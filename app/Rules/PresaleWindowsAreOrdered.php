<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Okno przedsprzedaży nie może zamykać się przed swoim otwarciem.
 *
 * ⚠️ Reguła siedzi na CAŁYM repeaterze okresów, tak samo jak `SalePeriodsDoNotOverlap`,
 * i z tego samego powodu: repeater jest jednym polem formularza, a walidacja pojedynczego
 * wiersza nie zobaczyłaby pozostałych. Przy okazji jedno miejsce sprawdza wszystkie okna,
 * więc operator dostaje pierwszy błąd niezależnie od tego, który wiersz go niesie.
 *
 * Przedsprzedaż jest WŁĄCZONA, gdy obie daty są wypełnione — wiersz z jedną datą jest
 * niedokończony, nie błędny, więc reguła go pomija (czyszczeniem zajmuje się przełącznik
 * w formularzu). Mieszka tutaj, bo formularz jest jedną ze ścieżek zapisu
 * (`CLAUDE.md`, „logika walidacyjna ma jeden dom").
 */
class PresaleWindowsAreOrdered implements ValidationRule
{
    /**
     * @param  mixed  $value  lista okresów; każdy może mieć `presale_opens_on`
     *                        i `presale_closes_on`
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }

            $opensOn = $item['presale_opens_on'] ?? null;
            $closesOn = $item['presale_closes_on'] ?? null;

            if (blank($opensOn) || blank($closesOn)) {
                continue;
            }

            if (substr((string) $closesOn, 0, 10) < substr((string) $opensOn, 0, 10)) {
                $fail(__('A presale window can not close before it opens.'));

                return;
            }
        }
    }
}
