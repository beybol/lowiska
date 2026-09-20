<?php

namespace App\Services;

use App\Enums\BlockEffect;
use App\Enums\PositionStatus;
use App\Enums\SaleUnavailabilityReason;
use App\Models\AvailabilityBlock;
use App\Models\Position;
use App\Models\PositionAttribute;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * JEDYNE miejsce w projekcie odpowiadające na pytanie „czy tę dobę można sprzedać
 * na tym stanowisku — i dlaczego nie" (ADR-012).
 *
 * Komponuje `FishingDayCalendar`, nie zastępuje go: kalendarz wie o CZASIE (doby,
 * reguły granic z ADR-010), ta usługa o STANOWISKU (stan własny, blokady).
 *
 * ⚠️ Warunki składane są w stałej kolejności wyrażającej TRWAŁOŚĆ przyczyny:
 * stan stanowiska → okres sprzedaży → blokada. Odmowa niesie pierwszy napotkany
 * powód. Kolejność wyznaczona kosztem zapytania zmieniłaby komunikat widziany przez
 * wędkarza przy pierwszej optymalizacji — nie przestawiaj jej dla wydajności.
 *
 * ⚠️ Zawieszenie cechy NIE jest odmową sprzedaży. Stanowisko z zawieszonym pomostem
 * nadal się sprzedaje — tylko bez pomostu. Stąd osobne pytanie `suspendedAttributes()`.
 *
 * ⚠️ Panel, portal, cennik i kalendarz wołają tę klasę. Zapytanie z
 * `where('status', 'available')` napisane obok niej jest defektem, nawet gdy dziś
 * zwraca to samo — przestanie, gdy dojdzie piąty warunek.
 */
final readonly class PositionAvailability
{
    public function __construct(private Position $position)
    {
        // Bez łowiska nie ma doby ani okresów, więc nie ma o co pytać.
        assert($position->fishery !== null);
    }

    /**
     * Czy dobę wolno sprzedać na tym stanowisku, a jeśli nie — to dlaczego.
     */
    public function availability(FishingDay|CarbonInterface|string $day): FishingDayAvailability
    {
        // 1. Stan własny stanowiska — najtrwalsza przyczyna, niezależna od dat.
        if ($this->position->status !== PositionStatus::Available) {
            return FishingDayAvailability::refused(SaleUnavailabilityReason::PositionWithdrawn);
        }

        // 2. Doba i okres sprzedaży — to rozstrzyga kalendarz, tutaj się tego nie powtarza.
        $calendar = $this->calendar();
        $fishingDay = $day instanceof FishingDay ? $day : $calendar->dayStartingOn($day);
        $calendarVerdict = $calendar->availability($fishingDay ?? $day);

        if (! $calendarVerdict->sellable || ! $fishingDay instanceof FishingDay) {
            return $calendarVerdict;
        }

        // 3. Blokada sprzedaży — wystarczy PRZECIĘCIE doby z oknem (ADR-010).
        if ($this->blocksIntersecting($fishingDay, BlockEffect::SaleBlocked)->isNotEmpty()) {
            return FishingDayAvailability::refused(SaleUnavailabilityReason::SaleBlocked);
        }

        return FishingDayAvailability::sellable();
    }

    public function isSellable(FishingDay|CarbonInterface|string $day): bool
    {
        return $this->availability($day)->sellable;
    }

    /**
     * Cechy stanowiska zawieszone w danej dobie — bez zmiany ich wartości.
     *
     * @return Collection<int, PositionAttribute>
     */
    public function suspendedAttributes(FishingDay|CarbonInterface|string $day): Collection
    {
        $fishingDay = $day instanceof FishingDay ? $day : $this->calendar()->dayStartingOn($day);

        if (! $fishingDay instanceof FishingDay) {
            return collect();
        }

        return $this->blocksIntersecting($fishingDay, BlockEffect::AttributeSuspended)
            ->map(fn (AvailabilityBlock $block): ?PositionAttribute => $block->attribute)
            ->filter()
            ->unique('id')
            ->values();
    }

    /**
     * Doby z zakresu dat, które wolno sprzedać na tym stanowisku.
     *
     * @return array<int, FishingDay>
     */
    public function sellableDaysBetween(CarbonInterface|string $from, CarbonInterface|string $to): array
    {
        return array_values(array_filter(
            $this->calendar()->daysBetween($from, $to),
            fn (FishingDay $day): bool => $this->isSellable($day),
        ));
    }

    /**
     * Wpisy o podanym skutku, których okno PRZECINA dobę.
     *
     * Okno wpisu to `starts_on` od północy do `ends_on` do końca dnia w strefie łowiska;
     * puste `ends_on` oznacza „do odwołania". Przecięcie liczy `FishingDay::overlaps()`
     * na momentach — jedyna implementacja tej reguły.
     *
     * @return Collection<int, AvailabilityBlock>
     */
    private function blocksIntersecting(FishingDay $day, BlockEffect $effect): Collection
    {
        $timezone = $this->position->fishery->timezone ?: 'Europe/Warsaw';

        return $this->position->availabilityBlocks()
            ->withEffect($effect)
            ->with('attribute')
            ->get()
            ->filter(function (AvailabilityBlock $block) use ($day, $timezone): bool {
                $windowStart = CarbonImmutable::parse($block->starts_on->toDateString(), $timezone)->startOfDay();

                // „Do odwołania" nie ma końca okna: przecięcie sprowadza się do tego,
                // czy doba kończy się PO otwarciu okna.
                if ($block->ends_on === null) {
                    return $day->endsAt > $windowStart;
                }

                $windowEnd = CarbonImmutable::parse($block->ends_on->toDateString(), $timezone)->endOfDay();

                return $day->overlaps($windowStart, $windowEnd);
            })
            ->values();
    }

    private function calendar(): FishingDayCalendar
    {
        return new FishingDayCalendar($this->position->fishery);
    }
}
