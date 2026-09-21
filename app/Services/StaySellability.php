<?php

namespace App\Services;

use App\Enums\SaleUnavailabilityReason;
use App\Models\Fishery;
use App\Models\Position;
use App\Models\SalePeriod;
use App\Models\WholeTermPeriod;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * JEDYNE miejsce odpowiadające na pytanie „czy ten POBYT wolno kupić w tej chwili —
 * a jeśli nie, to dlaczego" (ADR-013, zadanie 017).
 *
 * **Pobyt** to ciągły zakres dób na JEDNYM stanowisku, opisany dobą rozpoczęcia
 * i liczbą dób. Reguły z tego zadania dotyczą ciągu dób i nie dają się wyrazić jako
 * własność żadnej z nich osobno — stąd osobne pojęcie i osobna klasa.
 *
 * ⚠️ **KOMPONUJE `PositionAvailability`, nie zastępuje jej ani nie powtarza jej
 * warunków.** `PositionAvailability` pozostaje jedynym miejscem odpowiadającym o DOBĘ
 * (`dostepnosc.md` §2, ADR-012); ta klasa dokłada warstwę o POBYCIE. Granica jest ta
 * sama co między `FishingDayCalendar` a `PositionAvailability`: pytasz o dobę →
 * tamta klasa, pytasz o pobyt → ta. Panel, cennik (018), kalendarz (019) i przyszły
 * portal wołają tę klasę i nie powtarzają tych warunków u siebie.
 *
 * ⚠️ **Kolejność warunków wyraża TRWAŁOŚĆ przyczyny** i jest umową produktową, nie
 * skutkiem kosztu zapytania (to samo kryterium, którym ADR-012 uporządkował warunki
 * wewnątrz dostępności). Nie przestawiaj jej dla wydajności — zmieniłaby komunikat
 * widziany przez wędkarza:
 *
 *   1. dostępność KAŻDEJ doby pobytu (przez `PositionAvailability`),
 *   2. spoiwo — pobyt przecinający instancję pakietu musi ją objąć w całości,
 *   3. długość — `min_nights` / `max_nights`,
 *   4. horyzont sprzedaży i przedsprzedaż.
 *
 * ⚠️ **Nic nie jest zmaterializowane.** Instancje pakietów liczą się w chwili pytania;
 * nie ma kolumny buforującej werdykt (`dostepnosc.md` §2). Odczyty pamiętane
 * w obrębie JEDNEJ instancji usługi są dozwolone i konieczne — instancja żyje tyle,
 * co jedno pytanie, więc nie trzymaj jej dłużej w polu innego obiektu.
 */
final class StaySellability
{
    /**
     * Twardy limit długości marszu po spoiwie.
     *
     * ⚠️ Nie jest regułą biznesową, tylko bezpiecznikiem. Walidacja nie dopuszcza
     * weekendu obejmującego wszystkie siedem dób (pakiet nieskończony), ale dane
     * wpisane wprost do bazy tej walidacji nie przechodzą — bez limitu marsz po
     * takim zbiorze nigdy by się nie zatrzymał.
     */
    private const MAX_BUNDLE_WALK = 400;

    private readonly Fishery $fishery;

    private ?FishingDayCalendar $calendar = null;

    private ?PositionAvailability $availability = null;

    /** @var array<int, WholeTermPeriod>|null */
    private ?array $wholeTermPeriods = null;

    /** @var array<int, SalePeriod>|null */
    private ?array $salePeriods = null;

    /**
     * Werdykt dostępności doby, pamiętany po dniu rozpoczęcia.
     *
     * ⚠️ Marsz po spoiwie pyta o te same doby wielokrotnie (raz przy szukaniu
     * instancji pierwszej doby, raz przy ostatniej), a każde pytanie to przejście
     * przez `PositionAvailability`. Bez tej tablicy pobyt ośmiodobowy w weekendzie
     * zadawał to samo pytanie kilkanaście razy.
     *
     * @var array<string, bool>
     */
    private array $sellableByDate = [];

    public function __construct(private readonly Position $position)
    {
        // ⚠️ Jawny wyjątek, nie `assert()` — tak samo jak w `PositionAvailability`
        // i z tego samego powodu: asercje są wyłączone w obrazie produkcyjnym
        // (`zend.assertions=-1`), czyli dokładnie tam, gdzie ochrona ma działać.
        if ($position->fishery === null) {
            throw new InvalidArgumentException(
                "Position {$position->id} has no fishery, so stay sellability is undefined."
            );
        }

        $this->fishery = $position->fishery;
    }

    /**
     * Czy pobyt rozpoczynający się daną dobą i trwający `$nights` dób wolno kupić.
     *
     * @param  int  $nights  liczba dób pobytu, co najmniej jedna
     */
    public function verdict(CarbonInterface|string $startsOn, int $nights): StaySellabilityVerdict
    {
        if ($nights < 1) {
            throw new InvalidArgumentException("A stay must last at least one night, {$nights} given.");
        }

        $firstDate = CarbonImmutable::parse($startsOn, $this->timezone())->startOfDay();

        // 1. Dostępność KAŻDEJ doby pobytu. Nie powtarzamy tu żadnego z warunków
        //    `PositionAvailability` — stan stanowiska, okres sprzedaży i blokady
        //    rozstrzyga ona, a zmiana po tamtej stronie zmienia wynik tutaj bez
        //    dotykania tego kodu.
        $days = [];

        for ($offset = 0; $offset < $nights; $offset++) {
            $day = $this->calendar()->dayStartingOn($firstDate->addDays($offset));

            if (! $day instanceof FishingDay) {
                // Łowisko bez godzin doby nie wie, co sprzedaje — nie ma nawet czego
                // wskazać jako doby, która zawiodła.
                return StaySellabilityVerdict::refusedDay(
                    SaleUnavailabilityReason::FishingDayNotConfigured,
                    null,
                );
            }

            $verdict = $this->availability()->availability($day);

            if (! $verdict->sellable) {
                // ⚠️ Powód przepuszczony WRAZ z dobą, która zawiodła: „stanowisko
                // wycofane" bez daty nic nie mówi przy pobycie ośmiodobowym.
                return StaySellabilityVerdict::refusedDay($verdict->reason, $day);
            }

            $this->sellableByDate[$day->startsOn->toDateString()] = true;
            $days[] = $day;
        }

        // 2. Spoiwo.
        $bundleRefusal = $this->bundleRefusal($days);

        if ($bundleRefusal instanceof StaySellabilityVerdict) {
            return $bundleRefusal;
        }

        // Zwolnienie z minimum należy do PAKIETU zawierającego dobę święta, nie do
        // samego święta, i jest zero-jedynkowe — bez żadnej arytmetyki. Pobyt, który
        // doszedł aż tutaj, obejmuje każdy dotknięty pakiet w CAŁOŚCI (punkt 2), więc
        // nigdy nie jest od niego krótszy; obniżanie minimum do długości pakietu
        // dałoby ten sam wynik i tylko sugerowało obliczenia, których nie ma.
        $coversWholeTerm = $this->coversWholeTermNight($days);

        // 3. Długość. `max_nights` obowiązuje także pobyt ze świętem — zwolnienie
        //    dotyczy wyłącznie dolnej granicy („spoiwo tylko zaostrza, święto może
        //    łagodzić").
        if ($this->fishery->max_nights !== null && $nights > $this->fishery->max_nights) {
            return StaySellabilityVerdict::refusedStay(SaleUnavailabilityReason::StayTooLong);
        }

        if ($this->fishery->min_nights !== null
            && $nights < $this->fishery->min_nights
            && ! $coversWholeTerm) {
            return StaySellabilityVerdict::refusedStay(SaleUnavailabilityReason::StayTooShort);
        }

        // 4. Horyzont, a po nim minimum przedsprzedaży. Kolejność w obrębie tego
        //    kroku jest istotna: przy OTWARTYM oknie doba poza horyzontem przechodzi
        //    punkt horyzontu i odpada dopiero na minimum okna, więc wędkarz dostaje
        //    „dokup doby", a nie „ta data jest za daleko".
        $horizonRefusal = $this->horizonRefusal($days);

        if ($horizonRefusal instanceof StaySellabilityVerdict) {
            return $horizonRefusal;
        }

        return $this->presaleMinimumRefusal($days, $nights, $coversWholeTerm)
            ?? StaySellabilityVerdict::sellable();
    }

    public function isSellable(CarbonInterface|string $startsOn, int $nights): bool
    {
        return $this->verdict($startsOn, $nights)->sellable;
    }

    /**
     * Instancja pakietu, do której należy doba rozpoczynająca się danego dnia —
     * albo `null`, gdy doba nie jest spięta spoiwem albo nie jest sprzedawalna.
     *
     * *Instancja pakietu* to maksymalny ciąg KOLEJNYCH dób spiętych spoiwem, zbudowany
     * WYŁĄCZNIE z dób sprzedawalnych (przycinanie) i zlany, gdy spoiwa na siebie
     * zachodzą (spoiwo jest przechodnie). Zwracane granice to dni rozpoczęcia
     * pierwszej i ostatniej doby instancji.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public function bundleAround(CarbonInterface|string $date): ?array
    {
        $date = CarbonImmutable::parse($date, $this->timezone())->startOfDay();

        if (! $this->isGluedAndSellable($date)) {
            return null;
        }

        $first = $date;
        $last = $date;

        for ($step = 0; $step < self::MAX_BUNDLE_WALK; $step++) {
            $probe = $first->subDay();

            if (! $this->isGluedAndSellable($probe)) {
                break;
            }

            $first = $probe;
        }

        for ($step = 0; $step < self::MAX_BUNDLE_WALK; $step++) {
            $probe = $last->addDay();

            if (! $this->isGluedAndSellable($probe)) {
                break;
            }

            $last = $probe;
        }

        return [$first, $last];
    }

    /**
     * Odmowa ze spoiwa — albo `null`, gdy pobyt nie rozrywa żadnego pakietu.
     *
     * ⚠️ Sprawdzane są WYŁĄCZNIE dwie skrajne doby pobytu, i to wystarcza: instancja
     * jest ciągiem kolejnych dób, więc jeśli wystaje poza pobyt, musi przechodzić
     * przez jego brzeg. Instancja dotykająca wyłącznie środka pobytu jest w nim
     * zawarta w całości.
     *
     * @param  array<int, FishingDay>  $days
     */
    private function bundleRefusal(array $days): ?StaySellabilityVerdict
    {
        $firstNight = $days[0]->startsOn;
        $lastNight = $days[count($days) - 1]->startsOn;

        $startingBundle = $this->bundleAround($firstNight);

        if ($startingBundle !== null && $startingBundle[0] < $firstNight) {
            return $this->refusalFor($startingBundle);
        }

        $endingBundle = $this->bundleAround($lastNight);

        if ($endingBundle !== null && $endingBundle[1] > $lastNight) {
            return $this->refusalFor($endingBundle);
        }

        return null;
    }

    /**
     * Który z dwóch powodów niesie odmowa dla danej instancji.
     *
     * ⚠️ Pakiet ZLANY ze świętem odmawia jako „przerwane święto", nie „przerwany
     * weekend" — wystarczy jedna doba święta w instancji (zadanie 017,
     * rozstrzygnięcie 22). Powód: to święto nadaje pakietowi zwolnienie z minimum,
     * więc jest jego cechą dominującą, a nazwa własna święta jest dla wędkarza
     * rozpoznawalna. Siódmego powodu dla pakietów zlanych świadomie nie ma.
     *
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}  $bundle
     */
    private function refusalFor(array $bundle): StaySellabilityVerdict
    {
        [$first, $last] = $bundle;

        $reason = SaleUnavailabilityReason::WeekendBroken;

        for ($date = $first; $date <= $last; $date = $date->addDay()) {
            if ($this->wholeTermCovering($date) instanceof WholeTermPeriod) {
                $reason = SaleUnavailabilityReason::WholeTermBroken;

                break;
            }
        }

        return StaySellabilityVerdict::refusedBundle($reason, $first, $last);
    }

    /**
     * Czy pobyt obejmuje choć jedną dobę święta — czyli czy jest zwolniony z minimum.
     *
     * Wystarczy sprawdzić same doby pobytu: każda z nich jest sprzedawalna (punkt 1),
     * a doba święta jest spięta spoiwem z definicji, więc należy do jakiejś instancji
     * — a ta, po przejściu punktu 2, jest w pobycie zawarta w całości.
     * ⚠️ Dotyczy to także pakietu PRZYCIĘTEGO do jednej doby przez blokadę: zwolnienie
     * zostaje, bo wyjątek od tego byłby przypadkiem szczególnym bez właściciela.
     *
     * @param  array<int, FishingDay>  $days
     */
    private function coversWholeTermNight(array $days): bool
    {
        foreach ($days as $day) {
            if ($this->wholeTermCovering($day->startsOn) instanceof WholeTermPeriod) {
                return true;
            }
        }

        return false;
    }

    /**
     * Odmowa z horyzontu sprzedaży — albo `null`.
     *
     * Granica jest DOMKNIĘTA: doba rozpoczynająca się dokładnie `sale_horizon_days`
     * dni po dzisiejszym dniu jeszcze przechodzi. Porównanie idzie na DATACH w strefie
     * łowiska, nie na momentach — inaczej zmiana czasu przesuwałaby granicę o godzinę.
     *
     * Przedsprzedaż jest wyjątkiem WYŁĄCZNIE od horyzontu: otwarte okno wpuszcza doby
     * swojego okresu dalej niż horyzont, ale nie otwiera sprzedaży w blokadzie ani na
     * stanowisku wycofanym (to rozstrzygnął już punkt 1) i nie dotyczy dób innego okresu.
     *
     * @param  array<int, FishingDay>  $days
     */
    private function horizonRefusal(array $days): ?StaySellabilityVerdict
    {
        $horizon = $this->fishery->sale_horizon_days;

        if ($horizon === null) {
            return null;
        }

        $lastSellableDay = CarbonImmutable::now($this->timezone())->startOfDay()->addDays($horizon);

        foreach ($days as $day) {
            if ($day->startsOn <= $lastSellableDay) {
                continue;
            }

            $period = $this->periodFor($day);

            if ($period instanceof SalePeriod && $this->hasOpenPresale($period)) {
                continue;
            }

            return StaySellabilityVerdict::refusedDay(SaleUnavailabilityReason::BeyondSaleHorizon, $day);
        }

        return null;
    }

    /**
     * Odmowa z minimum przedsprzedaży — albo `null`.
     *
     * ⚠️ Minimum obowiązuje KAŻDY zakup dób okresu dokonany w oknie, **także dób
     * leżących w horyzoncie**. Przedsprzedaż jest ofertą hurtową, nie furtką na
     * pojedyncze doby: w otwartym oknie nie da się kupić jednej doby sezonu objętego
     * przedsprzedażą. Po zamknięciu okna — da się, o ile doba leży w horyzoncie.
     *
     * Flaga `presale_whole_terms_bypass_min_nights` (domyślnie włączona) zwalnia z tego
     * minimum pobyt obejmujący pakiet ze świętem. Weekend sam z siebie nigdy nie zwalnia.
     *
     * @param  array<int, FishingDay>  $days
     */
    private function presaleMinimumRefusal(
        array $days,
        int $nights,
        bool $coversWholeTerm,
    ): ?StaySellabilityVerdict {
        foreach ($this->openPresalePeriodsOf($days) as $period) {
            if ($period->presale_min_nights === null || $nights >= $period->presale_min_nights) {
                continue;
            }

            if ($coversWholeTerm && $period->presale_whole_terms_bypass_min_nights) {
                continue;
            }

            return StaySellabilityVerdict::refusedStay(SaleUnavailabilityReason::BelowPresaleMinimum);
        }

        return null;
    }

    /**
     * Okresy z OTWARTYM oknem przedsprzedaży, do których należy choć jedna doba pobytu.
     *
     * @param  array<int, FishingDay>  $days
     * @return array<int, SalePeriod>
     */
    private function openPresalePeriodsOf(array $days): array
    {
        $periods = [];

        foreach ($days as $day) {
            $period = $this->periodFor($day);

            if (! $period instanceof SalePeriod || ! $this->hasOpenPresale($period)) {
                continue;
            }

            $periods[$period->id] = $period;
        }

        return array_values($periods);
    }

    /**
     * Czy okno przedsprzedaży tego okresu jest otwarte W TEJ CHWILI.
     *
     * Okno obejmuje CAŁE dni brzegowe — od `presale_opens_on` od północy do
     * `presale_closes_on` do końca dnia w strefie łowiska, tak samo jak okno blokady
     * (`dostepnosc.md` §3). Porównywanym momentem jest chwila zakupu.
     */
    private function hasOpenPresale(SalePeriod $period): bool
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

    /**
     * Okres sprzedaży, w którym ZAWIERA się ta doba.
     *
     * ⚠️ Reguła zawierania, nie przecięcia — ta sama, którą stosuje
     * `FishingDayCalendar` przy sprzedaży doby, i stąd wynika, że doba na styku dwóch
     * sąsiadujących okresów nie należy do żadnego z nich (`dostepnosc.md` §1).
     */
    private function periodFor(FishingDay $day): ?SalePeriod
    {
        $timezone = $this->timezone();

        foreach ($this->salePeriods() as $period) {
            $windowStart = CarbonImmutable::parse($period->starts_on->toDateString(), $timezone)->startOfDay();
            $windowEnd = CarbonImmutable::parse($period->ends_on->toDateString(), $timezone)->endOfDay();

            if ($day->isContainedIn($windowStart, $windowEnd)) {
                return $period;
            }
        }

        return null;
    }

    /**
     * Czy doba rozpoczynająca się tego dnia jest spięta spoiwem I sprzedawalna.
     *
     * Dwie reguły powtarzania spoiwa, jedno pojęcie: dzień rozpoczęcia w `weekend_days`
     * (cyklicznie) albo doba objęta świętem datowanym (jednorazowo).
     */
    private function isGluedAndSellable(CarbonImmutable $date): bool
    {
        if (! $this->isGlued($date)) {
            return false;
        }

        return $this->isSellableNight($date);
    }

    private function isGlued(CarbonImmutable $date): bool
    {
        $weekendDays = $this->weekendDays();

        if ($weekendDays !== [] && in_array((int) $date->isoWeekday(), $weekendDays, true)) {
            return true;
        }

        return $this->wholeTermCovering($date) instanceof WholeTermPeriod;
    }

    /**
     * ⚠️ Nazwa celowo inna niż publiczne `isSellable()` — tamto pyta o POBYT
     * (data + liczba dób), to o JEDNĄ dobę. Wspólna nazwa była kolizją sygnatur.
     */
    private function isSellableNight(CarbonImmutable $date): bool
    {
        return $this->sellableByDate[$date->toDateString()] ??= $this->availability()->isSellable($date);
    }

    private function wholeTermCovering(CarbonImmutable $date): ?WholeTermPeriod
    {
        $dateString = $date->toDateString();

        foreach ($this->wholeTermPeriods() as $period) {
            if ($period->coversDayStartingOn($dateString)) {
                return $period;
            }
        }

        return null;
    }

    /**
     * Dni ISO-8601 rozpoczęcia dób składających się na weekend sprzedawany w całości.
     *
     * ⚠️ Wartości przechodzą przez `(int)`, bo kolumna JSON oddaje to, co w niej
     * zapisano — formularz Filamenta zapisuje tam łańcuchy, a `in_array()` w trybie
     * ścisłym nie dopasowałby `"5"` do `5`.
     *
     * @return array<int, int>
     */
    private function weekendDays(): array
    {
        $days = $this->fishery->weekend_days;

        if (! is_array($days)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $day): int => (int) $day, $days));
    }

    /**
     * @return array<int, WholeTermPeriod>
     */
    private function wholeTermPeriods(): array
    {
        return $this->wholeTermPeriods ??= $this->fishery->wholeTermPeriods()
            ->orderBy('first_day_on')
            ->get()
            ->all();
    }

    /**
     * @return array<int, SalePeriod>
     */
    private function salePeriods(): array
    {
        return $this->salePeriods ??= $this->fishery->salePeriods()
            ->orderBy('starts_on')
            ->get()
            ->all();
    }

    private function calendar(): FishingDayCalendar
    {
        return $this->calendar ??= new FishingDayCalendar($this->fishery);
    }

    private function availability(): PositionAvailability
    {
        return $this->availability ??= new PositionAvailability($this->position);
    }

    private function timezone(): string
    {
        return $this->fishery->timezone ?: 'Europe/Warsaw';
    }
}
