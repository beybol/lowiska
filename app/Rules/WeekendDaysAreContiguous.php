<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Zbiór dób składających się na weekend sprzedawany w całości musi być ciągiem
 * co najmniej dwóch, ale nie wszystkich siedmiu dób.
 *
 * ⚠️ **To są DOBY, nie dni.** Wartości to dni ISO-8601 (1 = poniedziałek … 7 = niedziela)
 * ROZPOCZĘCIA dób. Weekend „od piątku 15:00 do niedzieli 15:00" to `{5, 6}` — doby pt→sob
 * i sob→nd — a nie `{5, 6, 7}`. Doba `nd 15:00 → pon 15:00` leży poza weekendem
 * (zadanie 017, rozstrzygnięcie 13, potwierdzone przez łowisko).
 *
 * ⚠️ Reguła siedzi na CAŁYM polu zbioru, nie na pojedynczej opcji: ciągłość i liczność
 * są własnościami zbioru, więc walidacja jednej opcji nigdy by ich nie zobaczyła.
 * Mieszka tutaj, a nie w formularzu strony, bo formularz jest tylko jedną ze ścieżek
 * zapisu — import, seed i przyszłe API obeszłyby regułę wpisaną w komponent
 * (`CLAUDE.md`, „logika walidacyjna ma jeden dom").
 */
class WeekendDaysAreContiguous implements ValidationRule
{
    /**
     * @param  mixed  $value  zbiór dni ISO-8601; puste znaczy „brak reguły weekendu"
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        if (! is_array($value)) {
            $fail(__('The weekend has to be given as a set of nights.'));

            return;
        }

        $days = array_values(array_unique(array_map(
            static fn (mixed $day): int => (int) $day,
            $value,
        )));

        foreach ($days as $day) {
            if ($day < 1 || $day > 7) {
                $fail(__('A weekend night has to be a day of the week.'));

                return;
            }
        }

        // Pakiet jednodobowy nic nie znaczy — „w całości" jest wtedy puste.
        if (count($days) < 2) {
            $fail(__('A weekend sold whole has to cover at least two nights.'));

            return;
        }

        // Wszystkie siedem dób to pakiet nieskończony: każda doba byłaby spięta
        // z następną, więc nie dałoby się kupić niczego krótszego niż cały rok.
        if (count($days) > 6) {
            $fail(__('A weekend can not cover every night of the week.'));

            return;
        }

        if (! $this->isCyclicallyContiguous($days)) {
            $fail(__('The nights of a weekend have to follow one another.'));
        }
    }

    /**
     * Czy doby następują po sobie, licząc CYKLICZNIE.
     *
     * ⚠️ Cyklicznie, bo tydzień się zawija: `{7, 1}` (doby nd→pon i pon→wt) jest
     * poprawnym ciągiem, mimo że 7 i 1 nie sąsiadują liczbowo. Zbiór jest z natury
     * krótki, więc siedem obrotów pętli jest tańsze niż jakakolwiek sztuczka.
     *
     * @param  array<int, int>  $days
     */
    private function isCyclicallyContiguous(array $days): bool
    {
        sort($days);
        $count = count($days);

        // Każdy możliwy początek ciągu: jeśli od któregokolwiek dnia da się przejść
        // przez cały zbiór krokiem o jeden (mod 7), zbiór jest ciągiem.
        foreach ($days as $start) {
            $expected = $start;
            $matched = 0;

            for ($step = 0; $step < $count; $step++) {
                if (! in_array($expected, $days, true)) {
                    break;
                }

                $matched++;
                $expected = $expected === 7 ? 1 : $expected + 1;
            }

            if ($matched === $count) {
                return true;
            }
        }

        return false;
    }
}
