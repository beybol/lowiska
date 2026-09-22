<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Obniżka przedsprzedażowa jest procentem: od 0 do 100.
 *
 * ⚠️ Reguła siedzi na całym repeaterze okresów sprzedaży, tak samo jak
 * `SalePeriodsDoNotOverlap` i `PresaleWindowsAreOrdered`, i z tego samego powodu: repeater jest
 * jednym polem formularza. Mieszka w `app/Rules/`, a nie w komponencie, bo formularz jest jedną
 * ze ścieżek zapisu — import, seed i przyszłe API obeszłyby ograniczenie wpisane w pole
 * (`CLAUDE.md`, „logika walidacyjna ma jeden dom").
 *
 * Pusta wartość znaczy „bez obniżki" i jest poprawna.
 */
class PresaleDiscountIsPercentage implements ValidationRule
{
    /**
     * @param  mixed  $value  lista okresów; każdy może mieć `presale_discount_percent`
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

            $percent = $row['presale_discount_percent'] ?? null;

            if (blank($percent)) {
                continue;
            }

            if (! is_numeric($percent)) {
                $fail(__('A presale discount has to be a percentage between 0 and 100.'));

                return;
            }

            $percent = (float) $percent;

            if ($percent < 0 || $percent > 100) {
                $fail(__('A presale discount has to be a percentage between 0 and 100.'));

                return;
            }
        }
    }
}
