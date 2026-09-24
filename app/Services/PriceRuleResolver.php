<?php

namespace App\Services;

use App\Enums\PriceRuleKind;
use App\Enums\PricingFailure;
use App\Models\PriceRule;
use Carbon\CarbonImmutable;

/**
 * JEDYNE miejsce, które wybiera stawkę spośród reguł cennika (ADR-014).
 *
 * ⚠️ **Nachodzenie się stawek jest stanem ZAMIERZONYM, nie błędem** — i po przedefiniowaniu
 * z 22.09.2026 rozstrzyga się je **na korzyść wędkarza**, czyli po najniższej kwocie.
 * Priorytet, szczegółowość osiami i remis blokujący zapis zostały wycofane: cennik obu
 * znanych łowisk to jedna stawka bazowa plus warunkowa dopłata, więc cała ta maszyneria
 * nie obsługiwała żadnego realnego przypadku.
 *
 * ⚠️ **Kolejność rozstrzygania jest umową, nie szczegółem implementacji:**
 *
 *   1. odrzuć reguły zawieszone (robią to `coversDay()` / `appliesToNight()`),
 *   2. odrzuć reguły, których warunek nie jest spełniony **dla tej doby** — stawka zna
 *      wyłącznie daty, dopłata także dni tygodnia i obsadę,
 *   3. spośród `rate` wygrywa **najniższa kwota za osobę łowiącą**,
 *   4. wszystkie pasujące `surcharge` **sumują się** (K1a) — przy kwotach wynik nie zależy
 *      od kolejności, więc nie ma tu czego rozstrzygać.
 *
 * ⚠️ **Reguły miękko usunięte nie wracają do wyceny.** Załatwia to globalny zakres
 * `SoftDeletes` na zapytaniu, ale warto to wiedzieć przy wyborze „najniższa kwota": cena
 * z kosza byłaby KORZYSTNIEJSZA, więc wygrałaby i nie rzucałaby się w oczy.
 *
 * ⚠️ Klasa nie zna pojęcia sprzedawalności i nie ma go poznać. Składaniem obu odpowiedzi
 * zajmuje się warstwa oferty (ADR-015).
 */
final class PriceRuleResolver
{
    /** @var array<int, PriceRule> */
    private readonly array $rates;

    /** @var array<int, PriceRule> */
    private readonly array $surcharges;

    /**
     * @param  array<int, PriceRule>  $rules  cennik łowiska, dowolnego rodzaju
     */
    public function __construct(array $rules)
    {
        $rates = [];
        $surcharges = [];

        foreach ($rules as $rule) {
            if ($rule->kind === PriceRuleKind::Rate) {
                $rates[] = $rule;
            } else {
                $surcharges[] = $rule;
            }
        }

        $this->rates = $rates;
        $this->surcharges = $surcharges;
    }

    /**
     * ⚠️ Zapytanie NIE bierze roli uczestnika — stawka jej nie zna, a dopłata rozdziela się
     * po rolach dopiero w wycenie, przez `applies_to`.
     */
    public function resolve(FishingDay $night, int $anglersCount): NightPriceResolution
    {
        $candidates = $this->candidatesFor($night);

        if ($candidates === []) {
            return NightPriceResolution::failed(PricingFailure::NoMatchingRate);
        }

        $surcharges = array_values(array_filter(
            $this->surcharges,
            static fn (PriceRule $rule): bool => $rule->appliesToNight($night, $anglersCount),
        ));

        return NightPriceResolution::resolved($candidates[0], $candidates, $surcharges);
    }

    /**
     * Wszystkie stawki pasujące do tej doby, w kolejności rozstrzygania — zwycięzca pierwszy.
     *
     * ⚠️ **Zależy WYŁĄCZNIE od daty.** Po uproszczeniu stawka nie zna ani stanowiska, ani
     * składu uczestników, więc kalendarz (019) pobiera tę listę raz na łowisko i dobę,
     * a nie raz na komórkę siatki. Przy Klasztornym to różnica między trzydziestoma
     * a ośmiuset wywołaniami.
     *
     * @return array<int, PriceRule>
     */
    public function candidatesFor(FishingDay $night): array
    {
        return $this->candidatesForDay($night->startsOn);
    }

    /**
     * To samo, ale pytane samą datą — dla analizy przedziałów, która nie chodzi po kalendarzu.
     *
     * @return array<int, PriceRule>
     */
    public function candidatesForDay(CarbonImmutable $day): array
    {
        $candidates = array_values(array_filter(
            $this->rates,
            static fn (PriceRule $rule): bool => $rule->coversDay($day),
        ));

        usort($candidates, $this->compare(...));

        return $candidates;
    }

    /** Stawka, która wygrałaby tego dnia — albo `null`, gdy żadna go nie pokrywa. */
    public function cheapestOn(CarbonImmutable $day): ?PriceRule
    {
        return $this->candidatesForDay($day)[0] ?? null;
    }

    /**
     * Ujemne, gdy `$a` wygrywa. Kolejność: najniższa kwota za łowiącego → najniższa kwota
     * za towarzyszącą → mniejsze `id`.
     *
     * ⚠️ **Stawka bez ceny za osobę towarzyszącą przegrywa remis ZAWSZE** — sortuje się na
     * koniec, żeby przy równych kwotach wybór nie padł na regułę, która odmówi wyceny osobie
     * towarzyszącej, gdy obok stoi równoważna stawka z wypełnioną kwotą.
     *
     * ⚠️ Rozstrzygnięcie po `id` nie zmienia KWOTY — wtedy jest już identyczna. Chodzi
     * wyłącznie o to, żeby rozbicie wskazywało zawsze tę samą regułę.
     */
    private function compare(PriceRule $a, PriceRule $b): int
    {
        if ($a->amountInCents() !== $b->amountInCents()) {
            return $a->amountInCents() <=> $b->amountInCents();
        }

        $companionA = $a->companionAmountInCents() ?? PHP_INT_MAX;
        $companionB = $b->companionAmountInCents() ?? PHP_INT_MAX;

        if ($companionA !== $companionB) {
            return $companionA <=> $companionB;
        }

        return (int) $a->id <=> (int) $b->id;
    }
}
