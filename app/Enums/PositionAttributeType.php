<?php

namespace App\Enums;

/**
 * Typ cechy stanowiska — decyduje, KTÓRA z trzech kolumn wartości jest właściwa
 * (ADR-011) i jaki komponent generuje się w formularzu.
 */
enum PositionAttributeType: string
{
    /** Tak/nie — pomost, wjazd pojazdem. */
    case Flag = 'flag';

    /** Liczba z jednostką — odległość do parkingu, liczba miejsc na namiot. */
    case Number = 'number';

    /** Wybór z listy opcji przypisanych do cechy — rodzaj brzegu. */
    case Choice = 'choice';

    public function label(): string
    {
        return match ($this) {
            self::Flag => __('Yes / no'),
            self::Number => __('Number'),
            self::Choice => __('Choice from a list'),
        };
    }

    /**
     * Kolumna wartości właściwa dla tego typu. Jedyne miejsce, które tę
     * odpowiedniość zna — reguła walidacyjna i formularz pytają tutaj,
     * zamiast powtarzać `match` u siebie.
     */
    public function valueColumn(): string
    {
        return match ($this) {
            self::Flag => 'value_flag',
            self::Number => 'value_number',
            self::Choice => 'position_attribute_option_id',
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
