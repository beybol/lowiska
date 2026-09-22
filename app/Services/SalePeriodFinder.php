<?php

namespace App\Services;

use App\Models\Fishery;
use App\Models\SalePeriod;
use Carbon\CarbonImmutable;

/**
 * Do którego okresu sprzedaży należy doba i czy okno przedsprzedaży tego okresu jest otwarte.
 *
 * ⚠️ Klasa istnieje, żeby **nie było drugiego literału** tych dwóch reguł. Pytają o nie
 * `StaySellability` (horyzont i minimum przedsprzedaży, 017) oraz `StayPricing` (obniżka
 * przedsprzedażowa, 018) — a obie warstwy mają zostać od siebie niezależne, więc nie mogą
 * pytać jedna drugiej. Wspólny pomocnik jest jedynym wariantem, w którym reguła ma jeden dom
 * i nie zlepia warstw (`CLAUDE.md`: drugi literał tej samej reguły to defekt).
 *
 * ⚠️ To NIE jest warstwa składająca odpowiedzi — ta jest jedna i opisuje ją ADR-015.
 * Tutaj mieszkają wyłącznie dwa pytania o okres sprzedaży.
 */
final class SalePeriodFinder
{
    /**
     * Okresy wczytane RAZ na instancję — pytanie o zakres dób zadawałoby je inaczej
     * raz na dobę.
     *
     * @var array<int, SalePeriod>|null
     */
    private ?array $periods = null;

    public function __construct(private readonly Fishery $fishery) {}

    /**
     * Okres sprzedaży, w którym ZAWIERA się ta doba.
     *
     * ⚠️ Reguła zawierania, nie przecięcia — ta sama, którą stosuje `FishingDayCalendar`
     * przy sprzedaży doby. Stąd wynika, że doba na styku dwóch sąsiadujących okresów nie
     * należy do żadnego z nich (`dostepnosc.md` §1).
     */
    public function forNight(FishingDay $night): ?SalePeriod
    {
        $timezone = $this->timezone();

        foreach ($this->periods() as $period) {
            $windowStart = CarbonImmutable::parse($period->starts_on->toDateString(), $timezone)->startOfDay();
            $windowEnd = CarbonImmutable::parse($period->ends_on->toDateString(), $timezone)->endOfDay();

            if ($night->isContainedIn($windowStart, $windowEnd)) {
                return $period;
            }
        }

        return null;
    }

    /**
     * Czy okno przedsprzedaży tego okresu jest otwarte W TEJ CHWILI.
     *
     * Okno obejmuje CAŁE dni brzegowe — od `presale_opens_on` od północy do `presale_closes_on`
     * do końca dnia w strefie łowiska, tak samo jak okno blokady (`dostepnosc.md` §3).
     * Porównywanym momentem jest chwila zakupu.
     */
    public function hasOpenPresale(SalePeriod $period): bool
    {
        if (! $period->hasPresale()) {
            return false;
        }

        $timezone = $this->timezone();
        $now = CarbonImmutable::now($timezone);
        $opensAt = CarbonImmutable::parse($period->presale_opens_on->toDateString(), $timezone)->startOfDay();
        $closesAt = CarbonImmutable::parse($period->presale_closes_on->toDateString(), $timezone)->endOfDay();

        return $now >= $opensAt && $now <= $closesAt;
    }

    public function timezone(): string
    {
        return $this->fishery->timezone ?: 'Europe/Warsaw';
    }

    /**
     * @return array<int, SalePeriod>
     */
    private function periods(): array
    {
        return $this->periods ??= $this->fishery->salePeriods()->orderBy('starts_on')->get()->all();
    }
}
