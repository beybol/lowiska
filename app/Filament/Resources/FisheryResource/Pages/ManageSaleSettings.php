<?php

namespace App\Filament\Resources\FisheryResource\Pages;

use App\Enums\SaleMode;
use App\Filament\Resources\FisheryResource;
use App\Models\Fishery;
use App\Rules\PresaleDiscountIsPercentage;
use App\Rules\PresaleWindowsAreOrdered;
use App\Rules\SalePeriodsDoNotOverlap;
use App\Services\FisheryNavigation;
use App\Services\FishingDayCalendar;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Ekran „Sprzedaż i sezony": tryb sprzedaży, godziny doby i okresy sprzedaży
 * w jednym formularzu, z jednym „Zapisz".
 *
 * ⚠️ To jest ZAKŁADKA KONFIGURACYJNA, nie lista rekordów podrzędnych — dlatego
 * jest stroną ustawień, a nie RelationManagerem (ADR-006, aktualizacja z zadania
 * 015). Granica: lista rekordów mających własne strony → RelationManager;
 * konfiguracja łowiska zapisywana jednym przyciskiem → strona taka jak ta.
 *
 * ⚠️ Nie mylić z `ManageFishery` — tamta strona jest hubem i celowo NIE zawiera
 * formularza edycji łowiska (ADR-006). Ta jest formularzem i nie jest hubem.
 *
 * Okresy sprzedaży renderuje `Repeater`, bo `SalePeriod` świadomie nie ma własnego
 * zasobu: ma dwie daty i nazwę, więc trzy strony CRUD byłyby kosztem bez pokrycia.
 * Konsekwencja dla autoryzacji: model nie ma własnej polityki, dostępu pilnuje
 * `FisheryPolicy` przez `EditRecord::authorizeAccess()` — patrz
 * `docs/conventions/autoryzacja.md` §5.
 */
class ManageSaleSettings extends EditRecord
{
    protected static string $resource = FisheryResource::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    /**
     * Przełącznik przedsprzedaży jest POLEM FORMULARZA, nie kolumną — stan wynika
     * z wypełnienia obu dat okna (`panel-wlasciciela.md` §6). Kolumny
     * `presale_enabled` nie ma i mieć nie ma: dwie prawdy o tym samym rozjechałyby
     * się przy pierwszym zapisie z pominięciem formularza.
     */
    private const PRESALE_TOGGLE = 'presale_enabled';

    public static function getNavigationLabel(): string
    {
        return __('Sale and seasons');
    }

    public function getTitle(): string
    {
        return __('Sale and seasons').': '.$this->fishery()->name;
    }

    public function getBreadcrumb(): string
    {
        return __('Sale and seasons');
    }

    /**
     * @return array<int|string, string>
     */
    public function getBreadcrumbs(): array
    {
        return FisheryNavigation::fisheryBreadcrumbs($this->fishery()->id, __('Sale and seasons'));
    }

    /**
     * Zapis wraca do huba, tak samo jak strony zasobów podrzędnych — operator
     * kończy konfigurację tam, skąd ją zaczął (zadanie 012, ADR-006).
     */
    public function getRedirectUrl(): string
    {
        return FisheryResource::getUrl('manage', ['record' => $this->fishery()]);
    }

    public function form(Schema $schema): Schema
    {
        // ⚠️ `EditRecord::defaultForm()` narzuca `columns(2)`, o ile schemat sam nie
        // zadeklaruje kolumn. Bez tego obie sekcje stoją obok siebie, a repeater okresów
        // ze swoimi trzema kolumnami dostaje połowę szerokości i jest ściśnięty.
        return $schema->columns(1)->components([
            Section::make(__('How you sell time at a position'))
                ->description(__('Changing the hour applies from today and does not affect reservations already sold.'))
                ->schema([
                    Select::make('sale_mode')
                        ->label(__('Sale mode'))
                        ->options(SaleMode::options())
                        ->default(SaleMode::DailyPeriod->value)
                        ->selectablePlaceholder(false)
                        ->required(),
                    TimePicker::make('day_start_time')
                        ->label(__('The fishing day starts at'))
                        ->seconds(false)
                        ->required(),
                    TimePicker::make('day_end_time')
                        ->label(__('The fishing day ends at'))
                        ->helperText(__('On the following day — a fishing day always crosses midnight.'))
                        ->seconds(false)
                        ->required(),
                    Select::make('timezone')
                        ->label(__('Time zone'))
                        ->options(array_combine(
                            \DateTimeZone::listIdentifiers(),
                            \DateTimeZone::listIdentifiers(),
                        ))
                        ->searchable()
                        ->default('Europe/Warsaw')
                        ->required(),
                ])
                ->columns(2),
            Section::make(__('Sale periods'))
                ->description(__('Outside these periods the fishery is closed and nothing can be bought — even when a position is free.'))
                ->schema([
                    Repeater::make('salePeriods')
                        ->hiddenLabel()
                        ->relationship()
                        ->schema([
                            DatePicker::make('starts_on')
                                ->label(__('From'))
                                ->required(),
                            DatePicker::make('ends_on')
                                ->label(__('To'))
                                ->required(),
                            TextInput::make('name')
                                ->label(__('Own name'))
                                ->maxLength(255),
                            // ⚠️ Przedsprzedaż jest WŁAŚCIWOŚCIĄ OKRESU, nie osobną
                            // tabelą: zakres objętych dób to z definicji zakres okresu,
                            // więc nie da się skonfigurować okna obejmującego doby poza
                            // sezonem (zadanie 017, rozstrzygnięcie 2).
                            Toggle::make(self::PRESALE_TOGGLE)
                                ->label(__('Open a presale for this period'))
                                ->live()
                                ->columnSpanFull(),
                            DatePicker::make('presale_opens_on')
                                ->label(__('Presale opens on'))
                                ->visible(fn (Get $get): bool => (bool) $get(self::PRESALE_TOGGLE))
                                ->required(fn (Get $get): bool => (bool) $get(self::PRESALE_TOGGLE)),
                            DatePicker::make('presale_closes_on')
                                ->label(__('Presale closes on'))
                                ->visible(fn (Get $get): bool => (bool) $get(self::PRESALE_TOGGLE))
                                ->required(fn (Get $get): bool => (bool) $get(self::PRESALE_TOGGLE)),
                            TextInput::make('presale_min_nights')
                                ->label(__('Shortest stay bought in the presale (nights)'))
                                ->helperText(__('Applies to every purchase of this season made while the window is open.'))
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(365)
                                ->visible(fn (Get $get): bool => (bool) $get(self::PRESALE_TOGGLE)),
                            TextInput::make('presale_discount_percent')
                                // ⚠️ Piąte pole TEGO bloku, nie osobna sekcja i nie ekran
                                // „Cennik": wszystkie pięć opisuje tę samą ofertę tego samego
                                // sezonu. Rozdzielenie ich pozwalałoby ustawić obniżkę dla
                                // okresu, który przedsprzedaży w ogóle nie ma.
                                ->label(__('Price discount (%)'))
                                ->helperText(__('Taken off each night separately, from the rate together with its surcharges.'))
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(100)
                                ->visible(fn (Get $get): bool => (bool) $get(self::PRESALE_TOGGLE)),
                            Toggle::make('presale_whole_terms_bypass_min_nights')
                                ->label(__('Terms sold whole ignore that minimum'))
                                ->default(true)
                                ->visible(fn (Get $get): bool => (bool) $get(self::PRESALE_TOGGLE))
                                ->columnSpanFull(),
                        ])
                        ->columns(3)
                        ->reorderable(false)
                        ->addActionLabel(__('Add period'))
                        // Nagłówek wiersza mówi wprost, czy okres ma przedsprzedaż —
                        // inaczej trzeba rozwinąć każdy wiersz, żeby to sprawdzić.
                        ->itemLabel(fn (array $state): ?string => self::periodItemLabel($state))
                        // ⚠️ Stan przełącznika wynika z DANYCH, nie z kolumny
                        // (`panel-wlasciciela.md` §6). Repeater po relacji NIE przechodzi
                        // przez `mutateFormDataBefore*` strony — Filament zapisuje go
                        // w `saveRelationships()` z własnego stanu — więc klucz dokłada
                        // się i zdejmuje w hookach repeatera, nie w metodach strony.
                        ->mutateRelationshipDataBeforeFillUsing(
                            fn (array $data): array => self::withPresaleToggle($data),
                        )
                        ->mutateRelationshipDataBeforeCreateUsing(
                            fn (array $data): array => self::clearPresaleWhenDisabled($data),
                        )
                        ->mutateRelationshipDataBeforeSaveUsing(
                            fn (array $data): array => self::clearPresaleWhenDisabled($data),
                        )
                        // ⚠️ Reguły siedzą na całym repeaterze, nie na pojedynczym
                        // polu: nienachodzenie jest własnością ZBIORU okresów,
                        // więc walidacja pojedynczego wiersza nigdy by go nie
                        // zobaczyła.
                        ->rules([new SalePeriodsDoNotOverlap, new PresaleWindowsAreOrdered, new PresaleDiscountIsPercentage]),
                ]),
            Section::make(__('Sale horizon'))
                ->description(__('How far ahead anglers may buy. Leave empty for no horizon.'))
                ->schema([
                    TextInput::make('sale_horizon_days')
                        ->label(__('Sell at most this many days ahead'))
                        ->helperText(__('A night starting exactly that many days from today is still on sale.'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(3650),
                ]),
        ]);
    }

    /**
     * Ostrzeżenia o konfiguracji, którą operator prawdopodobnie zrobił przez pomyłkę
     * (G3). ⚠️ Wszystkie trzy są OSTRZEŻENIAMI, nie błędami: zapis przechodzi, bo
     * operator porządkuje sezon w dowolnej kolejności, a blokowanie tego kosztowałoby
     * więcej, niż daje (zadanie 017, reguła 11).
     */
    protected function afterSave(): void
    {
        $fishery = $this->fishery()->refresh();
        $warnedAboutMissingHorizon = false;

        foreach ($fishery->salePeriods as $period) {
            if (! $period->hasPresale()) {
                continue;
            }

            // Okno otwierające się PO starcie okresu to już nie jest „przed"-sprzedaż.
            if ($period->presale_opens_on->gt($period->starts_on)) {
                Notification::make()
                    ->warning()
                    ->title(__('A presale window opens after its period starts'))
                    ->body(__('The window of the period starting :start opens on :opens, so it is not a presale any more.', [
                        'start' => $period->starts_on->toDateString(),
                        'opens' => $period->presale_opens_on->toDateString(),
                    ]))
                    ->send();
            }

            // Bez horyzontu okno NICZEGO nie otwiera — doby okresu i tak są kupowalne —
            // a jedynie OGRANICZA sprzedaż przez `presale_min_nights` na czas swojego
            // trwania. To prawie na pewno pomyłka w konfiguracji. Ostrzeżenie leci raz,
            // nie raz na okres: przyczyna jest jedna i dotyczy łowiska.
            if ($fishery->sale_horizon_days === null && ! $warnedAboutMissingHorizon) {
                $warnedAboutMissingHorizon = true;

                Notification::make()
                    ->warning()
                    ->title(__('A presale without a sale horizon opens nothing'))
                    ->body(__('Without a horizon the nights of that period are already on sale, so the window only limits the sale while it lasts.'))
                    ->send();
            }
        }

        $this->warnAboutTermsLeftOutsideTheSeason($fishery);
    }

    /**
     * Skrócenie albo usunięcie okresu, które zostawia istniejące święto poza sezonem.
     *
     * Ostrzeżenie, nie błąd: blokowanie porządkowania sezonów kosztuje więcej, niż daje,
     * a w czasie działania ratuje to PRZYCINANIE pakietu do dób sprzedawalnych
     * (`dostepnosc.md` §4). Odwrotna strona — zapis święta poza sezonem — jest błędem,
     * i pilnuje jej `WholeTermPeriodsFitTheSeason`.
     */
    private function warnAboutTermsLeftOutsideTheSeason(Fishery $fishery): void
    {
        if (blank($fishery->day_start_time) || blank($fishery->day_end_time)) {
            return;
        }

        $calendar = new FishingDayCalendar($fishery);
        $stranded = [];

        foreach ($fishery->wholeTermPeriods as $term) {
            for (
                $date = CarbonImmutable::parse($term->first_day_on->toDateString());
                $date->toDateString() <= $term->last_day_on->toDateString();
                $date = $date->addDay()
            ) {
                if (! $calendar->isSellable($date->toDateString())) {
                    $stranded[] = $term->name ?: $term->first_day_on->toDateString();

                    break;
                }
            }
        }

        if ($stranded === []) {
            return;
        }

        Notification::make()
            ->warning()
            ->title(__('A term sold whole is left outside the season'))
            ->body(__('These terms no longer fit a sale period: :terms. Their nights outside the season drop out of the package.', [
                'terms' => implode(', ', array_unique($stranded)),
            ]))
            ->send();
    }

    /**
     * Nagłówek wiersza okresu: zakres plus stan przedsprzedaży.
     *
     * @param  array<string, mixed>  $state
     */
    private static function periodItemLabel(array $state): ?string
    {
        $startsOn = substr((string) ($state['starts_on'] ?? ''), 0, 10);
        $endsOn = substr((string) ($state['ends_on'] ?? ''), 0, 10);

        if ($startsOn === '' || $endsOn === '') {
            return null;
        }

        $opensOn = substr((string) ($state['presale_opens_on'] ?? ''), 0, 10);
        $closesOn = substr((string) ($state['presale_closes_on'] ?? ''), 0, 10);

        if ($opensOn === '' || $closesOn === '') {
            return $startsOn.' – '.$endsOn.' · '.__('no presale');
        }

        $presale = __('presale :from – :to', ['from' => $opensOn, 'to' => $closesOn]);
        $discount = $state['presale_discount_percent'] ?? null;

        if (filled($discount)) {
            $presale .= ' · −'.rtrim(rtrim(number_format((float) $discount, 2, '.', ''), '0'), '.').'%';
        }

        return $startsOn.' – '.$endsOn.' · '.$presale;
    }

    /**
     * Dokłada stan przełącznika przy wypełnianiu formularza — wynika z danych.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function withPresaleToggle(array $data): array
    {
        $data[self::PRESALE_TOGGLE] = filled($data['presale_opens_on'] ?? null)
            && filled($data['presale_closes_on'] ?? null);

        return $data;
    }

    /**
     * Wyłączenie przełącznika CZYŚCI cztery kolumny przedsprzedaży.
     *
     * ⚠️ Bez tego okno zostawało w bazie po wyłączeniu i dalej ograniczało sprzedaż
     * przez `presale_min_nights`, a formularz pokazywał przedsprzedaż jako wyłączoną.
     * Klucz przełącznika wypada dopiero PO wyliczeniu z niego wartości — nie zdejmuj go
     * `->dehydrated(false)` (`panel-wlasciciela.md` §6).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function clearPresaleWhenDisabled(array $data): array
    {
        if (! ($data[self::PRESALE_TOGGLE] ?? false)) {
            $data['presale_opens_on'] = null;
            $data['presale_closes_on'] = null;
            $data['presale_min_nights'] = null;
            $data['presale_whole_terms_bypass_min_nights'] = true;
            $data['presale_discount_percent'] = null;
        }

        unset($data[self::PRESALE_TOGGLE]);

        return $data;
    }

    /**
     * `getRecord()` deklaruje `Model`, więc bez tego zawężenia każde sięgnięcie po
     * pole łowiska jest dla analizy statycznej dostępem do nieznanej właściwości
     * (ten sam zabieg co w `ManageFishery`, zadanie 012).
     */
    private function fishery(): Fishery
    {
        $record = $this->getRecord();
        assert($record instanceof Fishery);

        return $record;
    }
}
