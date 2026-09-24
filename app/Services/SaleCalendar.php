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
 *
 * ⚠️ **Wyjątek od „wyłącznie warstwa oferty": lista usług stanowiska** (zadanie 020). Reguła
 * dotyczy KOMÓREK — sprzedawalności i ceny pobytu. Lista usług jest odczytem konfiguracji, nie
 * ofertą, więc pochodzi z domu „usług stanowiska" (`PositionServices`), a komórki i „ceny od"
 * usług nie doliczają (`panel-wlasciciela.md` §8).
 */
final class SaleCalendar
{
    private ?FishingDayCalendar $calendar = null;

    private bool $setupChecked = false;

    /** @var 'fishing_day'|'sale_period'|'position'|null */
    private ?string $missingSetup = null;

    /** @var array<int, PriceRule>|null */
    private ?array $rules = null;

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
        // Wystarczy pierwszy sezon: lista jest posortowana po początku i odfiltrowana po
        // `ends_on >= dziś`, więc pierwszy albo trwa, albo jest najbliższym przyszłym.
        $first = $this->seasons()[0] ?? null;

        if ($first === null) {
            return null;
        }

        $today = $this->today();
        $starts = $this->asLocalDay($first->starts_on);

        return $today->betweenIncluded($starts, $this->asLocalDay($first->ends_on)) ? $today : $starts;
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
        // ⚠️ Pyta o to i widok, i `getGrid()` — w tym samym renderze. Wynik na żądanie, nie
        // bufor werdyktu: instancja żyje tyle, co jedno żądanie (`dostepnosc.md` §2).
        if (! $this->setupChecked) {
            $this->missingSetup = $this->detectMissingSetup();
            $this->setupChecked = true;
        }

        return $this->missingSetup;
    }

    /**
     * @return 'fishing_day'|'sale_period'|'position'|null
     */
    private function detectMissingSetup(): ?string
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

        // ⚠️ JEDNA instancja na render: usługi, przypięcia i wartości cech wczytuje raz dla
        // wszystkich stanowisk — liczba zapytań nie rośnie z liczbą usług ani dób.
        $services = new PositionServices($this->fishery);
        $firstNight = $days[0];
        $lastNight = $days[count($days) - 1];

        foreach ($positions as $position) {
            // ⚠️ Łowisko wstrzykujemy W RELACJĘ, zamiast pozwolić jej się doczytać. Konstruktory
            // `StayOffer`, `StaySellability` i `StayPricing` sięgają po `$position->fishery`,
            // więc bez tego każde stanowisko wykonałoby własne zapytanie o łowisko, które
            // trzymamy już w ręku — 26 zapytań za nic. Przy okazji wszystkie warstwy dostają
            // TEN SAM obiekt, a nie 26 jego kopii.
            $position->setRelation('fishery', $this->fishery);

            $positionServices = $services->forNights($position, $firstNight, $lastNight);

            if ($position->status === PositionStatus::Withdrawn) {
                $rows[] = SaleCalendarRow::withdrawn($position, $positionServices);

                continue;
            }

            // ⚠️ JEDNA instancja warstwy oferty na stanowisko — blokady i okresy wczytują się
            // wtedy raz, a nie raz na komórkę. Cennik dostaje GOTOWY, wspólny dla wszystkich
            // stanowisk: bez tego każde wczytywało go osobno (28 razy przy 26 stanowiskach,
            // zadanie 023). To nie jest bufor werdyktu.
            $offer = new StayOffer($position, $this->rules());
            $cells = [];

            foreach ($days as $day) {
                $cells[] = $this->cellFor($offer, $position, $day, $anglers, $companions, $nights, $blocks);
            }

            $rows[] = SaleCalendarRow::of($position, $cells, $positionServices);
        }

        [$candidates, $deadRates] = $this->pricingDiagnostics($days);

        return new SaleCalendarGrid($days, $rows, $candidates, $deadRates);
    }

    /**
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
                // ⚠️ `positions` (właściwość), nie `positions()` (relacja): metoda relacji
                // OMIJA eager-load z `blocksByPosition()` i wykonuje `select count(*)` dla
                // każdej zablokowanej komórki. Przy trzydziestodobowej blokadzie na 26
                // stanowiskach to 780 zapytań za odpowiedź, którą mamy już wczytaną.
                $count = $block->positions->count();
                // ⚠️ `selection_label` jest NULLABLE — zbiór zaznaczony ręcznie go nie ma,
                // więc potrzebny jest wariant bez opisu kryterium, nigdy pusty nawias.
                $label = filled($block->selection_label) ? (string) $block->selection_label : null;
            }
        }

        return SaleCalendarCell::refused($day, $reason, $bundleFirstDay, $bundleLastDay, $count, $label);
    }

    /**
     * Blokada, która wyłączyła sprzedaż tej doby — albo `null`.
     *
     * ⚠️ **Reguła przecięcia doby z oknem blokady ma JEDEN dom i nie jest nim ta klasa.**
     * Liczy ją `FishingDay::overlaps()`, a granice okna ustala się dokładnie tak samo jak
     * w `PositionAvailability`: „do odwołania" nie ma końca, więc przecięcie sprowadza się do
     * tego, czy doba kończy się po otwarciu okna. Wcześniejsza wersja odtwarzała tę arytmetykę
     * ręcznie na datach — i rozjeżdżała się z warstwą niżej na granicy doby kończącej się
     * o północy ([`dostepnosc.md`](../../docs/conventions/dostepnosc.md) §1: drugi kod liczący
     * doby jest defektem).
     *
     * ⚠️ To wyszukiwanie odpowiada WYŁĄCZNIE na pytanie „którą blokadą to było", żeby
     * podpowiedź podała jej zasięg. O tym, czy doba jest sprzedawalna, rozstrzygnęła już
     * warstwa oferty — tutaj nie ma prawa zapaść żadna decyzja o sprzedaży.
     *
     * @param  array<int, AvailabilityBlock>  $blocks
     */
    private function blockCovering(array $blocks, CarbonImmutable $day): ?AvailabilityBlock
    {
        $night = $this->calendar()->dayStartingOn($day);

        if (! $night instanceof FishingDay) {
            return null;
        }

        $timezone = $this->fishery->timezoneName();
        $covering = [];

        foreach ($blocks as $block) {
            $windowStart = CarbonImmutable::parse($block->starts_on->toDateString(), $timezone)->startOfDay();

            if ($block->ends_on === null) {
                if ($night->endsAt > $windowStart) {
                    $covering[] = $block;
                }

                continue;
            }

            $windowEnd = CarbonImmutable::parse($block->ends_on->toDateString(), $timezone)->endOfDay();

            if ($night->overlaps($windowStart, $windowEnd)) {
                $covering[] = $block;
            }
        }

        if ($covering === []) {
            return null;
        }

        // ⚠️ Gdy dobę przykrywa kilka blokad, wybór musi być DETERMINISTYCZNY — inaczej
        // podpowiedź podawałaby zasięg przypadkowej z nich i zmieniałaby się między renderami.
        usort($covering, static fn (AvailabilityBlock $a, AvailabilityBlock $b): int => [
            $a->starts_on->toDateString(), (int) $a->id,
        ] <=> [
            $b->starts_on->toDateString(), (int) $b->id,
        ]);

        return $covering[0];
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
        $resolver = new PriceRuleResolver($this->rules());

        $candidates = [];

        foreach ($days as $day) {
            $matching = $resolver->candidatesForDay($day);

            if (count($matching) > 1) {
                $candidates[$day->toDateString()] = $matching;
            }
        }

        return [$candidates, (new PricingConfigurationAudit($this->fishery, $this->rules()))->deadRates()];
    }

    /**
     * Cennik łowiska — JEDNO wczytanie na render, wspólne dla warstwy oferty wszystkich
     * stanowisk, diagnostyki i audytu (zadanie 023, poz. 8).
     *
     * @return array<int, PriceRule>
     */
    private function rules(): array
    {
        if ($this->rules === null) {
            /** @var array<int, PriceRule> $rules */
            $rules = $this->fishery->priceRules()->get()->all();
            $this->rules = $rules;
        }

        return $this->rules;
    }

    /**
     * Kalendarz dób — JEDNA instancja na render. To nie jest bufor werdyktu, tylko uniknięcie
     * budowania tego samego obiektu raz na komórkę (`dostepnosc.md` §2).
     */
    private function calendar(): FishingDayCalendar
    {
        return $this->calendar ??= new FishingDayCalendar($this->fishery);
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->fishery->timezoneName())->startOfDay();
    }

    private function asLocalDay(mixed $date): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $date->toDateString(), $this->fishery->timezoneName())->startOfDay();
    }
}
