<?php

namespace App\Filament\Resources\FisheryResource\Pages;

use App\Enums\ParticipantRole;
use App\Enums\PriceRuleKind;
use App\Filament\Resources\FisheryResource;
use App\Models\Fishery;
use App\Rules\PriceRuleDatesAreOrdered;
use App\Rules\PriceRulesDoNotTie;
use App\Services\FisheryNavigation;
use App\Services\PricingConfigurationAudit;
use App\Services\SharedFormComponents;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Ekran „Cennik": ile kosztuje doba na stanowisku.
 *
 * ⚠️ Cennik jest **listą reguł z warunkami**, nie tabelą stawek po wymiarach (ADR-014).
 * Dwie listy — stawki i dopłaty — bo operator myśli o nich osobno, choć mechanika wpisu jest
 * ta sama i różni je wyłącznie `kind`: stawka ZASTĘPUJE, dopłata DODAJE się.
 *
 * ⚠️ To jest STRONA ZASOBU `FisheryResource`, nie strona panelu (`panel-wlasciciela.md` §6),
 * więc trafia do obu paneli bez dotykania providerów i bez dopisywania uprawnień do
 * `tests/TestCase.php`.
 *
 * ⚠️ **Ekran nie liczy ceny.** Odpowiada na nią `StayPricing`, a na „czy w ofercie" —
 * `StayOffer` (ADR-015). Tutaj są dane wejściowe, jeden błąd zapisu (remis) i dwa ostrzeżenia.
 */
class ManagePricing extends EditRecord
{
    protected static string $resource = FisheryResource::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

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
        // obok siebie i wiersz reguły z sześcioma polami warunku jest nieczytelny
        // (`panel-wlasciciela.md` §6).
        return $schema->columns(1)->components([
            Section::make(__('Rates'))
                ->description(__('A rate replaces the price. For one night and one role exactly one rate wins — by priority, then by how many conditions it carries.'))
                ->schema([
                    Repeater::make('rateRules')
                        ->hiddenLabel()
                        ->relationship()
                        ->schema($this->ruleFields())
                        ->columns(3)
                        ->reorderable(false)
                        ->defaultItems(0)
                        ->addActionLabel(__('Add rate'))
                        ->itemLabel(fn (array $state): ?string => $this->ruleItemLabel($state))
                        // ⚠️ Relacja jest zawężona po `kind`, a Eloquent NIE wypełnia wartości
                        // z `where()` przy tworzeniu przez relację — bez tego nowy wiersz
                        // zapisałby się bez rodzaju i zniknąłby z obu list.
                        // ⚠️ Rodzaj ustawia hook, bo relacja zawężona po `kind` NIE wypełnia go
                        // przy tworzeniu. Puste warunki sprowadzamy tu do `null` — pusty `Select`
                        // przysyła pusty łańcuch, na którym rzut enuma wywracał zapis.
                        ->mutateRelationshipDataBeforeCreateUsing(
                            fn (array $data): array => self::ruleData($data, PriceRuleKind::Rate),
                        )
                        ->mutateRelationshipDataBeforeSaveUsing(
                            fn (array $data): array => self::ruleData($data, PriceRuleKind::Rate),
                        )
                        // ⚠️ Reguły siedzą na CAŁYM repeaterze: remis jest własnością ZBIORU,
                        // więc walidacja pojedynczego wiersza nigdy by go nie zobaczyła.
                        ->rules([new PriceRulesDoNotTie, new PriceRuleDatesAreOrdered]),
                ]),
            Section::make(__('Surcharges'))
                ->description(__('A surcharge adds to the rate. All matching surcharges add up, so there is nothing to resolve between them.'))
                ->schema([
                    Repeater::make('surchargeRules')
                        ->hiddenLabel()
                        ->relationship()
                        ->schema($this->ruleFields())
                        ->columns(3)
                        ->reorderable(false)
                        ->defaultItems(0)
                        ->addActionLabel(__('Add surcharge'))
                        ->itemLabel(fn (array $state): ?string => $this->ruleItemLabel($state))
                        ->mutateRelationshipDataBeforeCreateUsing(
                            fn (array $data): array => self::ruleData($data, PriceRuleKind::Surcharge),
                        )
                        ->mutateRelationshipDataBeforeSaveUsing(
                            fn (array $data): array => self::ruleData($data, PriceRuleKind::Surcharge),
                        )
                        ->rules([new PriceRuleDatesAreOrdered]),
                ]),
        ]);
    }

    /**
     * Pola jednego wiersza — wspólne dla stawek i dopłat, bo mechanika wpisu jest ta sama.
     *
     * @return array<int, mixed>
     */
    private function ruleFields(): array
    {
        return [
            // ⚠️ Minimum 0,00, nie 0,01: stawka osoby towarzyszącej MUSI dać się zapisać
            // jako zero — tak właśnie wycenia się ją zgodnie z O15, bez gałęzi w kodzie.
            SharedFormComponents::getPriceInput('amount', __('Amount per person per night'), 0.00),
            TextInput::make('label')
                ->label(__('Label shown to the angler'))
                ->maxLength(255),
            TextInput::make('priority')
                ->label(__('Priority'))
                ->helperText(__('A higher number wins.'))
                ->numeric()
                ->default(0),
            DatePicker::make('effective_from')
                ->label(__('Effective from')),
            DatePicker::make('effective_to')
                ->label(__('Effective to')),
            Toggle::make('is_suspended')
                ->label(__('Suspended'))
                ->helperText(__('Stays in the price list and takes no part in pricing.')),
            // ⚠️ Warunki. Pusta oś znaczy „bez warunku na tej osi", NIE „warunek fałszywy" —
            // reguła bez ani jednego warunku jest stawką bazową łowiska.
            ToggleButtons::make('weekdays')
                ->label(__('Only on these nights'))
                ->helperText(__('Nights are identified by the day they start on.'))
                ->multiple()
                ->inline()
                ->options($this->weekdayOptions())
                ->columnSpanFull(),
            DatePicker::make('first_day_on')
                ->label(__('First night')),
            DatePicker::make('last_day_on')
                ->label(__('Last night')),
            Select::make('anglers_count')
                ->label(__('Only with exactly this many anglers'))
                ->options($this->anglerCountOptions()),
            Select::make('participant_role')
                ->label(__('Only for this role'))
                ->options(ParticipantRole::options()),
        ];
    }

    /**
     * Ostrzeżenia o konfiguracji (G3).
     *
     * ⚠️ Oba są **ostrzeżeniami, nie błędami**, i to jest świadome: dziura w cenniku może być
     * etapem porządkowania, a stawka bez warunku roli obciążająca osobę towarzyszącą może być
     * intencją („w sylwestra płacą wszyscy"). Twardym błędem jest wyłącznie remis — bo tam
     * cennik mówi dwie rzeczy naraz i żadnej nie da się wybrać uczciwie.
     */
    protected function afterSave(): void
    {
        $audit = new PricingConfigurationAudit($this->fishery()->refresh());

        $gap = $audit->firstPricingGap();

        if ($gap !== null) {
            Notification::make()
                ->warning()
                ->title(__('Some nights have no price — anglers can not buy them'))
                ->body($this->pricingGapBody($gap))
                ->send();
        }

        $outranking = $audit->ratesOutrankingCompanionRate();

        if ($outranking !== []) {
            Notification::make()
                ->warning()
                ->title(__('A rate without a role condition outranks the companion rate'))
                ->body(__('On the nights it applies, a companion will pay it. Add a role condition to that rate, or raise the priority of the companion rate.'))
                ->send();
        }
    }

    /**
     * Siedem dób jako dni rozpoczęcia — ta sama zasada co przy weekendzie na „Regułach
     * sprzedaży": zaznacza się DOBY, identyfikowane dniem rozpoczęcia.
     *
     * @return array<int, string>
     */
    private function weekdayOptions(): array
    {
        $options = [];

        foreach (range(1, 7) as $isoDay) {
            $options[$isoDay] = CarbonImmutable::now()
                ->startOfWeek(CarbonImmutable::MONDAY)
                ->addDays($isoDay - 1)
                ->locale(app()->getLocale())
                ->isoFormat('ddd');
        }

        return $options;
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
     * @param  array<string, mixed>  $state
     */
    private function ruleItemLabel(array $state): ?string
    {
        $amount = $state['amount'] ?? null;

        if (blank($amount)) {
            return null;
        }

        $label = $state['label'] ?? null;
        $suffix = filled($label) ? ' · '.$label : '';

        if ($state['is_suspended'] ?? false) {
            $suffix .= ' · '.__('suspended');
        }

        return $amount.$suffix;
    }

    /**
     * Treść ostrzeżenia o dziurze w cenniku.
     *
     * ⚠️ Komunikat ma **nazwać dzień tygodnia**, a nie samą datę. Najczęstszą przyczyną dziury
     * jest warunek dób tygodnia na stawce, więc „piątek, 25.09.2026" prowadzi operatora prosto
     * do pola, które trzeba poprawić; sama data każe mu to zgadywać (zgłoszenie z 2026-09-22).
     *
     * ⚠️ Rolę i obsadę wymieniamy **tylko wtedy, gdy są istotne**. W zwykłym przypadku (łowiący,
     * jedna osoba) zdanie „dla roli Łowiący przy 1 łowiących" jest szumem, który przykrywa jedyną
     * użyteczną informację — którą dobę poprawić.
     *
     * ⚠️ Zastrzeżenie o dzisiejszym stanie cennika ZOSTAJE, bo sprawdzenie nie analizuje osi
     * czasu — ale jest jednym krótkim zdaniem. Wcześniejsza wersja rozwijała je w wykład
     * o „regule wygasającej później" i podsuwała operatorowi trop `effective_*` nawet wtedy,
     * gdy żadna jego reguła nie miała dat obowiązywania.
     *
     * @param  array{night: CarbonImmutable, role: ParticipantRole, anglers: int}  $gap
     */
    private function pricingGapBody(array $gap): string
    {
        $body = __('The first one is :night. None of your rates covers it — check the conditions on your rates: nights of the week, date range, number of anglers, role.', [
            'night' => $gap['night']->locale(app()->getLocale())->isoFormat('dddd, D.MM.YYYY'),
        ]);

        if ($gap['role'] !== ParticipantRole::Angler || $gap['anglers'] > 1) {
            $body .= ' '.__('It concerns :role at a position taken by :anglers angler(s).', [
                'role' => mb_strtolower($gap['role']->label()),
                'anglers' => $gap['anglers'],
            ]);
        }

        return $body.' '.__('Checked against the price list as it stands today.');
    }

    /**
     * Wiersz repeatera gotowy do zapisu: rodzaj reguły plus puste warunki sprowadzone do `null`.
     *
     * ⚠️ Normalizacja pustych wartości ma jeden dom — `PriceRulesDoNotTie::withoutBlankConditions()`
     * — bo tę samą operację musi wykonać walidacja remisu, która hydratuje z wiersza model.
     * Dwie kopie rozjechałyby się przy pierwszej nowej osi warunku.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function ruleData(array $data, PriceRuleKind $kind): array
    {
        return PriceRulesDoNotTie::withoutBlankConditions($data) + ['kind' => $kind->value];
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
