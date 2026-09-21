<?php

namespace App\Rules;

use App\Models\Fishery;
use App\Services\FishingDay;
use App\Services\FishingDayCalendar;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Święto sprzedawane w całości musi obejmować co najmniej dwie doby i mieścić się
 * w okresie sprzedaży — każdą swoją dobą.
 *
 * ⚠️ **`first_day_on` i `last_day_on` to dni ROZPOCZĘCIA dób, nie granice okna.**
 * Święto 30.04–02.05 obejmuje trzy doby (30.04, 01.05, 02.05), podczas gdy okres
 * sprzedaży o tych samych datach sprzedaje dwie. Reguła **woła `FishingDayCalendar`**
 * zamiast porównywać daty po swojemu — inaczej powstałby drugi kod liczący doby
 * (`docs/conventions/dostepnosc.md` §1), a asymetria reguł granic przestałaby
 * obowiązywać w jednym z dwóch miejsc.
 *
 * ⚠️ **Na świeżym łowisku reguła musi mówić prawdę o przyczynie.** Kalendarz odmawia
 * każdej doby zarówno wtedy, gdy nie ma godzin doby, jak i wtedy, gdy nie ma ani
 * jednego okresu sprzedaży — bez rozróżnienia operator zobaczyłby „święto poza sezonem"
 * tam, gdzie problemem jest brak konfiguracji. Stąd trzy różne komunikaty.
 *
 * ⚠️ **Nie ma tu reguły nienachodzenia.** Święta mogą na siebie zachodzić i mogą
 * zachodzić na weekend — nachodzące spoiwa zlewają się w jeden pakiet, więc nachodzenie
 * jest stanem poprawnym (ADR-013).
 */
class WholeTermPeriodsFitTheSeason implements ValidationRule
{
    public function __construct(private readonly Fishery $fishery) {}

    /**
     * @param  mixed  $value  lista świąt; każde z kluczami `first_day_on` i `last_day_on`
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value) || $value === []) {
            return;
        }

        $terms = [];

        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }

            $firstDayOn = $item['first_day_on'] ?? null;
            $lastDayOn = $item['last_day_on'] ?? null;

            if (blank($firstDayOn) || blank($lastDayOn)) {
                continue;
            }

            $firstDayOn = substr((string) $firstDayOn, 0, 10);
            $lastDayOn = substr((string) $lastDayOn, 0, 10);

            // Święto jednodobowe przeszłoby walidację dat, ale niczego by nie
            // ograniczało — „w całości" jest wtedy puste, a jedynym jego skutkiem
            // byłoby zwolnienie tej doby z minimum, czyli działanie, którego nazwa
            // pola nie opisuje (zadanie 017, rozstrzygnięcie 20).
            if ($lastDayOn <= $firstDayOn) {
                $fail(__('A term sold whole has to cover at least two nights.'));

                return;
            }

            $terms[] = [$firstDayOn, $lastDayOn];
        }

        if ($terms === []) {
            return;
        }

        if (blank($this->fishery->day_start_time) || blank($this->fishery->day_end_time)) {
            $fail(__('Set the fishing day hours before adding terms sold whole.'));

            return;
        }

        if ($this->fishery->salePeriods()->count() === 0) {
            $fail(__('Add a sale period before adding terms sold whole.'));

            return;
        }

        $calendar = new FishingDayCalendar($this->fishery);

        foreach ($terms as [$firstDayOn, $lastDayOn]) {
            for (
                $date = CarbonImmutable::parse($firstDayOn);
                $date->toDateString() <= $lastDayOn;
                $date = $date->addDay()
            ) {
                $day = $calendar->dayStartingOn($date->toDateString());

                // Ta sama reguła zawierania co przy sprzedaży doby, więc doba na styku
                // dwóch sąsiadujących okresów też jest błędem — nie należy do żadnego
                // z nich w całości.
                if (! $day instanceof FishingDay || ! $calendar->isSellable($day)) {
                    $fail(__('A term sold whole has to fit inside a sale period, night by night.'));

                    return;
                }
            }
        }
    }
}
