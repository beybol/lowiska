<?php

namespace App\Enums;

/**
 * Skutek wpisu o dostępności — jedyna rzecz, która różni blokadę od ograniczenia.
 * Kształt wpisu (zbiór, daty, powód, widoczność) jest identyczny.
 */
enum BlockEffect: string
{
    /** Wyłącza sprzedaż w terminie — doba przecinająca okno jest niesprzedawalna. */
    case SaleBlocked = 'sale_blocked';

    /**
     * Zawiesza jedną cechę na czas przecięcia, NIE ruszając jej wartości.
     * Stanowisko nadal się sprzedaje — tylko bez tej cechy.
     */
    case AttributeSuspended = 'attribute_suspended';

    public function label(): string
    {
        return match ($this) {
            self::SaleBlocked => __('Sale blocked'),
            self::AttributeSuspended => __('Attribute suspended'),
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
