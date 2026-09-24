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
use InvalidArgumentException;

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
final class PositionAvailability
{
    private ?FishingDayCalendar $calendar = null;

    /**
     * Wpisy o dostępności wczytane RAZ na instancję, kluczowane skutkiem.
     *
     * @var array<string, Collection<int, AvailabilityBlock>>
     */
    private array $blocksByEffect = [];

    public function __construct(private readonly Position $position)
    {
        // ⚠️ Jawny wyjątek, nie `assert()`: `positions.fishery_id` jest świadomie
        // `nullable` z `set null` (`panel-wlasciciela.md` §8), a asercje są wyłączone
        // w obrazie produkcyjnym (`zend.assertions=-1`) — tam `assert()` przepuszczał
        // stanowisko bez łowiska do `new FishingDayCalendar(null)` i do `->timezone`
        // na `null`. Bez łowiska nie ma doby ani okresów, więc nie ma o co pytać.
        if ($position->fishery === null) {
            throw new InvalidArgumentException(
                "Position {$position->id} has no fishery, so its sale availability is undefined."
            );
        }
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
     * WPISY zawieszające cechy stanowiska w dobie albo w ciągu dób — z powodem i datami.
     *
     * ⚠️ `suspendedAttributes()` mówi, KTÓRE cechy są zawieszone; ta metoda mówi, KTÓRE WPISY je
     * zawieszają, bo przyczyna niedostępności usługi ma nieść powód i zakres dat ograniczenia
     * (G11, zadanie 020). Reguła przecięcia wpisu z dobą zostaje tu, w jednym domu — klasa
     * usług stanowiska nie pyta o blokady sama.
     *
     * Zakres podaje się dniami rozpoczęcia PIERWSZEJ i OSTATNIEJ doby (obie włącznie), tak jak
     * kolumny siatki kalendarza — to nie jest reguła zawierania z `sellableDaysBetween()`.
     * Wpisy czyta to samo jedno zapytanie na instancję co reszta tej klasy.
     *
     * @return Collection<int, AvailabilityBlock>
     */
    public function attributeSuspensions(
        FishingDay|CarbonInterface|string $firstNight,
        FishingDay|CarbonInterface|string|null $lastNight = null,
    ): Collection {
        $calendar = $this->calendar();
        $first = $firstNight instanceof FishingDay ? $firstNight : $calendar->dayStartingOn($firstNight);

        if (! $first instanceof FishingDay) {
            return collect();
        }

        $last = $lastNight === null
            ? $first
            : ($lastNight instanceof FishingDay ? $lastNight : $calendar->dayStartingOn($lastNight));

        if (! $last instanceof FishingDay) {
            return collect();
        }

        $found = collect();

        for ($date = $first->startsOn; $date <= $last->startsOn; $date = $date->addDay()) {
            $day = $calendar->dayStartingOn($date);

            if ($day instanceof FishingDay) {
                $found = $found->merge($this->blocksIntersecting($day, BlockEffect::AttributeSuspended));
            }
        }

        return $found->unique('id')->values();
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

        return $this->blocksWithEffect($effect)
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

    /**
     * Wpisy o danym skutku — jedno zapytanie na instancję i skutek.
     *
     * ⚠️ To NIE jest bufor dostępności zakazany przez `dostepnosc.md` §2. Tamten zakaz
     * dotyczy kolumny przechowującej werdykt między żądaniami; tutaj odczyt żyje tyle,
     * co instancja usługi, a pytanie o zakres dat (`sellableDaysBetween()`) wykonywało
     * bez tego jedno zapytanie NA DOBĘ.
     *
     * @return Collection<int, AvailabilityBlock>
     */
    private function blocksWithEffect(BlockEffect $effect): Collection
    {
        return $this->blocksByEffect[$effect->value] ??= $this->position->availabilityBlocks()
            ->withEffect($effect)
            ->with('attribute')
            ->get();
    }

    private function calendar(): FishingDayCalendar
    {
        return $this->calendar ??= new FishingDayCalendar($this->position->fishery);
    }
}
