<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Żadna z dwóch par dat reguły cenowej nie może kończyć się przed swoim początkiem.
 *
 * ⚠️ **To są DWA różne wymiary czasu i sprawdza się je osobno**, mimo że oba wyglądają jak
 * „zakres dat" (ADR-014):
 * - `effective_from`/`effective_to` — czy ten ZAPIS bierze dziś udział w wycenie;
 * - `first_day_on`/`last_day_on` — których DÓB reguła dotyczy.
 *
 * Reguła siedzi na całym repeaterze, jak `SalePeriodsDoNotOverlap` i `PresaleWindowsAreOrdered`:
 * repeater jest jednym polem formularza, a walidacja pojedynczego wiersza nie widzi pozostałych.
 * Mieszka tutaj, a nie w formularzu, bo formularz jest jedną ze ścieżek zapisu.
 */
class PriceRuleDatesAreOrdered implements ValidationRule
{
    /**
     * @param  mixed  $value  lista reguł cenowych
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $row) {
            if (! is_array($row)) {
                continue;
            }

            if ($this->endsBeforeItStarts($row['effective_from'] ?? null, $row['effective_to'] ?? null)) {
                $fail(__('A price rule can not stop being effective before it starts.'));

                return;
            }

            if ($this->endsBeforeItStarts($row['first_day_on'] ?? null, $row['last_day_on'] ?? null)) {
                $fail(__('The last night of a price rule can not come before its first night.'));

                return;
            }
        }
    }

    /**
     * Para z jednym pustym końcem jest poprawna — otwarty koniec znaczy „bez granicy".
     */
    private function endsBeforeItStarts(mixed $from, mixed $to): bool
    {
        if (blank($from) || blank($to)) {
            return false;
        }

        return substr((string) $to, 0, 10) < substr((string) $from, 0, 10);
    }
}
