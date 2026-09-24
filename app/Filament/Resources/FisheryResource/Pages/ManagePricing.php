<?php

namespace App\Filament\Resources\FisheryResource\Pages;

use App\Enums\PriceRuleKind;
use App\Enums\SurchargeAudience;
use App\Filament\Resources\FisheryResource;
use App\Models\Fishery;
use App\Models\PriceRule;
use App\Rules\PriceRuleDatesAreOrdered;
use App\Services\FisheryNavigation;
use App\Services\PriceRulePeriods;
use App\Services\PricingConfigurationAudit;
use App\Services\SharedFormComponents;
use App\Services\WeekdayNights;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Ekran „Cennik": ile kosztuje doba na stanowisku.
 *
 * ⚠️ Cennik jest **listą reguł**, nie tabelą stawek po wymiarach (ADR-014). Dwie listy —
 * stawki i dopłaty — bo operator myśli o nich osobno, a po przedefiniowaniu z 22.09.2026
 * różnią się także KSZTAŁTEM, nie tylko flagą `kind`:
 *
 * - **stawka** ma sześć pól i żadnego warunku poza datami (nazwa jest opisem, nie warunkiem);
 * - **dopłata** ma osiem pól i niesie cały ciężar warunkowy.
 *
 * Kto chce różnicować cenę dniami tygodnia, robi to dopłatą. Zniknęły priorytet, rola
 * uczestnika, drugi zakres dat i błąd remisu — nachodzenie rozstrzyga się na korzyść wędkarza.
 *
 * ⚠️ To jest STRONA ZASOBU `FisheryResource`, nie strona panelu (`panel-wlasciciela.md` §6),
 * więc trafia do obu paneli bez dotykania providerów i bez dopisywania uprawnień do
 * `tests/TestCase.php`.
 *
 * ⚠️ **Ekran nie liczy ceny.** Odpowiada na nią `StayPricing`, a na „czy w ofercie" —
 * `StayOffer` (ADR-015). Tutaj są dane wejściowe, jeden błąd zapisu i dwa powiadomienia.
 */
class ManagePricing extends EditRecord
{
    protected static string $resource = FisheryResource::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    /**
     * Identyfikatory reguł istniejących PRZED zapisem — z nich wynika, które powstały teraz.
     *
     * ⚠️ Domykanie okresów działa wyłącznie przy utworzeniu, więc musi odróżnić nowy wpis od
     * edytowanego. Filament zapisuje repeatery relacyjne hurtem i nie mówi, co dołożył.
     *
     * @var array<int, int>
     */
    protected array $ruleIdsBeforeSave = [];

    public static function getNavigationLabel(): string
    {
        return __('Pricing');
    }

    public function getTitle(): string
    {
        return __('Pricing').': '.$this->fishery()->name;
    }

    public function getBreadcrumb(): string
    {
        return __('Pricing');
    }

    /**
     * @return array<int|string, string>
     */
    public function getBreadcrumbs(): array
    {
        return FisheryNavigation::fisheryBreadcrumbs($this->fishery()->id, __('Pricing'));
    }

    public function getRedirectUrl(): string
    {
        return FisheryResource::getUrl('manage', ['record' => $this->fishery()]);
    }

    public function form(Schema $schema): Schema
    {
        // ⚠️ `EditRecord::defaultForm()` narzuca `columns(2)` — bez tego obie listy stoją
        // obok siebie i wiersz dopłaty z ośmioma polami jest nieczytelny
        // (`panel-wlasciciela.md` §6).
        return $schema->columns(1)->components([
            Section::make(__('Rates'))
                ->description(__('A rate replaces the price of a night. Rates have no conditions other than dates — to charge more on some days of the week, add a surcharge instead.'))
                ->schema([
                    Repeater::make('rateRules')
                        ->hiddenLabel()
                        ->relationship()
                        ->schema($this->rateFields())
                        ->columns(3)
                        ->reorderable(false)
                        ->defaultItems(0)
                        ->addActionLabel(__('Add rate'))
                        ->itemLabel(fn (array $state): ?string => $this->rateItemLabel($state))
                        // ⚠️ Rodzaj ustawia hook, bo relacja zawężona po `kind` NIE wypełnia go
                        // przy tworzeniu — bez tego nowy wiersz zapisałby się bez rodzaju
                        // i zniknąłby z obu list.
                        ->mutateRelationshipDataBeforeCreateUsing(
                            fn (array $data): array => self::rateData($data),
                        )
                        ->mutateRelationshipDataBeforeSaveUsing(
                            fn (array $data): array => self::rateData($data),
                        )
                        ->rules([new PriceRuleDatesAreOrdered]),
                ]),
            Section::make(__('Surcharges'))
                ->description(__('A surcharge adds to the rate. All matching surcharges add up, so there is nothing to resolve between them. This is also how you charge more on chosen days of the week.'))
                ->schema([
                    Repeater::make('surchargeRules')
                        ->hiddenLabel()
                        ->relationship()
                        ->schema($this->surchargeFields())
                        ->columns(3)
                        ->reorderable(false)
                        ->defaultItems(0)
                        ->addActionLabel(__('Add surcharge'))
                        ->itemLabel(fn (array $state): ?string => $this->surchargeItemLabel($state))
                        ->mutateRelationshipDataBeforeCreateUsing(
                            fn (array $data): array => self::surchargeData($data),
                        )
                        ->mutateRelationshipDataBeforeSaveUsing(
                            fn (array $data): array => self::surchargeData($data),
                        )
                        ->rules([new PriceRuleDatesAreOrdered]),
                ]),
        ]);
    }

    /**
     * Sześć pól stawki — kwoty, opcjonalna nazwa i daty, nic więcej.
     *
     * ⚠️ **Nazwa nie jest warunkiem.** Mówi wyłącznie, jak stawka nazywa się w powiadomieniu
     * o domknięciu, w rozbiciu wyceny, w nagłówku wiersza i w kalendarzu. Pusta zachowuje
     * dawne zachowanie: rozbicie niesie `null`, a powiadomienie pokazuje kwotę (zadanie 023).
     *
     * @return array<int, mixed>
     */
    private function rateFields(): array
    {
        return [
            SharedFormComponents::getPriceInput('amount', __('Amount per angler per night'), 0.00),
            // ⚠️ Minimum 0,00, nie 0,01: osoba towarzysząca jest u obu znanych łowisk darmowa.
            // ⚠️ WYMAGANE, bo puste pole znaczy BRAK CENY, czyli odmowę sprzedaży komuś,
            // kto przyjechał z osobą towarzyszącą — a operator prawie nigdy tego nie chce.
            SharedFormComponents::getPriceInput('amount_companion', __('Amount per companion per night'), 0.00)
                ->required()
                ->default(0)
                ->helperText(__('Enter 0.00 if companions stay for free. Leaving this empty means the night can not be sold to anyone bringing a companion.')),
            TextInput::make('label')
                ->label(__('Rate name'))
                ->helperText(__('Optional, e.g. "Price list 2026". Names this rate in the price breakdown, the calendar and notifications.'))
                ->maxLength(255),
            Toggle::make('is_suspended')
                ->label(__('Suspended'))
                ->helperText(__('Stays in the price list and takes no part in pricing.')),
            DatePicker::make('first_day_on')
                ->label(__('Effective from'))
                ->helperText(__('The first night this rate prices. Empty means no lower bound.')),
            DatePicker::make('last_day_on')
                ->label(__('Effective to'))
                ->helperText(__('Leave empty for the current price list — a new open-ended rate then closes this one automatically.')),
        ];
    }

    /**
     * Osiem pól dopłaty — cały ciężar warunkowy cennika.
     *
     * ⚠️ **Każde pole warunku ma jednozdaniowe wyjaśnienie.** Zgłoszenie, które doprowadziło do
     * przedefiniowania, dotyczyło nieczytelności warunków; samo usunięcie dwóch osi go nie zamyka.
     *
     * @return array<int, mixed>
     */
    private function surchargeFields(): array
    {
        return [
            SharedFormComponents::getPriceInput('amount', __('Amount per person per night'), 0.00),
            TextInput::make('label')
                ->label(__('Label shown to the angler'))
                ->helperText(__('Shown next to every night it applies to.'))
                ->maxLength(255),
            // ⚠️ Domyślnie „dla łowiącego", NIE „dla każdego": osoba towarzysząca bywa darmowa,
            // więc domyślne obciążanie jej byłoby pomyłką najtrudniejszą do zauważenia.
            Select::make('applies_to')
                ->label(__('Charged to'))
                ->options(SurchargeAudience::options())
                ->default(SurchargeAudience::Angler->value)
                ->selectablePlaceholder(false)
                ->required()
                ->helperText(__('"For everyone" charges companions too — pick it for things everyone uses, such as power or parking.')),
            DatePicker::make('first_day_on')
                ->label(__('Effective from'))
                ->helperText(__('The first night this surcharge applies to. Empty means no lower bound.')),
            DatePicker::make('last_day_on')
                ->label(__('Effective to'))
                ->helperText(__('Empty means it applies indefinitely.')),
            Toggle::make('is_suspended')
                ->label(__('Suspended'))
                ->helperText(__('Stays in the price list and takes no part in pricing.')),
            Select::make('anglers_count')
                ->label(__('Only with exactly this many anglers'))
                ->options($this->anglerCountOptions())
                ->helperText(__('Empty means any number. Only anglers count — a companion does not raise it.')),
            // ⚠️ Ten sam komponent co weekend na „Regułach sprzedaży" (zadanie 023). Bez godzin
            // doby pole NIE jest wyłączane: dopłata wybiera doby po dniu rozpoczęcia, więc
            // znika tylko linia godzin na chipach.
            SharedFormComponents::weekdayNightsInput(
                'weekdays',
                $this->weekdayNights(),
                __('Only on these nights'),
                __('Every night.'),
                __('Nights are identified by the day they start on. Selecting none means every night.'),
            ),
        ];
    }

    /**
     * Zapamiętuje stan sprzed zapisu, żeby dało się odróżnić nowe reguły od edytowanych.
     *
     * ⚠️ **`beforeValidate`, a NIE `beforeSave`** — i to nie jest wybór stylistyczny. Filament
     * zapisuje repeatery relacyjne wewnątrz `$this->form->getState()`, a hook `beforeSave`
     * odpala się dopiero w jego wywołaniu zwrotnym `afterValidate`, czyli **już po** wstawieniu
     * nowych wierszy. Migawka zrobiona tam zawierałaby je wszystkie i domykanie nigdy by się
     * nie uruchomiło — cicho, bez błędu.
     */
    protected function beforeValidate(): void
    {
        $this->ruleIdsBeforeSave = $this->fishery()->priceRules()->pluck('id')->map(
            static fn (mixed $id): int => (int) $id,
        )->all();
    }

    /**
     * Domknięcie wypartych stawek plus ostrzeżenie o dziurze w cenniku (G3).
     *
     * ⚠️ **Ostrzeżenie o dziurze jest ostrzeżeniem, nie błędem**, i to jest świadome: dziura
     * może być etapem porządkowania sezonu.
     *
     * ⚠️ **Nie ostrzegamy o stawce, która przegrywa z tańszą.** Zapis „90 zł w lipcu" przy
     * bezterminowych 70 zł jest legalny i nic nie zrobi — pokazuje to kalendarz podglądowy
     * (019), a nie formularz, żeby wiedza o nachodzeniu miała jeden dom.
     */
    protected function afterSave(): void
    {
        $fishery = $this->fishery()->refresh();

        $created = $fishery->priceRules()
            ->whereNotIn('id', $this->ruleIdsBeforeSave)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        foreach ((new PriceRulePeriods($fishery))->closeSupersededRates($created) as $closed) {
            Notification::make()
                ->success()
                ->title(__('A previous rate was closed'))
                ->body(__('Rate ":label" now ends on :until, because a newer rate takes over from the next day.', [
                    'label' => $closed['label'],
                    'until' => $closed['until'],
                ]))
                ->send();
        }

        $gap = (new PricingConfigurationAudit($fishery->refresh()))->firstPricingGap();

        if ($gap instanceof CarbonImmutable) {
            Notification::make()
                ->warning()
                ->title(__('Some nights have no price — anglers can not buy them'))
                ->body($this->pricingGapBody($gap))
                ->send();
        }
    }

    private function weekdayNights(): WeekdayNights
    {
        return WeekdayNights::forFishery($this->fishery());
    }

    /**
     * Obsady możliwe na tym łowisku.
     *
     * ⚠️ Porównywana jest **faktyczna obsada z zapytania**, a nie pojemność stanowiska — lista
     * opcji bierze się z pojemności tylko dlatego, że większej obsady i tak nie da się zgłosić.
     *
     * @return array<int, string>
     */
    private function anglerCountOptions(): array
    {
        $largest = (new PricingConfigurationAudit($this->fishery()))->largestAnglerCapacity();
        $options = [];

        foreach (range(1, $largest) as $count) {
            $options[$count] = (string) $count;
        }

        return $options;
    }

    /**
     * Nagłówek wiersza stawki: `[Cennik 2026 · ]70,00 · od 2026-01-01` albo `· okno` przy datowanej.
     *
     * @param  array<string, mixed>  $state
     */
    private function rateItemLabel(array $state): ?string
    {
        $amount = $state['amount'] ?? null;

        if (blank($amount)) {
            return null;
        }

        $from = $state['first_day_on'] ?? null;
        $to = $state['last_day_on'] ?? null;

        $parts = [];

        if (filled($state['label'] ?? null)) {
            $parts[] = (string) $state['label'];
        }

        $parts[] = (string) $amount;

        $period = PriceRule::periodText(
            filled($from) ? (string) $from : null,
            filled($to) ? (string) $to : null,
        );

        if ($period !== null) {
            $parts[] = $period;
        }

        if (filled($from) && filled($to)) {
            $parts[] = __('window');
        }

        return implode(' · ', $parts).$this->suspendedSuffix($state);
    }

    /**
     * Nagłówek wiersza dopłaty: `+20,00 zł · dla łowiącego · obsada 1 · czw–nd`.
     *
     * @param  array<string, mixed>  $state
     */
    private function surchargeItemLabel(array $state): ?string
    {
        $amount = $state['amount'] ?? null;

        if (blank($amount)) {
            return null;
        }

        $parts = ['+'.$amount];

        if (filled($state['label'] ?? null)) {
            $parts[] = (string) $state['label'];
        }

        $audience = SurchargeAudience::tryFrom((string) ($state['applies_to'] ?? ''));
        $parts[] = mb_strtolower(($audience ?? SurchargeAudience::Angler)->label());

        if (filled($state['anglers_count'] ?? null)) {
            $parts[] = __('anglers: :count', ['count' => $state['anglers_count']]);
        }

        // ⚠️ Krótka forma z domu dób, nie „pierwszy–ostatni" — tamto przekłamywało zbiór
        // nieciągły („pn + śr" wyglądało jak „pn–śr").
        $weekdays = $this->weekdayNights()->shortForm($state['weekdays'] ?? []);

        if ($weekdays !== '') {
            $parts[] = $weekdays;
        }

        return implode(' · ', $parts).$this->suspendedSuffix($state);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function suspendedSuffix(array $state): string
    {
        return ($state['is_suspended'] ?? false) ? ' · '.__('suspended') : '';
    }

    /**
     * Treść ostrzeżenia o dziurze w cenniku.
     *
     * ⚠️ Komunikat ma **nazwać dzień tygodnia**, a nie samą datę — operator myśli o cenniku
     * kalendarzowo (zgłoszenie z 2026-09-22).
     *
     * ⚠️ **Nie wymienia już obsady ani roli**: stawka od nich nie zależy, więc byłyby to dane
     * mylące, a nie doprecyzowujące. Nie wymienia też dni tygodnia jako możliwej przyczyny —
     * stawka ich nie zna, więc dziura może mieć już tylko przyczynę datową.
     */
    private function pricingGapBody(CarbonImmutable $night): string
    {
        return __('The first one is :night. None of your rates covers it — check the dates on your rates.', [
            'night' => $night->locale(app()->getLocale())->isoFormat('dddd, D.MM.YYYY'),
        ]).' '.__('Checked against the price list as it stands today.');
    }

    /**
     * Wiersz stawki gotowy do zapisu.
     *
     * ⚠️ **Pola dopłaty jadą na `null`, a nie zostają puste.** Kolumny są wspólne, bo rodzaj
     * reguły jest flagą — ale zostawienie w nich czegokolwiek znaczyłoby trzymanie w bazie
     * danych, których nikt nie interpretuje.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function rateData(array $data): array
    {
        return self::withoutBlankValues($data) + [
            'kind' => PriceRuleKind::Rate->value,
            'weekdays' => null,
            'anglers_count' => null,
            'applies_to' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function surchargeData(array $data): array
    {
        return self::withoutBlankValues($data) + [
            'kind' => PriceRuleKind::Surcharge->value,
            'amount_companion' => null,
        ];
    }

    /**
     * Puste wartości z formularza sprowadzone do `null`.
     *
     * ⚠️ **Pusty `Select` przysyła PUSTY ŁAŃCUCH, nie `null`** — i to jest stan normalny, bo
     * dopłata bez warunku obsady nic tam nie wybiera. Bez tej normalizacji rzutowanie enuma
     * na `''` rzuca `ValueError` i wywraca **cały zapis** formularza (zgłoszenie z 2026-09-22).
     *
     * ⚠️ Metoda przyjechała tu z `PriceRulesDoNotTie`, usuniętej razem z remisami. Gdyby ktoś
     * szukał jej tam — nie ma, i nie ma dokąd wracać: walidacja remisu przestała istnieć,
     * a normalizacja musiała przeżyć.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function withoutBlankValues(array $row): array
    {
        foreach (['weekdays', 'first_day_on', 'last_day_on', 'anglers_count', 'applies_to', 'label', 'amount_companion'] as $key) {
            if (array_key_exists($key, $row) && blank($row[$key])) {
                $row[$key] = null;
            }
        }

        return $row;
    }

    /**
     * `getRecord()` deklaruje `Model`, więc bez tego zawężenia każde sięgnięcie po pole łowiska
     * jest dla analizy statycznej dostępem do nieznanej właściwości.
     */
    private function fishery(): Fishery
    {
        $record = $this->getRecord();
        assert($record instanceof Fishery);

        return $record;
    }
}
