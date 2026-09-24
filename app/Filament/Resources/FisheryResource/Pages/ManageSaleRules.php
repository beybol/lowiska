<?php

namespace App\Filament\Resources\FisheryResource\Pages;

use App\Filament\Resources\FisheryResource;
use App\Models\Fishery;
use App\Rules\StayLengthRangeIsOrdered;
use App\Rules\WeekendDaysAreContiguous;
use App\Rules\WholeTermPeriodsFitTheSeason;
use App\Services\FisheryNavigation;
use App\Services\FishingDay;
use App\Services\FishingDayCalendar;
use App\Services\SharedFormComponents;
use App\Services\WeekdayNights;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Ekran „Reguły sprzedaży": JAKI POBYT wolno kupić.
 *
 * ⚠️ Osobna pozycja sub-nawigacji, a nie kolejna sekcja na „Sprzedaż i sezony" — jedno
 * pytanie na ekran. Tamta strona odpowiada KIEDY sprzedajesz (doba, okresy, horyzont),
 * ta — JAKI POBYT wolno kupić (długość, weekend i święta sprzedawane w całości).
 * Uzasadnienie: zadanie 017, rozstrzygnięcie 1.
 *
 * ⚠️ To jest STRONA ZASOBU `FisheryResource`, nie strona panelu (`panel-wlasciciela.md`
 * §6). Konkretny skutek: zasób jest zarejestrowany w obu panelach, więc strona trafia
 * do obu bez dotykania `OwnerPanelProvider` ani `AdminPanelProvider` i bez dopisywania
 * uprawnień do listy w `tests/TestCase.php`.
 *
 * ⚠️ Święta renderuje `Repeater` po relacji, bo `WholeTermPeriod` świadomie nie ma
 * własnego zasobu ani polityki — dostępu pilnuje `FisheryPolicy` przez
 * `EditRecord::authorizeAccess()` (`autoryzacja.md` §5).
 *
 * ⚠️ Reguły pobytu NIE są tu liczone. Odpowiada na nie `StaySellability` (ADR-013);
 * tutaj są wyłącznie dane wejściowe i ostrzeżenia o konfiguracji, której nie da się
 * kupić (G3).
 */
class ManageSaleRules extends EditRecord
{
    protected static string $resource = FisheryResource::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    /**
     * Przełącznik weekendu jest POLEM FORMULARZA, nie kolumną — stan wynika
     * z wypełnienia `weekend_days` (`panel-wlasciciela.md` §6). Klucz zdejmuje
     * `mutateFormDataBeforeSave()`; nie zdejmuj go `->dehydrated(false)`.
     */
    private const WEEKEND_TOGGLE = 'weekend_whole';

    public static function getNavigationLabel(): string
    {
        return __('Sale rules');
    }

    public function getTitle(): string
    {
        return __('Sale rules').': '.$this->fishery()->name;
    }

    public function getBreadcrumb(): string
    {
        return __('Sale rules');
    }

    /**
     * @return array<int|string, string>
     */
    public function getBreadcrumbs(): array
    {
        return FisheryNavigation::fisheryBreadcrumbs($this->fishery()->id, __('Sale rules'));
    }

    public function getRedirectUrl(): string
    {
        return FisheryResource::getUrl('manage', ['record' => $this->fishery()]);
    }

    public function form(Schema $schema): Schema
    {
        // ⚠️ `EditRecord::defaultForm()` narzuca `columns(2)`, o ile schemat sam nie
        // zadeklaruje kolumn — bez tego sekcje stoją obok siebie, a repeater świąt
        // dostaje połowę szerokości (`panel-wlasciciela.md` §6).
        return $schema->columns(1)->components([
            Section::make(__('Stay length'))
                ->description(__('Leave a field empty for no limit. A fishery without limits sells a stay of any length.'))
                ->schema([
                    TextInput::make('min_nights')
                        ->label(__('Shortest stay (nights)'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(365),
                    TextInput::make('max_nights')
                        ->label(__('Longest stay (nights)'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(365)
                        ->rules([
                            fn (Get $get): StayLengthRangeIsOrdered => new StayLengthRangeIsOrdered($get('min_nights')),
                        ]),
                ])
                ->columns(2),
            Section::make(__('Weekend sold whole'))
                ->description(__('Mark the nights that go together. A stay touching the weekend has to cover all of them.'))
                ->schema([
                    Toggle::make(self::WEEKEND_TOGGLE)
                        ->label(__('The weekend is sold whole'))
                        ->helperText($this->hasFishingDay()
                            ? null
                            : __('Set the fishing day hours in Sale and seasons first.'))
                        ->disabled(! $this->hasFishingDay())
                        ->live(),
                    // ⚠️ Doby, NIE dni. Chip pokazuje przedział liczony z godzin doby łowiska,
                    // żeby operator nie zaznaczał „trzech dni" tam, gdzie weekend to dwie doby
                    // (zadanie 017, rozstrzygnięcie 6). Ten sam komponent buduje warunek dopłaty
                    // w „Cenniku" — wiedza o dobach ma jeden dom (`WeekdayNights`, zadanie 023).
                    SharedFormComponents::weekdayNightsInput(
                        'weekend_days',
                        WeekdayNights::forFishery($this->fishery()),
                        __('Select the nights that make up the weekend'),
                        __('No weekend nights chosen yet.'),
                    )
                        ->visible(fn (Get $get): bool => (bool) $get(self::WEEKEND_TOGGLE))
                        ->rules([new WeekendDaysAreContiguous]),
                ]),
            Section::make(__('Terms sold whole'))
                // ⚠️ Bez godzin doby sekcja jest WYŁĄCZONA, tak samo jak przełącznik
                // weekendu: nie ma z czego policzyć podpowiedzi „czyli pobyt", a reguła
                // mieszczenia się w sezonie zwracałaby mylący powód (reguła 11 zadania).
                ->description($this->hasFishingDay()
                    ? __('Dated terms — May Day, Christmas. A stay touching a term has to cover all of its nights.')
                    : __('Set the fishing day hours in Sale and seasons first.'))
                ->schema($this->hasFishingDay() ? [
                    Repeater::make('wholeTermPeriods')
                        ->hiddenLabel()
                        ->relationship()
                        ->schema([
                            TextInput::make('name')
                                ->label(__('Own name'))
                                ->maxLength(255),
                            DatePicker::make('first_day_on')
                                ->label(__('First night'))
                                ->required()
                                ->live(),
                            DatePicker::make('last_day_on')
                                // ⚠️ „Ostatnia DOBA", nie „do" — `last_day_on` wskazuje
                                // dobę objętą, a nie granicę okna jak `ends_on` okresu
                                // sprzedaży. Etykieta jest tu częścią zabezpieczenia.
                                ->label(__('Last night'))
                                ->required()
                                ->live(),
                            Text::make(fn (Get $get): string => $this->stayPreview(
                                $get('first_day_on'),
                                $get('last_day_on'),
                            ))
                                ->columnSpanFull(),
                        ])
                        ->columns(3)
                        ->reorderable(false)
                        ->defaultItems(0)
                        ->addActionLabel(__('Add term'))
                        // ⚠️ Reguła siedzi na CAŁYM repeaterze, jak `SalePeriodsDoNotOverlap`:
                        // walidacja pojedynczego wiersza nie zobaczyłaby pozostałych,
                        // a komunikat o braku okresu sprzedaży dotyczy łowiska, nie wiersza.
                        ->rules([new WholeTermPeriodsFitTheSeason($this->fishery())]),
                ] : []),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Stan przełącznika wynika z DANYCH, nie z osobnej kolumny.
        $data[self::WEEKEND_TOGGLE] = filled($data['weekend_days'] ?? null);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Wyłączenie przełącznika CZYŚCI dane — inaczej zbiór zostawałby w kolumnie
        // i wracał przy następnym włączeniu jako cicha reguła.
        if (! ($data[self::WEEKEND_TOGGLE] ?? false)) {
            $data['weekend_days'] = null;
        } elseif (is_array($data['weekend_days'] ?? null)) {
            // Kolumna JSON oddaje to, co w niej zapisano, a chipy zapisują łańcuchy —
            // bez tego zbiór wracał jako `["5","6"]`.
            $data['weekend_days'] = array_values(array_map(
                static fn (mixed $day): int => (int) $day,
                $data['weekend_days'],
            ));
        }

        // Przełącznik nie jest kolumną, więc klucz musi wypaść PO wyliczeniu z niego
        // wartości (`panel-wlasciciela.md` §6).
        unset($data[self::WEEKEND_TOGGLE]);

        return $data;
    }

    /**
     * Ostrzeżenia o konfiguracji, której nie da się kupić (G3).
     *
     * Zapis przechodzi — operator może być w trakcie porządkowania sezonu i ustawiać
     * te rzeczy w dowolnej kolejności. Twarde odrzucenie dawałoby błąd w miejscu,
     * którego operator w tej chwili nie edytuje.
     */
    protected function afterSave(): void
    {
        $fishery = $this->fishery()->refresh();
        $minNights = $fishery->min_nights;
        $maxNights = $fishery->max_nights;
        $weekendNights = count(is_array($fishery->weekend_days) ? $fishery->weekend_days : []);

        if ($minNights !== null && $weekendNights > 0 && $minNights > $weekendNights) {
            Notification::make()
                ->warning()
                ->title(__('The shortest stay is longer than the weekend'))
                ->body(__('A weekend of :weekend nights can not be bought while the shortest stay is :min nights.', [
                    'weekend' => $weekendNights,
                    'min' => $minNights,
                ]))
                ->send();
        }

        if ($maxNights === null) {
            return;
        }

        $longestBundle = $weekendNights;

        foreach ($fishery->wholeTermPeriods as $term) {
            $longestBundle = max(
                $longestBundle,
                (int) $term->first_day_on->diffInDays($term->last_day_on) + 1,
            );
        }

        if ($longestBundle > $maxNights) {
            Notification::make()
                ->warning()
                ->title(__('The longest stay is shorter than a package sold whole'))
                ->body(__('A package of :bundle nights can not be bought while the longest stay is :max nights.', [
                    'bundle' => $longestBundle,
                    'max' => $maxNights,
                ]))
                ->send();
        }
    }

    /**
     * „Czyli pobyt": czw 30.04 15:00 → nd 3.05 15:00 · 3 doby.
     *
     * ⚠️ Liczone przez `FishingDayCalendar`, nie różnicą dat — inaczej powstałby drugi
     * kod liczący doby (`dostepnosc.md` §1), a właśnie tutaj ma być widać, że
     * `last_day_on` wskazuje dobę OBJĘTĄ, a nie dzień wyjazdu.
     */
    private function stayPreview(mixed $firstDayOn, mixed $lastDayOn): string
    {
        if (blank($firstDayOn) || blank($lastDayOn) || ! $this->hasFishingDay()) {
            return '';
        }

        $calendar = new FishingDayCalendar($this->fishery());
        $firstNight = $calendar->dayStartingOn(substr((string) $firstDayOn, 0, 10));
        $lastNight = $calendar->dayStartingOn(substr((string) $lastDayOn, 0, 10));

        if (! $firstNight instanceof FishingDay || ! $lastNight instanceof FishingDay) {
            return '';
        }

        $nights = (int) $firstNight->startsOn->diffInDays($lastNight->startsOn) + 1;

        if ($nights < 1) {
            return '';
        }

        return __('That is a stay from :from to :to', [
            'from' => $firstNight->startsAt->isoFormat('ddd D.MM HH:mm'),
            'to' => $lastNight->endsAt->isoFormat('ddd D.MM HH:mm'),
        ]).' · '.trans_choice(':count night|:count nights', $nights, ['count' => $nights]);
    }

    /**
     * Bez godzin doby nie ma z czego policzyć ani przedziałów dób, ani podpowiedzi
     * „czyli pobyt", a walidacja mieszczenia się w sezonie zwracałaby mylący powód.
     */
    private function hasFishingDay(): bool
    {
        $fishery = $this->fishery();

        return filled($fishery->day_start_time) && filled($fishery->day_end_time);
    }

    /**
     * `getRecord()` deklaruje `Model`, więc bez tego zawężenia każde sięgnięcie po pole
     * łowiska jest dla analizy statycznej dostępem do nieznanej właściwości (ten sam
     * zabieg co w `ManageFishery` i `ManageSaleSettings`).
     */
    private function fishery(): Fishery
    {
        $record = $this->getRecord();
        assert($record instanceof Fishery);

        return $record;
    }
}
