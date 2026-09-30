<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Numer telefonu kontaktowego łowiska (zadanie 030).
 *
 * Celowo luźna: numer trafia do wędkarza jako tekst do wybrania, nie do bramki SMS. Dopuszcza
 * zapis z kierunkowym i separatorami („+48 661 231 623", „(61) 517-971-002"), ale wymaga
 * co najmniej sześciu cyfr, żeby „-" albo „brak" nie udawały numeru.
 */
class PhoneNumber implements ValidationRule
{
    public const MAX_LENGTH = 32;

    private const MIN_DIGITS = 6;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        $value = (string) $value;

        if (mb_strlen($value) > self::MAX_LENGTH
            || preg_match('/^[0-9+\-() ]+$/', $value) !== 1
            || preg_match_all('/[0-9]/', $value) < self::MIN_DIGITS) {
            $fail(__('The :attribute must be a phone number: digits, spaces, "+", "-" and brackets, at least 6 digits.'));
        }
    }
}
