<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Okresy sprzedaży jednego łowiska nie mogą na siebie zachodzić, a żaden z nich
 * nie może kończyć się przed swoim początkiem.
 *
 * ⚠️ Reguła mieszka tutaj, a nie w formularzu strony ustawień, bo formularz jest
 * tylko jedną ze ścieżek zapisu — import, seed i przyszłe API obeszłyby regułę
 * wpisaną w komponent (`CLAUDE.md`, „logika walidacyjna ma jeden dom").
 *
 * Sprawdzenie dotyczy WYŁĄCZNIE przekazanego zbioru. Okresy usunięte miękko do
 * niego nie trafiają, i to jest zamierzone: wycofany sezon nie może blokować
 * założenia nowego na te same daty (zadanie 015, „Rozstrzygnięcia").
 */
class SalePeriodsDoNotOverlap implements ValidationRule
{
    /**
     * @param  mixed  $value  lista okresów; każdy z kluczami `starts_on` i `ends_on`
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        $periods = [];

        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }

            $startsOn = $item['starts_on'] ?? null;
            $endsOn = $item['ends_on'] ?? null;

            if (blank($startsOn) || blank($endsOn)) {
                continue;
            }

            $startsOn = substr((string) $startsOn, 0, 10);
            $endsOn = substr((string) $endsOn, 0, 10);

            if ($endsOn < $startsOn) {
                $fail(__('A sale period can not end before it starts.'));

                return;
            }

            $periods[] = [$startsOn, $endsOn];
        }

        // Porównanie każdego z każdym: zbiór jest z natury krótki (sezony jednego
        // łowiska w roku), więc sortowanie i tak nie zmieniłoby rzędu wielkości,
        // a pętla po parach czyta się wprost jak reguła.
        foreach ($periods as $i => [$startsOn, $endsOn]) {
            foreach (array_slice($periods, $i + 1) as [$otherStartsOn, $otherEndsOn]) {
                if ($startsOn <= $otherEndsOn && $otherStartsOn <= $endsOn) {
                    $fail(__('Sale periods of one fishery can not overlap.'));

                    return;
                }
            }
        }
    }
}
