<?php

namespace App\Enums;

/**
 * JAK operator wybrał zbiór stanowisk — wyłącznie opis, nie reguła.
 *
 * ⚠️ Nie jest rozwiązywany przy odczycie. Zbiór jest zmaterializowany w tabeli
 * pośredniej; ten enum służy do wyjaśnienia i do ponownego przeliczenia na żądanie.
 */
enum SelectionKind: string
{
    case Fishery = 'fishery';
    case Group = 'group';
    case Manual = 'manual';
    case Attribute = 'attribute';

    public function label(): string
    {
        return match ($this) {
            self::Fishery => __('Whole fishery'),
            self::Group => __('Position group'),
            self::Manual => __('Chosen by hand'),
            self::Attribute => __('Positions with an attribute'),
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
