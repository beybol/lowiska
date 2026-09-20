<?php

namespace App\Enums;

/**
 * Tryb sprzedaży łowiska — czym jest jednostka, którą wędkarz kupuje.
 *
 * Dziś jedna wartość. Enum, a nie stała, żeby dołożenie sprzedaży godzinowej
 * albo turnusu było dopisaniem wartości, a nie przepisaniem ADR-010.
 */
enum SaleMode: string
{
    case DailyPeriod = 'daily_period';

    public function label(): string
    {
        return match ($this) {
            self::DailyPeriod => __('Daily period from a fixed hour'),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
