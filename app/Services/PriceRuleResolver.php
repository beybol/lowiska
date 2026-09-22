<?php

namespace App\Services;

use App\Enums\ParticipantRole;
use App\Enums\PriceRuleKind;
use App\Enums\PricingFailure;
use App\Models\PriceRule;
use Carbon\CarbonImmutable;

/**
 * JEDYNE miejsce, które wybiera stawkę spośród reguł cennika (ADR-014).
 *
 * ⚠️ **Nachodzenie się reguł jest stanem ZAMIERZONYM, nie błędem.** „70 zł zawsze" koliduje
 * z „90 zł w piątki" w każdy piątek — i tak właśnie operator chce to zapisać. Dlatego istnieje
 * reguła wyboru, a nie zakaz nachodzenia.
 *
 * ⚠️ **Kolejność rozstrzygania jest umową, nie szczegółem implementacji:**
 *
 *   1. odrzuć reguły zawieszone i spoza `effective_*` (wobec DZISIEJSZEJ daty),
 *   2. odrzuć reguły, których warunek nie jest spełniony **dla tej doby**,
 *   3. spośród `rate` wygrywa najwyższy `priority`; przy remisie wyższa **szczegółowość**;
 *      przy remisie nierozstrzygalnym — **błąd konfiguracji**, nigdy losowa cena (G2),
 *   4. wszystkie pasujące `surcharge` **sumują się** (K1a) — przy kwotach wynik nie zależy
 *      od kolejności, więc nie ma tu czego rozstrzygać.
 *
 * ⚠️ Remis **nie leci wyjątkiem** — wraca jako wynik, żeby jedna zła para reguł nie wywróciła
 * całego widoku kalendarza (019).
 *
 * ⚠️ Klasa nie zna pojęcia sprzedawalności i nie ma go poznać. Składaniem obu odpowiedzi
 * zajmuje się warstwa oferty (ADR-015).
 */
final class PriceRuleResolver
{
    /** @var array<int, PriceRule> */
    private readonly array $effectiveRules;

    /**
     * @param  array<int, PriceRule>  $rules  cennik łowiska, dowolnego rodzaju
     * @param  CarbonImmutable  $today  dzisiejsza data w strefie łowiska — wymiar `effective_*`
     */
    public function __construct(array $rules, CarbonImmutable $today)
    {
        // Krok 1 wykonuje się RAZ na instancję: `effective_*` i zawieszenie nie zależą
        // od wycenianej doby, więc filtrowanie ich przy każdej dobie byłoby powtarzaniem
        // tej samej odpowiedzi.
        $this->effectiveRules = array_values(array_filter(
            $rules,
            static fn (PriceRule $rule): bool => $rule->isEffectiveOn($today),
        ));
    }

    public function resolve(FishingDay $night, ParticipantRole $role, int $anglersCount): NightPriceResolution
    {
        $rates = [];
        $surcharges = [];

        foreach ($this->effectiveRules as $rule) {
            if (! $rule->matches($night, $role, $anglersCount)) {
                continue;
            }

            if ($rule->kind === PriceRuleKind::Rate) {
                $rates[] = $rule;
            } else {
                $surcharges[] = $rule;
            }
        }

        if ($rates === []) {
            return NightPriceResolution::failed(PricingFailure::NoMatchingRate);
        }

        $winner = $this->pickWinner($rates);

        if (! $winner instanceof PriceRule) {
            return NightPriceResolution::failed(PricingFailure::UnresolvableTie);
        }

        return NightPriceResolution::resolved($winner, $surcharges);
    }

    /**
     * Zwycięska stawka albo `null`, gdy dwie są nierozróżnialne.
     *
     * @param  array<int, PriceRule>  $rates
     */
    private function pickWinner(array $rates): ?PriceRule
    {
        $best = null;
        $tied = false;

        foreach ($rates as $rule) {
            if ($best === null) {
                $best = $rule;

                continue;
            }

            $comparison = $this->compare($rule, $best);

            if ($comparison > 0) {
                $best = $rule;
                $tied = false;
            } elseif ($comparison === 0) {
                $tied = true;
            }
        }

        return $tied ? null : $best;
    }

    /**
     * Dodatnie, gdy `$a` wygrywa; zero przy nierozróżnialności.
     *
     * ⚠️ Priorytet PRZED szczegółowością, a nie odwrotnie: priorytet jest jedynym narzędziem,
     * którym operator wyraża własną intencję wprost, więc musi wygrywać z regułą wyprowadzoną
     * z kształtu warunków.
     */
    private function compare(PriceRule $a, PriceRule $b): int
    {
        if ((int) $a->priority !== (int) $b->priority) {
            return (int) $a->priority <=> (int) $b->priority;
        }

        return $a->specificity() <=> $b->specificity();
    }
}
