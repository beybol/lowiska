<?php

namespace App\Services;

use App\Enums\ParticipantRole;
use App\Enums\PriceRuleKind;
use App\Enums\PricingFailure;
use App\Models\Fishery;
use App\Models\Position;
use App\Models\PriceRule;
use App\Models\SalePeriod;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * JEDYNE miejsce odpowiadające na pytanie „ile kosztuje ten pobyt i z czego się to składa"
 * (ADR-014).
 *
 * ⚠️ **Wycena NIE pyta o sprzedawalność, a sprzedawalność nie pyta o cenę.** Ta klasa nie woła
 * `StaySellability` i nie powtarza żadnego z jej warunków; składaniem obu odpowiedzi zajmuje się
 * **warstwa oferty** (`StayOffer`, ADR-015). Zmiana reguł pobytu z 017 nie zmienia wyniku wyceny —
 * i to jest sprawdzane testem, bo jest to granica, a nie przypadek.
 *
 * ⚠️ **Uczestnik jest PARAMETREM ZAPYTANIA, nie rekordem.** Pytamy „ilu łowiących, ile osób
 * towarzyszących", bo modelu rezerwacji i uczestników nie ma w schemacie (G1, poza iteracją).
 *
 * ⚠️ **Kwoty liczą się w groszach, w liczbach całkowitych.** Obniżka przedsprzedażowa zaokrągla
 * się raz na dobę i musi odróżnić 14,01 zł od 14,02 zł.
 *
 * ⚠️ **Brak ceny wraca WYNIKIEM, nie wyjątkiem** — żeby jedna dziura w cenniku nie wywróciła
 * całego widoku kalendarza (019). Dwie odmowy są rozróżnialne: `NoMatchingRate` znaczy „dopisz
 * stawkę", `NoCompanionPrice` — „popraw pole w stawce, która już jest".
 *
 * ⚠️ **Skład wymaga co najmniej jednego łowiącego.** Bez tej bramki zapytanie „0 łowiących,
 * 1 osoba towarzysząca" przeszłoby przez cały model i dało ofertę za 0,00 zł: warunek obsady
 * by nie wszedł, a stawka wniosłaby kwotę towarzyszącej. To niepoprawne wejście, nie odmowa
 * biznesowa — stąd wyjątek, a nie `SaleUnavailabilityReason`.
 */
final class StayPricing
{
    private readonly Fishery $fishery;

    private ?FishingDayCalendar $calendar = null;

    private ?PriceRuleResolver $resolver = null;

    private ?SalePeriodFinder $periods = null;

    /**
     * @param  array<int, PriceRule>|null  $rules  cennik łowiska wczytany przez wołającego;
     *                                             `null` = wczytanie przy pierwszej wycenie.
     *                                             ⚠️ To jedno wczytanie na żądanie, nie bufor
     *                                             werdyktu (`dostepnosc.md` §2).
     */
    public function __construct(
        private readonly Position $position,
        private readonly ?array $rules = null,
    ) {
        // ⚠️ Jawny wyjątek, nie `assert()` — tak samo jak w `PositionAvailability`
        // i `StaySellability`, bo asercje są wyłączone w obrazie produkcyjnym.
        if ($position->fishery === null) {
            throw new InvalidArgumentException(
                "Position {$position->id} has no fishery, so its price is undefined."
            );
        }

        $this->fishery = $position->fishery;
    }

    /**
     * Rozbicie ceny pobytu.
     *
     * ⚠️ Brak skonfigurowanej doby jest **błędem wywołania**, nie odmową wyceny: bez godzin doby
     * nie istnieje nic, co dałoby się wycenić. O tym, czy łowisko w ogóle sprzedaje, odpowiada
     * warstwa dostępności — i warstwa oferty pyta ją PIERWSZĄ, więc tą ścieżką wycena nigdy tu
     * nie dojdzie.
     *
     * @param  int  $anglers  liczba łowiących — to ona jest porównywana z warunkiem `anglers_count`
     * @param  int  $companions  liczba osób towarzyszących
     */
    public function breakdown(
        CarbonInterface|string $startsOn,
        int $nights,
        int $anglers = 1,
        int $companions = 0,
    ): StayPriceBreakdown {
        if ($nights < 1) {
            throw new InvalidArgumentException("A stay must last at least one night, {$nights} given.");
        }

        if ($anglers < 1) {
            throw new InvalidArgumentException("A stay must have at least one angler, {$anglers} given.");
        }

        if ($companions < 0) {
            throw new InvalidArgumentException('A stay can not have a negative number of companions.');
        }

        $timezone = $this->fishery->timezoneName();
        $firstDate = CarbonImmutable::parse($startsOn, $timezone)->startOfDay();
        $pricedNights = [];

        for ($offset = 0; $offset < $nights; $offset++) {
            $night = $this->calendar()->dayStartingOn($firstDate->addDays($offset));

            if (! $night instanceof FishingDay) {
                throw new InvalidArgumentException(
                    "Fishery {$this->fishery->id} has no fishing day configured, so nothing can be priced."
                );
            }

            // ⚠️ Rozstrzygnięcie zapada RAZ na dobę, nie raz na rolę: stawka nie zna roli,
            // a obsadą porównywaną z warunkiem dopłaty jest zawsze liczba ŁOWIĄCYCH — dopłata
            // „obsada 1" należy się przy jednym łowiącym niezależnie od tego, ile osób mu
            // towarzyszy.
            $resolution = $this->resolver()->resolve($night, $anglers);

            if (! $resolution->isResolved()) {
                return StayPriceBreakdown::failed(
                    $resolution->failure,
                    $night->startsOn,
                    ParticipantRole::Angler,
                    $anglers,
                );
            }

            $companionAmount = $resolution->companionAmountInCents();

            // ⚠️ Brak kwoty za osobę towarzyszącą to BRAK CENY, nie cena zerowa — i ma własny
            // powód odmowy, bo prowadzi operatora gdzie indziej niż dziura w cenniku: tam
            // trzeba dopisać stawkę, tu poprawić jedno pole w stawce, która już jest.
            if ($companions > 0 && $companionAmount === null) {
                return StayPriceBreakdown::failed(
                    PricingFailure::NoCompanionPrice,
                    $night->startsOn,
                    ParticipantRole::Companion,
                    $anglers,
                );
            }

            $items = [
                new StayPriceItem(
                    night: $night->startsOn,
                    role: ParticipantRole::Angler,
                    kind: PriceRuleKind::Rate,
                    label: $resolution->rate->label,
                    people: $anglers,
                    amountPerPersonInCents: $resolution->anglerAmountInCents(),
                    priceRuleId: $resolution->rate->id,
                ),
            ];

            if ($companions > 0) {
                $items[] = new StayPriceItem(
                    night: $night->startsOn,
                    role: ParticipantRole::Companion,
                    kind: PriceRuleKind::Rate,
                    label: $resolution->rate->label,
                    people: $companions,
                    amountPerPersonInCents: (int) $companionAmount,
                    priceRuleId: $resolution->rate->id,
                );
            }

            foreach ($resolution->surcharges as $surcharge) {
                // ⚠️ `applies_to` decyduje, PRZEZ ILU osób mnoży się dopłata — nie czy wchodzi.
                // Rozbijamy ją na pozycje per rola, żeby wędkarz widział w rozbiciu, kto płaci:
                // „dla każdego" daje dwie pozycje, pozostałe warianty po jednej.
                foreach ([[ParticipantRole::Angler, $anglers], [ParticipantRole::Companion, $companions]] as [$role, $people]) {
                    $charged = $surcharge->chargeableHeadcount(
                        $role === ParticipantRole::Angler ? $people : 0,
                        $role === ParticipantRole::Companion ? $people : 0,
                    );

                    if ($charged < 1) {
                        continue;
                    }

                    $items[] = new StayPriceItem(
                        night: $night->startsOn,
                        role: $role,
                        kind: PriceRuleKind::Surcharge,
                        label: $surcharge->label,
                        people: $charged,
                        amountPerPersonInCents: $surcharge->amountInCents(),
                        priceRuleId: $surcharge->id,
                    );
                }
            }

            $pricedNights[] = $this->withPresaleDiscount($night, $items);
        }

        return StayPriceBreakdown::priced($pricedNights);
    }

    /**
     * Dokłada obniżkę przedsprzedażową tej doby — o ile okno okresu, do którego doba należy,
     * jest właśnie otwarte.
     *
     * @param  array<int, StayPriceItem>  $items
     */
    private function withPresaleDiscount(FishingDay $night, array $items): StayNightPrice
    {
        $period = $this->periods()->forNight($night);

        if (! $period instanceof SalePeriod
            || $period->presale_discount_percent === null
            || ! $this->periods()->hasOpenPresale($period)) {
            return new StayNightPrice($night->startsOn, $items);
        }

        $subtotal = 0;

        foreach ($items as $item) {
            $subtotal += $item->amountInCents();
        }

        return new StayNightPrice(
            night: $night->startsOn,
            items: $items,
            discountInCents: self::discountInCents($subtotal, (string) $period->presale_discount_percent),
            discountPercent: (string) $period->presale_discount_percent,
        );
    }

    /**
     * Obniżka od podstawy, zaokrąglona do pełnego grosza, **połówki w górę**.
     *
     * ⚠️ Arytmetyka całkowita, nie `round()` na `float`. Przypadek rozstrzygający: podstawa
     * 140,10 zł przy 10% daje **14,01 zł**, a liczenie osobno na dwie osoby po 70,05 zł dałoby
     * 7,005 → 7,01 i razem 14,02 zł. Różnica grosza jest tu jedyną rzeczą, którą test może
     * odróżnić, więc nie wolno jej zgubić w zmiennym przecinku.
     */
    public static function discountInCents(int $subtotalInCents, string $percent): int
    {
        // Procent z dwoma miejscami po przecinku jako liczba całkowita setnych części procenta.
        $hundredths = (int) round(((float) $percent) * 100);

        if ($hundredths <= 0 || $subtotalInCents <= 0) {
            return 0;
        }

        // subtotal * hundredths / 10000, zaokrąglone połówkami w górę.
        return intdiv(2 * $subtotalInCents * $hundredths + 10000, 20000);
    }

    private function calendar(): FishingDayCalendar
    {
        return $this->calendar ??= new FishingDayCalendar($this->fishery);
    }

    private function periods(): SalePeriodFinder
    {
        return $this->periods ??= new SalePeriodFinder($this->fishery);
    }

    /**
     * Cennik wczytany RAZ na instancję.
     *
     * ⚠️ **Nie ma już wymiaru „obowiązywania zapisu"** (`effective_*`): reguła ma jeden przedział
     * dat i mówi on, których DÓB dotyczy. Różnica między „kiedy zapis działa" a „których dób
     * dotyczy" jest obserwowalna dopiero przy utrwalonej transakcji pamiętającej cenę z chwili
     * zakupu, a ta należy do snapshotu G1 (ADR-014, sekcja „Aktualizacja").
     *
     * ⚠️ Brak `orderBy` jest celowy — kolejność rozstrzygania ustala `PriceRuleResolver`, żeby
     * nie dało się jej zmienić przypadkiem z poziomu zapytania.
     */
    private function resolver(): PriceRuleResolver
    {
        if ($this->resolver instanceof PriceRuleResolver) {
            return $this->resolver;
        }

        /** @var array<int, PriceRule> $rules */
        $rules = $this->rules ?? $this->fishery->priceRules()->get()->all();

        return $this->resolver = new PriceRuleResolver($rules);
    }
}
