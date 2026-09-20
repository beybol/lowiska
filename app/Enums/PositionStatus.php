<?php

namespace App\Enums;

/**
 * Stan WŁASNY stanowiska — decyzja operatora, czy miejsce jest w sprzedaży.
 *
 * ⚠️ Nie mylić z dostępnością w konkretnym terminie. Ta jest czymś innym, zależy
 * od czasu i wylicza ją zadanie 016; tutaj nie ma żadnych dat ani przyczyn.
 */
enum PositionStatus: string
{
    case Available = 'available';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Available => __('Available'),
            self::Withdrawn => __('Withdrawn'),
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
