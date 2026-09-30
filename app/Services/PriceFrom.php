<?php

namespace App\Services;

use App\Models\Fishery;
use App\Models\PriceRule;
use Carbon\CarbonImmutable;

/**
 * „Cena od" łowiska — JEDYNE miejsce tej liczby (zadanie 032, portal-v3 §4).
 *
 * Najniższa kwota za osobę łowiącą za dobę spośród stawek, które WYGRYWAJĄ w którejś dobie od dziś
 * do końca trwającego albo najbliższego okresu sprzedaży. Bez dopłat, obniżki przedsprzedażowej,
 * kwoty za osobę towarzyszącą i usług — liczba ma być prosta i porównywalna między łowiskami.
 *
 * ⚠️ **To odczyt CENNIKA, nie oferta pobytu** — świadomy wyjątek od „portal pyta wyłącznie
 * `StayOffer`" (`cennik.md` §5), wzorem listy usług z 020. Oferta pracuje per stanowisko i zależy
 * od daty i długości pobytu; „cena od" jest liczbą łowiska. Cena POBYTU nadal wyłącznie przez `StayOffer`.
 *
 * ⚠️ **Reguł cennika tu nie ma.** Która stawka obowiązuje w danej dobie (daty, zawieszenie, miękkie
 * usunięcie, remis) rozstrzyga `PriceRuleResolver`; które doby należą do okresu — `FishingDayCalendar`.
 * Klasa tylko pyta o kolejne doby okna, tak jak `PricingConfigurationAudit::firstPricingGap()`.
 */
final class PriceFrom
{
    /**
     * @param  array<int, PriceRule>|null  $rules  cennik wczytany przez wołającego; `null` = wczytaj sam
     */
    public function __construct(
        private readonly Fishery $fishery,
        private readonly ?array $rules = null,
    ) {}

    /**
     * Kwota w groszach albo `null` — „cennik w przygotowaniu" (brak godzin doby, okresu albo stawki).
     */
    public function amountInCents(): ?int
    {
        if (blank($this->fishery->day_start_time) || blank($this->fishery->day_end_time)) {
            return null;
        }

        $timezone = $this->fishery->timezoneName();
        $today = CarbonImmutable::now($timezone)->startOfDay();

        // Trwający albo najbliższy okres — pierwszy, który nie skończył się przed dziś.
        $period = $this->fishery->salePeriods()
            ->whereDate('ends_on', '>=', $today->toDateString())
            ->orderBy('starts_on')
            ->first();

        if ($period === null) {
            return null;
        }

        $from = CarbonImmutable::parse($period->starts_on->toDateString(), $timezone)->startOfDay();
        $to = CarbonImmutable::parse($period->ends_on->toDateString(), $timezone)->endOfDay();

        // Przeszłości nie sprzedajemy — stawka z minionej części sezonu nie jest „ceną od".
        if ($from < $today) {
            $from = $today;
        }

        $resolver = new PriceRuleResolver($this->rules ?? $this->fishery->priceRules()->get()->all());
        $lowest = null;

        foreach ((new FishingDayCalendar($this->fishery))->daysBetween($from, $to) as $night) {
            $winner = $resolver->candidatesFor($night)[0] ?? null;

            if ($winner !== null && ($lowest === null || $winner->amountInCents() < $lowest)) {
                $lowest = $winner->amountInCents();
            }
        }

        return $lowest;
    }
}
