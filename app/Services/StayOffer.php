<?php

namespace App\Services;

use App\Enums\SaleUnavailabilityReason;
use App\Models\Position;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Warstwa oferty pobytu — **jedyne wejście** dla kalendarza podglądowego (019), przyszłego
 * koszyka i portalu (ADR-015).
 *
 * Odpowiada na jedno pytanie: **czy ten pobyt jest sprzedawalny i wyceniony.**
 *
 * ⚠️ Od chwili, w której dziura w cenniku jest odmową, na pytanie „czy wolno to sprzedać"
 * odpowiadają **dwaj niezależni dostawcy**: reguły pobytu (`StaySellability`, ADR-013) i cennik
 * (`StayPricing`, ADR-014). Żaden z nich nie zna drugiego i **nie ma znać**. Gdyby składał ich
 * każdy pytający z osobna, powstałyby trzy implementacje składania — dokładnie to, czego
 * zabraniają ADR-012 i ADR-013, a rozjazd między nimi nie zapaliłby się nigdzie, bo każda
 * osobno „działa".
 *
 * ⚠️ **Kolejność jest ta sama co wszędzie: najpierw sprzedawalność, potem cena.** Pobyt
 * niesprzedawalny **nie jest wyceniany**, więc odmowa niesie przyczynę trwalszą („stanowisko
 * wycofane" przed „brak ceny"). U każdego wołającego z osobna byłaby to konwencja, którą trzeba
 * pamiętać — i którą pierwszy nowy ekran by złamał, bo odwrotna kolejność też „działa", tylko
 * zwraca gorszy komunikat.
 *
 * ⚠️ **Panel konfiguracyjny nadal woła to, co odpowiada na jego pytanie** — sam cennik albo samą
 * sprzedawalność. Ta klasa jest wejściem dla pytających o sprzedaż, nie obowiązkową bramą
 * do wszystkiego.
 *
 * ⚠️ **GRANICA NA PRZYSZŁOŚĆ: ta warstwa nie liczy niczego własnego.** Pierwsza reguła sprzedażowa
 * zapisana tutaj, a nie w `StaySellability` albo w cenniku, czyni z niej czwarte źródło prawdy —
 * i wtedy ADR-015 trzeba odwrócić, a nie rozszerzyć.
 */
final class StayOffer
{
    /**
     * Ile długości pobytu wolno sprawdzić, zanim uznamy dobę za niesprzedawalną.
     *
     * ⚠️ Bezpiecznik, nie reguła biznesowa. Realnie pętla kręci się tyle razy, ile wynosi
     * `min_nights` (formularz ogranicza je do 365), bo odmowa ze spoiwa nie iteruje — skacze
     * od razu na długość pakietu.
     */
    private const MAX_LENGTH_PROBES = 400;

    /**
     * ⚠️ Zależności budują się RAZ na instancję, bo warstwa konstruuje się **per stanowisko**,
     * tak jak `PositionAvailability` i `StaySellability`. Gdyby powstawały przy każdym wywołaniu,
     * memoizacja dopuszczona przez `dostepnosc.md` §2 (blokady i cennik wczytane raz na instancję)
     * przestałaby działać — a wtedy pomiar kosztu w 019 wyszedłby zły **z powodu kształtu
     * konstruktora, nie realnej ceny algorytmu**, i skłoniłby do bufora, którego może w ogóle
     * nie trzeba.
     */
    private ?StaySellability $sellability = null;

    private ?StayPricing $pricing = null;

    public function __construct(private readonly Position $position) {}

    /**
     * @param  int  $anglers  liczba łowiących
     * @param  int  $companions  liczba osób towarzyszących
     */
    public function offer(
        CarbonInterface|string $startsOn,
        int $nights,
        int $anglers = 1,
        int $companions = 0,
    ): StayOfferVerdict {
        // 1. Sprzedawalność — przyczyna trwalsza idzie pierwsza.
        $sellability = $this->sellability()->verdict($startsOn, $nights);

        if (! $sellability->sellable) {
            return StayOfferVerdict::notSellable($sellability);
        }

        // 2. Cena. Dopiero tutaj, i wyłącznie dla pobytu, który wolno sprzedać.
        $breakdown = $this->pricing()->breakdown($startsOn, $nights, $anglers, $companions);

        if (! $breakdown->isPriced()) {
            // ⚠️ Powód „brak ceny" nazywa TA warstwa, nie wycena — dzięki temu wycena zostaje
            // wolna od słownika odmów sprzedaży. Obie przyczyny niepowodzenia wyceny (brak
            // stawki i remis nierozstrzygalny) dają tę samą odmowę dla wędkarza; różnicę widzi
            // operator w rozbiciu i w ostrzeżeniach cennika.
            return StayOfferVerdict::notPriced($breakdown);
        }

        return StayOfferVerdict::offered($breakdown);
    }

    public function isAvailable(
        CarbonInterface|string $startsOn,
        int $nights,
        int $anglers = 1,
        int $companions = 0,
    ): bool {
        return $this->offer($startsOn, $nights, $anglers, $companions)->available;
    }

    /**
     * Najkrótszy KUPOWALNY pobyt rozpoczynający się tą dobą — druga odpowiedź tej warstwy,
     * potrzebna kalendarzowi (019) do widoku „ceny od".
     *
     * ⚠️ **Metoda SZUKA, ale nie ROZSTRZYGA.** Nie czyta `min_nights`, `max_nights` ani
     * `presale_min_nights` i nie wie nic o spoiwie — pyta o każdą długość `StaySellability`
     * i czyta z odmowy, co robić dalej. Dzięki temu zwolnienie świąteczne z minimum (reguła 7),
     * minimum przedsprzedaży i przycinanie pakietu działają tu **same z siebie**, bo obsługuje
     * je warstwa niżej. Gdyby te progi odczytać tutaj, warstwa oferty stałaby się czwartym
     * źródłem prawdy — czyli dokładnie tym, czego zakazuje ADR-015.
     *
     * ⚠️ **Odmowa ze spoiwa niesie zakres pakietu, więc nie trzeba zgadywać po jednej dobie.**
     * Gdy pakiet zaczyna się PRZED pytaną dobą — pobytu nie da się tu zacząć. Gdy zaczyna się
     * w niej, skaczemy od razu do długości pokrywającej cały pakiet, zamiast dochodzić do niej
     * po jednej dobie.
     */
    public function shortestOffer(
        CarbonInterface|string $startsOn,
        int $anglers = 1,
        int $companions = 0,
    ): ShortestStayVerdict {
        $nights = 1;

        for ($attempt = 0; $attempt < self::MAX_LENGTH_PROBES; $attempt++) {
            $verdict = $this->offer($startsOn, $nights, $anglers, $companions);

            if ($verdict->available) {
                return ShortestStayVerdict::found($nights, $verdict->breakdown);
            }

            $sellability = $verdict->sellability;

            // Odmowa ze spoiwa: albo „zacznij wcześniej", albo skok na długość pakietu.
            if ($sellability?->bundleFirstDay !== null && $sellability->bundleLastDay !== null) {
                $first = CarbonImmutable::parse($startsOn, $sellability->bundleFirstDay->timezone)->startOfDay();

                if ($sellability->bundleFirstDay->lt($first)) {
                    return ShortestStayVerdict::startsEarlier($verdict->reason, $sellability->bundleFirstDay);
                }

                $nights = max($nights + 1, (int) $first->diffInDays($sellability->bundleLastDay) + 1);

                continue;
            }

            // Wyłącznie „za krótki" i „poniżej minimum przedsprzedaży" da się naprawić
            // wydłużeniem. Każdy inny powód — za długi, doba niesprzedawalna, poza horyzontem,
            // brak ceny — przy dłuższym pobycie może się tylko pogorszyć.
            if ($verdict->reason !== SaleUnavailabilityReason::StayTooShort
                && $verdict->reason !== SaleUnavailabilityReason::BelowPresaleMinimum) {
                return ShortestStayVerdict::none($verdict->reason);
            }

            $nights++;
        }

        return ShortestStayVerdict::none(SaleUnavailabilityReason::StayTooLong);
    }

    private function sellability(): StaySellability
    {
        return $this->sellability ??= new StaySellability($this->position);
    }

    private function pricing(): StayPricing
    {
        return $this->pricing ??= new StayPricing($this->position);
    }
}
