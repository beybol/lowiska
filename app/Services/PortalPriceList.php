<?php

namespace App\Services;

use App\Enums\PriceRuleKind;
use App\Enums\ServiceScope;
use App\Enums\SurchargeAudience;
use App\Models\AdditionalService;
use App\Models\Fishery;
use App\Models\Position;
use App\Models\PriceRule;
use Carbon\CarbonImmutable;

/**
 * Dane zakładki „Cennik" strony łowiska — odczyt konfiguracji łowiska, nie wycena (zadanie 034).
 *
 * ⚠️ **Klasa niczego nie liczy.** Które stawki wygrywają w jakiejś dobie rozstrzyga
 * `PricingConfigurationAudit::deadRates()` (jedna implementacja z kalendarzem 019), tekst warunku
 * dopłaty — `PriceRule::conditionText()`, cenę usługi z jednostką — `AdditionalService::priceLabelForVisitor()`,
 * przedsprzedaż — `FisheryRulesSummary`. Cena i sprzedawalność POBYTU nadal wyłącznie przez `StayOffer`;
 * to jawny wyjątek od tej zasady, tak jak `PriceFrom` i lista usług stanowiska (`cennik.md` §5).
 *
 * ⚠️ **Horyzont: od dziś, bez górnej granicy** (R3) — przyszłe sezony są widoczne. Stawki zakończone,
 * zawieszone i martwe oraz dopłaty zawieszone i zakończone się nie pokazują.
 *
 * ⚠️ **„Obowiązkowa" jest własnością PRZYPIĘCIA usługi do stanowiska**, nie usługi (`PositionServices`):
 * usługa o zasięgu „całe łowisko" nigdy nie jest obowiązkowa.
 */
final class PortalPriceList
{
    /** @var array<int, PriceRule>|null */
    private ?array $rules = null;

    public function __construct(private readonly Fishery $fishery) {}

    /**
     * Stawki za łowiącego — od najwcześniejszego okresu; stawka bez dat na początku.
     *
     * @return list<array{period: string|null, amount: string, companion: string|null, upcoming: bool}>
     */
    public function rates(): array
    {
        $today = $this->today();
        $dead = array_map(
            static fn (PriceRule $rule): int => (int) $rule->id,
            (new PricingConfigurationAudit($this->fishery, $this->rules()))->deadRates($today),
        );

        $rates = array_filter(
            $this->rules(),
            static fn (PriceRule $rule): bool => $rule->kind === PriceRuleKind::Rate
                && ! $rule->is_suspended
                && ! in_array((int) $rule->id, $dead, true),
        );

        usort($rates, static fn (PriceRule $a, PriceRule $b): int => [
            $a->first_day_on?->toDateString() ?? '', $a->amountInCents(), (int) $a->id,
        ] <=> [
            $b->first_day_on?->toDateString() ?? '', $b->amountInCents(), (int) $b->id,
        ]);

        return array_map(fn (PriceRule $rule): array => [
            'period' => $rule->visitorPeriod(),
            'amount' => $this->perNight($rule->amountInCents()),
            'companion' => $this->companion($rule),
            'upcoming' => $rule->first_day_on !== null && $rule->first_day_on->toDateString() > $today->toDateString(),
        ], $rates);
    }

    /**
     * Dopłaty pod nazwami łowiska, z warunkiem w języku wędkarza.
     *
     * @return list<array{name: string, amount: string, audience: string, condition: string}>
     */
    public function surcharges(): array
    {
        $today = $this->today()->toDateString();

        $surcharges = array_filter(
            $this->rules(),
            static fn (PriceRule $rule): bool => $rule->kind === PriceRuleKind::Surcharge
                && ! $rule->is_suspended
                && ($rule->last_day_on === null || $rule->last_day_on->toDateString() >= $today),
        );

        usort($surcharges, static fn (PriceRule $a, PriceRule $b): int => [
            mb_strtolower((string) $a->label), (int) $a->id,
        ] <=> [
            mb_strtolower((string) $b->label), (int) $b->id,
        ]);

        return array_map(fn (PriceRule $rule): array => [
            'name' => filled($rule->label) ? (string) $rule->label : __('Surcharge'),
            'amount' => '+ '.$this->perNight($rule->amountInCents()),
            'audience' => ($rule->applies_to ?? SurchargeAudience::Angler)->label(),
            'condition' => $rule->conditionText(),
        ], $surcharges);
    }

    /** Przedsprzedaż — tylko gdy okno jest otwarte albo otwiera się wkrótce (ten sam wyciąg co nad kalendarzem). */
    public function presale(): ?string
    {
        return (new FisheryRulesSummary($this->fishery))->items()['presale'] ?? null;
    }

    /**
     * Usługi dodatkowe sprzedawane na łowisku — obowiązkowe pierwsze, potem alfabetycznie.
     *
     * Usługa o zasięgu „wybrane stanowiska" bez żadnego stanowiska w sprzedaży nie jest nigdzie
     * oferowana, więc się nie pokazuje.
     *
     * @return list<array{name: string, price: string, scope: string, required: string|null, attributes: list<string>}>
     */
    public function services(): array
    {
        $inSale = $this->fishery->positions()->available()->count();

        $services = $this->fishery->additionalServices()
            ->isActive()
            ->with([
                'requiredAttributes',
                'positions' => fn ($query) => $query->available(),
            ])
            ->get();

        $items = [];

        foreach ($services as $service) {
            /** @var AdditionalService $service */
            $item = $this->serviceItem($service, $inSale);

            if ($item !== null) {
                $items[] = $item;
            }
        }

        usort($items, static fn (array $a, array $b): int => [
            $a['required'] === null ? 1 : 0, mb_strtolower($a['name']),
        ] <=> [
            $b['required'] === null ? 1 : 0, mb_strtolower($b['name']),
        ]);

        return $items;
    }

    /**
     * @return array{name: string, price: string, scope: string, required: string|null, attributes: list<string>}|null
     */
    private function serviceItem(AdditionalService $service, int $positionsInSale): ?array
    {
        $attributes = $service->requiredAttributes
            ->map(fn ($attribute): string => __($attribute->name))
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        $base = [
            'name' => $service->name,
            'price' => $service->priceLabelForVisitor($this->fishery->currency?->name),
            'attributes' => $attributes,
        ];

        if ($service->scope === ServiceScope::WholeFishery) {
            return $base + ['scope' => __('The whole fishery'), 'required' => null];
        }

        $pinned = $service->positions;

        if ($pinned->isEmpty()) {
            return null;
        }

        $scope = $pinned->count() >= $positionsInSale
            ? __('all positions')
            : __('positions :labels', ['labels' => $this->labels($pinned->all())]);

        $required = $pinned->filter(fn (Position $position): bool => (bool) data_get($position, 'pivot.is_required'));

        return $base + [
            'scope' => $scope,
            'required' => match (true) {
                $required->isEmpty() => null,
                $required->count() === $pinned->count() => __('required'),
                default => __('required at positions :labels', ['labels' => $this->labels($required->all())]),
            },
        ];
    }

    /**
     * @param  array<int, Position>  $positions
     */
    private function labels(array $positions): string
    {
        $names = array_map(static fn (Position $position): string => (string) $position->name, $positions);
        usort($names, 'strnatcasecmp');

        return implode(', ', $names);
    }

    private function companion(PriceRule $rule): ?string
    {
        $cents = $rule->companionAmountInCents();

        if ($cents === null) {
            return null;
        }

        return $cents === 0 ? __('free') : $this->perNight($cents);
    }

    private function perNight(int $cents): string
    {
        return AmountFormatter::forVisitor($cents, $this->fishery->currency?->name).' / '.__('night');
    }

    private function today(): CarbonImmutable
    {
        return FisheryDocuments::today($this->fishery);
    }

    /**
     * Cennik wczytany RAZ — ten sam zbiór dostaje analiza martwych stawek.
     *
     * @return array<int, PriceRule>
     */
    private function rules(): array
    {
        /** @var array<int, PriceRule> $rules */
        $rules = $this->rules ??= $this->fishery->priceRules()->get()->all();

        return $rules;
    }
}
