<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Maksymalna długość pobytu nie może być mniejsza niż minimalna.
 *
 * ⚠️ Reguła porównawcza zamiast `gte:min_nights`, bo **oba pola bywają puste**
 * i wtedy porównanie ma się w ogóle nie odbyć — `null` znaczy „bez granicy", nie zero
 * (ten sam zabieg co przy `max_people` w `PositionResource`).
 *
 * Mieszka tutaj, a nie w formularzu strony, bo formularz jest jedną ze ścieżek zapisu
 * (`CLAUDE.md`, „logika walidacyjna ma jeden dom").
 */
class StayLengthRangeIsOrdered implements ValidationRule
{
    public function __construct(private readonly mixed $minNights) {}

    /**
     * @param  mixed  $value  maksymalna liczba dób; `null` znaczy „bez górnej granicy"
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value) || blank($this->minNights)) {
            return;
        }

        if ((int) $value < (int) $this->minNights) {
            $fail(__('The maximum stay length can not be lower than the minimum.'));
        }
    }
}
