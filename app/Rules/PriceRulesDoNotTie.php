<?php

namespace App\Rules;

use App\Models\PriceRule;
use App\Services\PriceRuleOverlap;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Dwie stawki nierozróżnialne dla tej samej doby są **błędem konfiguracji**, nie okazją do
 * wybrania losowej ceny (G2, ADR-014).
 *
 * ⚠️ Nachodzenie się stawek jest **zamierzone** — „70 zł zawsze" koliduje z „90 zł w piątki"
 * w każdy piątek i tak właśnie operator chce to zapisać. Błędem jest wyłącznie remis
 * **nierozstrzygalny**: ten sam `priority` I ta sama szczegółowość, przy warunkach dających się
 * spełnić jednocześnie.
 *
 * ⚠️ Reguła siedzi na CAŁYM repeaterze stawek, nie na pojedynczym polu: remis jest własnością
 * ZBIORU, więc walidacja jednego wiersza nigdy by go nie zobaczyła (ta sama pułapka co
 * w `SalePeriodsDoNotOverlap`).
 *
 * ⚠️ Porównywane są **pary**, bez analizy przesłaniania: remis jest błędem także wtedy, gdy
 * trzecia, szczegółowsza reguła i tak wygrywa w całym przecięciu. Pełna analiza pokrycia jest
 * nieproporcjonalnie droga wobec obejścia, którym jest podniesienie priorytetu o jeden.
 *
 * Samo przecięcie warunków liczy `PriceRuleOverlap` — pyta o nie także ostrzeżenie o stawce
 * bez warunku roli, więc reguła ma jeden dom.
 */
class PriceRulesDoNotTie implements ValidationRule
{
    /**
     * @param  mixed  $value  lista reguł `rate`; każda z kluczami warunków
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        $rules = [];

        foreach ($value as $row) {
            if (is_array($row)) {
                // Hydratacja modelu, żeby szczegółowość liczyła się **jedną** metodą —
                // tą samą, której używa rozstrzyganie cennika.
                $rules[] = (new PriceRule)->forceFill($row);
            }
        }

        $overlap = new PriceRuleOverlap;

        foreach ($rules as $i => $rule) {
            foreach (array_slice($rules, $i + 1) as $other) {
                if ((int) $rule->priority !== (int) $other->priority) {
                    continue;
                }

                if ($rule->specificity() !== $other->specificity()) {
                    continue;
                }

                if ($overlap->canMatchSimultaneously($rule, $other)) {
                    $fail(__('Two rates with the same priority and the same number of conditions match the same night. Raise the priority of one of them.'));

                    return;
                }
            }
        }
    }
}
