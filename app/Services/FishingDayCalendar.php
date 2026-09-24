<?php

namespace App\Services;

use App\Enums\SaleMode;
use App\Enums\SaleUnavailabilityReason;
use App\Models\Fishery;
use App\Models\SalePeriod;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Jedyne miejsce, które liczy doby wędkarskie dla łowiska (ADR-010).
 *
 * ⚠️ Ani zasoby Filamenta, ani przyszłe zapytania o dostępność, cennik czy blokady
 * nie liczą dób po swojemu — wołają tę klasę. Drugi kod liczący doby jest defektem,
 * nie optymalizacją: asymetria reguł granic przestaje wtedy obowiązywać w jednym
 * z dwóch miejsc i nic tego nie sygnalizuje.
 *
 * Wszystkie wyliczenia biegną w strefie czasowej ŁOWISKA (`fisheries.timezone`).
 * Strefa aplikacji nie bierze w nich udziału.
 */
final class FishingDayCalendar
{
    /**
     * Okresy sprzedaży wczytane RAZ na instancję.
     *
     * ⚠️ Klasa nie jest `readonly` WYŁĄCZNIE z tego powodu. `availability()` odpytuje
     * okresy przy każdym pytaniu, a pytanie o zakres dat zadaje je raz na dobę —
     * trzydziestodniowy zakres to było trzydzieści identycznych zapytań. Łowisko
     * pozostaje niezmienne (`readonly` na właściwości).
     *
     * @var array<int, SalePeriod>|null
     */
    private ?array $salePeriods = null;

    public function __construct(private readonly Fishery $fishery) {}

    /**
     * Doba rozpoczynająca się danego dnia — albo `null`, gdy łowisko nie ma
     * zdefiniowanej doby i nie wiadomo, co właściwie sprzedaje.
     */
    public function dayStartingOn(CarbonInterface|string $date): ?FishingDay
    {
        if (! $this->hasFishingDayConfigured()) {
            return null;
        }

        $timezone = $this->fishery->timezoneName();
        $startsOn = CarbonImmutable::parse($date, $timezone)->startOfDay();

        return new FishingDay(
            startsOn: $startsOn,
            startsAt: $this->atTime($startsOn, (string) $this->fishery->day_start_time),
            // Doba zawsze kończy się DNIA NASTĘPNEGO, więc przedział zawsze
            // przechodzi przez północ (zadanie 015, „Pozostałe reguły zachowania").
            endsAt: $this->atTime($startsOn->addDay(), (string) $this->fishery->day_end_time),
        );
    }

    /**
     * Doby ZAWARTE w podanym zakresie dat — czyli takie, których cały przedział
     * mieści się między początkiem dnia `$from` a końcem dnia `$to`.
     *
     * Ta sama reguła zawierania co przy okresie sprzedaży, więc ostatnia doba
     * zakresu jest tą rozpoczynającą się przedostatniego dnia.
     *
     * @return array<int, FishingDay>
     */
    public function daysBetween(CarbonInterface|string $from, CarbonInterface|string $to): array
    {
        if (! $this->hasFishingDayConfigured()) {
            return [];
        }

        $timezone = $this->fishery->timezoneName();
        $windowStart = CarbonImmutable::parse($from, $timezone)->startOfDay();
        $windowEnd = CarbonImmutable::parse($to, $timezone)->endOfDay();

        $days = [];

        for ($date = $windowStart; $date <= $windowEnd; $date = $date->addDay()) {
            $day = $this->dayStartingOn($date);

            if ($day instanceof FishingDay && $day->isContainedIn($windowStart, $windowEnd)) {
                $days[] = $day;
            }
        }

        return $days;
    }

    /**
     * Czy dobę wolno sprzedać, a jeśli nie — to dlaczego.
     *
     * Doba jest sprzedawalna wtedy i tylko wtedy, gdy mieści się W CAŁOŚCI w oknie
     * któregoś okresu sprzedaży. Brak okresów oznacza brak sprzedaży — reguła jest
     * odwrotna do intuicji „nic nie ustawiłem, więc sprzedaję normalnie" i myli się
     * wyłącznie w stronę odmowy (zadanie 015, F2).
     */
    public function availability(FishingDay|CarbonInterface|string $day): FishingDayAvailability
    {
        $day = $day instanceof FishingDay ? $day : $this->dayStartingOn($day);

        if (! $day instanceof FishingDay) {
            return FishingDayAvailability::refused(SaleUnavailabilityReason::FishingDayNotConfigured);
        }

        $periods = $this->salePeriods();

        if ($periods === []) {
            return FishingDayAvailability::refused(SaleUnavailabilityReason::NoSalePeriodDefined);
        }

        $startsBeforeEveryPeriod = true;
        $endsAfterItsPeriod = false;

        foreach ($periods as $period) {
            [$windowStart, $windowEnd] = $this->windowOf($period);

            if ($day->isContainedIn($windowStart, $windowEnd)) {
                return FishingDayAvailability::sellable();
            }

            if ($day->startsAt >= $windowStart) {
                $startsBeforeEveryPeriod = false;
            }

            // Doba zaczyna się wewnątrz okna, ale z niego wystaje — to jest ten
            // przypadek, dla którego ostatnie pozwolenie jednodobowe kupuje się
            // na PRZEDOSTATNI dzień sezonu.
            if ($day->startsAt >= $windowStart && $day->startsAt <= $windowEnd) {
                $endsAfterItsPeriod = true;
            }
        }

        if ($endsAfterItsPeriod) {
            return FishingDayAvailability::refused(SaleUnavailabilityReason::EndsAfterSalePeriod);
        }

        if ($startsBeforeEveryPeriod) {
            return FishingDayAvailability::refused(SaleUnavailabilityReason::StartsBeforeSalePeriod);
        }

        return FishingDayAvailability::refused(SaleUnavailabilityReason::OutsideSalePeriod);
    }

    public function isSellable(FishingDay|CarbonInterface|string $day): bool
    {
        return $this->availability($day)->sellable;
    }

    /**
     * Okno okresu sprzedaży: daty kalendarzowe, od północy do końca dnia,
     * w strefie łowiska.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function windowOf(SalePeriod $period): array
    {
        $timezone = $this->fishery->timezoneName();

        return [
            CarbonImmutable::parse($period->starts_on->toDateString(), $timezone)->startOfDay(),
            CarbonImmutable::parse($period->ends_on->toDateString(), $timezone)->endOfDay(),
        ];
    }

    /**
     * @return array<int, SalePeriod>
     */
    private function salePeriods(): array
    {
        return $this->salePeriods ??= $this->fishery->salePeriods()->orderBy('starts_on')->get()->all();
    }

    private function hasFishingDayConfigured(): bool
    {
        if (blank($this->fishery->day_start_time) || blank($this->fishery->day_end_time)) {
            return false;
        }

        // ⚠️ `match` BEZ gałęzi domyślnej, a nie porównanie z jedyną dzisiejszą
        // wartością: porównanie przy jednowariantowym enumie jest tautologią
        // (Larastan słusznie ją zgłasza), a wyczerpujący `match` zaczerwieni się
        // sam, gdy dojdzie tryb godzinowy albo turnus — czyli dokładnie wtedy,
        // gdy ta metoda przestanie być prawdziwa. Wartość nigdy nie jest `null`:
        // kolumna jest NOT NULL, a `Fishery::$attributes` niesie tę samą domyślną.
        return match ($this->fishery->sale_mode) {
            SaleMode::DailyPeriod => true,
        };
    }

    private function atTime(CarbonImmutable $date, string $time): CarbonImmutable
    {
        // Sklejamy datę lokalną z godziną i rozwiązujemy w strefie łowiska, więc
        // doba biegnie po ZEGARZE LOKALNYM — stąd 23 albo 25 godzin przy zmianie czasu.
        return CarbonImmutable::parse(
            $date->format('Y-m-d').' '.substr($time, 0, 8),
            $this->fishery->timezoneName(),
        );
    }
}
