<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Progi polityki zwrotu (zadanie 021): liczby całkowite (dni 0–365, procent 0–100), bez duplikatów
 * liczby dni, a procent NIE ROŚNIE wraz z przybliżaniem się terminu.
 *
 * ⚠️ Portal nie narzuca widełek (D2) — „0% zawsze" jest poprawne; ostrzeżenie o nim daje panel,
 * nie ta reguła.
 */
final class RefundTiersAreValid implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value) || $value === []) {
            return;
        }

        $percentByDays = [];

        foreach ($value as $tier) {
            $days = is_array($tier) ? ($tier['days'] ?? null) : null;
            $percent = is_array($tier) ? ($tier['percent'] ?? null) : null;

            if (! self::isWhole($days, 0, 365) || ! self::isWhole($percent, 0, 100)) {
                $fail(__('Each refund tier needs whole numbers: 0–365 days and 0–100 percent.'));

                return;
            }

            if (array_key_exists((int) $days, $percentByDays)) {
                $fail(__('Two refund tiers can not have the same number of days.'));

                return;
            }

            $percentByDays[(int) $days] = (int) $percent;
        }

        krsort($percentByDays);
        $previous = null;

        foreach ($percentByDays as $percent) {
            if ($previous !== null && $percent > $previous) {
                $fail(__('The refund can not grow as the stay gets closer.'));

                return;
            }

            $previous = $percent;
        }
    }

    private static function isWhole(mixed $value, int $min, int $max): bool
    {
        if (is_int($value)) {
            return $value >= $min && $value <= $max;
        }

        if (! is_string($value) || preg_match('/^\d+$/', $value) !== 1) {
            return false;
        }

        return (int) $value >= $min && (int) $value <= $max;
    }
}
