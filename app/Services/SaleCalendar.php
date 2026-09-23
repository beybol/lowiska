<?php

namespace App\Services;

use App\Enums\BlockEffect;
use App\Enums\CalendarWindow;
use App\Enums\PositionStatus;
use App\Enums\SaleUnavailabilityReason;
use App\Models\AvailabilityBlock;
use App\Models\Fishery;
use App\Models\Position;
use App\Models\PriceRule;
use App\Models\SalePeriod;
use Carbon\CarbonImmutable;

/**
 * Kalendarz podglądowy „co z tego wynika" — mechanizm G4 (zadanie 019).
 *
 * ⚠️ **Woła WYŁĄCZNIE warstwę oferty.** Nie pyta osobno o sprzedawalność i osobno o cenę,
 * nie dotyka `StaySellability`, `PositionAvailability` ani wyceny wprost. Powód jest ten sam,
 * dla którego warstwa oferty powstała: dwóch dostawców odpowiedzi nie może mieć dwóch miejsc
 * składania (ADR-012, ADR-013, ADR-015).
 *
 * ⚠️ **Nie liczy najkrótszego pobytu sam.** Wartość składa się z pakietu, `min_nights`,
 * zwolnienia świątecznego, minimum przedsprzedaży i `max_nights` — czyli z reguł należących
 * do 017. Odtworzenie ich tutaj zrobiłoby z kalendarza drugie źródło prawdy.
 *
 * ⚠️ **Nie buforuje werdyktu** — zakazane przez `dostepnosc.md` §2 bez uzasadnienia
 * pomiarowego. Wolno wyłącznie to, co buforem nie jest: jedna instancja warstwy oferty na
 * stanowisko i jedno wczytanie cennika oraz blokad na cały render.
 */
final class SaleCalendar
{
    public function __construct(private readonly Fishery $fishery) {}

    /**
     * Okresy sprzedaży **trwające i przyszłe**, w kolejności rozpoczęcia.
     *
     * ⚠️ Zakończonych nie pokazujemy — przeszłości nie sprzedajemy, więc nie ma czego
     * weryfikować, a lista rosłaby z każdym sezonem.
     *
     * @return array<int, SalePeriod>
     */
    public function seasons(): array
    {
        $today = $this->today();

        /** @var array<int, SalePeriod> $periods */
        $periods = $this->fishery->salePeriods()
            ->whereDate('ends_on', '>=', $today->toDateString())
            ->orderBy('starts_on')
            ->get()
            ->all();

        return $periods;
    }

    /**
     * Kotwica widoku: dziś, jeśli mieścimy się w którymś okresie; inaczej początek
     * najbliższego przyszłego.
     *
     * ⚠️ **Okno „najbliższe 30 dni od dziś" NIE pokrywa głównego przypadku użycia.** Kalendarz
     * służy do sprawdzenia dopiero co wprowadzonej konfiguracji, a ta zwykle dotyczy przyszłego
     * sezonu: łowisko ustawiające sezon 2027 w listopadzie 2026 zobaczyłoby same odmowy „poza
     * sezonem", czyli nic.
     */
    public function anchor(): ?CarbonImmutable
    {
        $today = $this->today();

        foreach ($this->seasons() as $period) {
            $starts = $this->asLocalDay($period->starts_on);
            $ends = $this->asLocalDay($period->ends_on);

            if ($today->betweenIncluded($starts, $ends)) {
                return $today;
            }

            return $starts;
        }

        return null;
    }

    /**
     * Czego brakuje, żeby siatka miała sens — albo `null`, gdy niczego.
     *
     * ⚠️ Kalendarz jest narzędziem **onboardingu**, więc mówi, czego brakuje i dokąd pójść.
     * Trzydzieści kolumn „poza sezonem" nie jest odpowiedzią dla kogoś, kto dopiero konfiguruje
     * obiekt — a to jest główny scenariusz tego ekranu.
     *
     * @return 'fishing_day'|'sale_period'|'position'|null
     */
    public function missingSetup(): ?string
    {
        if (blank($this->fishery->day_start_time) || blank($this->fishery->day_end_time)) {
            return 'fishing_day';
        }

        if ($this->fishery->salePeriods()->count() === 0) {
            return 'sale_period';
        }

        if ($this->fishery->positions()->count() === 0) {
            return 'position';
        }

        return null;
    }

    /**
     * ⚠️ **Zwykłe sprawdzenie ISTNIENIA, a nie druga implementacja wykrywania dziur.** Pełna
     * analiza — iteracja po dobach okresów sprzedaży — mieszka w walidacji zadania 018 i ma
     * tam zostać. Tutaj chodzi tylko o komunikat nad siatką dla łowiska bez ani jednej stawki.
     */
    public function hasNoRates(): bool
    {
        return $this->fishery->rateRules()->count() === 0;
    }

    /**
     * Siatka dla jednego okna.
     *
     * @param  int|null  $nights  `null` = najkrótszy kupowalny pobyt, czyli widok „ceny od"
     */
    public function grid(
        CarbonImmutable $from,
        CalendarWindow $unit = CalendarWindow::Month,
        int $anglers = 1,
        int $companions = 0,
        ?int $nights = null,
    ): SaleCalendarGrid {
        $days = $this->daysOf($from, $unit);

        /** @var array<int, Position> $positions */
        $positions = $this->fishery->positions()->orderBy('name')->get()->all();

        $blocks = $this->blocksByPosition();
        $rows = [];

        foreach ($positions as $position) {
            if ($position->status === PositionStatus::Withdrawn) {
                $rows[] = SaleCalendarRow::withdrawn($position);

                continue;
            }

            // ⚠️ JEDNA instancja warstwy oferty na stanowisko — blokady, okresy i cennik
            // wczytują się wtedy raz, a nie raz na komórkę. To nie jest bufor werdyktu.
            $offer = new StayOffer($position);
            $cells = [];

            foreach ($days as $day) {
                $cells[] = $this->cellFor($offer, $position, $day, $anglers, $companions, $nights, $blocks);
            }

            $rows[] = SaleCalendarRow::of($position, $cells);
        }

        [$candidates, $deadRates] = $this->pricingDiagnostics($days);

        return new SaleCalendarGrid($days, $rows, $candidates, $deadRates);
    }

    /**
     * @param  array<int, CarbonImmutable>  $days
     * @return array<int, CarbonImmutable>
     */
    private function daysOf(CarbonImmutable $from, CalendarWindow $unit): array
    {
        $day = $unit->startFor($from);
        $last = $unit->endFor($from);
        $days = [];

        while ($day <= $last) {
            $days[] = $day;
            $day = $day->addDay();
        }

        return $days;
    }

    /**
     * @param  array<int, array<int, AvailabilityBlock>>  $blocks
     */
    private function cellFor(
        StayOffer $offer,
        Position $position,
        CarbonImmutable $day,
        int $anglers,
        int $companions,
        ?int $nights,
        array $blocks,
    ): SaleCalendarCell {
        // ⚠️ Stała długość i „najkrótszy możliwy" to dwa różne pytania do warstwy oferty,
        // a nie jedno z flagą — i oba zadaje ona, nie kalendarz.
        if ($nights !== null) {
            $verdict = $offer->offer($day, $nights, $anglers, $companions);

            if ($verdict->available && $verdict->breakdown !== null) {
                return SaleCalendarCell::sellable($day, $nights, $verdict->breakdown->totalInCents());
            }

            return $this->refusedCell(
                $day,
                $verdict->reason ?? SaleUnavailabilityReason::OutsideSalePeriod,
                $verdict->sellability?->bundleFirstDay,
                $verdict->sellability?->bundleLastDay,
                $position,
                $blocks,
            );
        }

        $shortest = $offer->shortestOffer($day, $anglers, $companions);

        if ($shortest->available && $shortest->nights !== null && $shortest->breakdown !== null) {
            return SaleCalendarCell::sellable($day, $shortest->nights, $shortest->breakdown->totalInCents());
        }

        $reason = $shortest->reason ?? SaleUnavailabilityReason::OutsideSalePeriod;

        if ($shortest->startEarlierOn instanceof CarbonImmutable) {
            return SaleCalendarCell::startsEarlier($day, $reason, $shortest->startEarlierOn);
        }

        return $this->refusedCell($day, $reason, null, null, $position, $blocks);
    }

    /**
     * @param  array<int, array<int, AvailabilityBlock>>  $blocks
     */
    private function refusedCell(
        CarbonImmutable $day,
        SaleUnavailabilityReason $reason,
        ?CarbonImmutable $bundleFirstDay,
        ?CarbonImmutable $bundleLastDay,
        Position $position,
        array $blocks,
    ): SaleCalendarCell {
        $count = null;
        $label = null;

        // ⚠️ **Zasięg blokady musi być widoczny, bo to on uzasadnia ten wariant siatki.**
        // Argumentem za najdroższym widokiem jest „blokada objęła piętnaście miejsc zamiast
        // pięciu" — ale operator zobaczy to tylko wtedy, gdy podpowiedź poda liczbę objętych
        // stanowisk. Bez tego zostaje mu liczenie zaczernionych komórek wzrokiem.
        if ($reason === SaleUnavailabilityReason::SaleBlocked) {
            $block = $this->blockCovering($blocks[$position->id] ?? [], $day);

            if ($block instanceof AvailabilityBlock) {
                $count = $block->positions()->count();
                // ⚠️ `selection_label` jest NULLABLE — zbiór zaznaczony ręcznie go nie ma,
                // więc potrzebny jest wariant bez opisu kryterium, nigdy pusty nawias.
                $label = filled($block->selection_label) ? (string) $block->selection_label : null;
            }
        }

        return SaleCalendarCell::refused($day, $reason, $bundleFirstDay, $bundleLastDay, $count, $label);
    }

    /**
     * @param  array<int, AvailabilityBlock>  $blocks
     */
    private function blockCovering(array $blocks, CarbonImmutable $day): ?AvailabilityBlock
    {
        foreach ($blocks as $block) {
            $starts = $this->asLocalDay($block->starts_on);
            $ends = $block->ends_on === null ? null : $this->asLocalDay($block->ends_on);

            // ⚠️ Blokada działa przez PRZECIĘCIE z dobą, a okres sprzedaży przez ZAWIERANIE —
            // ta asymetria jest udokumentowana w `dostepnosc.md` i nie wolno jej tu zgubić.
            // Doba zaczynająca się dnia D kończy się nazajutrz, więc blokada dnia D+1 też ją tnie.
            if ($day->addDay() < $starts) {
                continue;
            }

            if ($ends === null || $day <= $ends) {
                return $block;
            }
        }

        return null;
    }

    /**
     * Blokady sprzedaży wczytane RAZ na render, pogrupowane po stanowisku.
     *
     * @return array<int, array<int, AvailabilityBlock>>
     */
    private function blocksByPosition(): array
    {
        $blocks = $this->fishery->availabilityBlocks()
            ->where('effect', BlockEffect::SaleBlocked->value)
            ->with('positions:id')
            ->get();

        $byPosition = [];

        foreach ($blocks as $block) {
            foreach ($block->positions as $position) {
                $byPosition[(int) $position->id][] = $block;
            }
        }

        return $byPosition;
    }

    /**
     * Diagnostyka cennika — pobierana RAZ NA OKNO, nie raz na komórkę.
     *
     * ⚠️ Kandydaci zależą wyłącznie od daty, bo stawka po uproszczeniu 018 nie zna ani
     * stanowiska, ani składu uczestników. Pobranie ich per komórka byłoby trzydziestokrotnym
     * powtórzeniem tej samej odpowiedzi razy liczba stanowisk.
     *
     * ⚠️ **Kalendarz nie dopasowuje stawek sam** — po uproszczeniu jest to wprawdzie samo
     * porównanie dat, ale nadal logika cennika, a ta ma jeden dom (ADR-014).
     *
     * @param  array<int, CarbonImmutable>  $days
     * @return array{0: array<string, array<int, PriceRule>>, 1: array<int, PriceRule>}
     */
    private function pricingDiagnostics(array $days): array
    {
        /** @var array<int, PriceRule> $rules */
        $rules = $this->fishery->priceRules()->get()->all();
        $resolver = new PriceRuleResolver($rules);

        $candidates = [];

        foreach ($days as $day) {
            $matching = $resolver->candidatesForDay($day);

            if (count($matching) > 1) {
                $candidates[$day->toDateString()] = $matching;
            }
        }

        return [$candidates, (new PricingConfigurationAudit($this->fishery))->deadRates()];
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone())->startOfDay();
    }

    private function asLocalDay(mixed $date): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $date->toDateString(), $this->timezone())->startOfDay();
    }

    private function timezone(): string
    {
        return $this->fishery->timezone ?: 'Europe/Warsaw';
    }
}
