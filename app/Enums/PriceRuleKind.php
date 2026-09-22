<?php

namespace App\Enums;

/**
 * Rodzaj reguły cenowej — jedna mechanika wpisu z flagą, nie dwa byty (O3, ADR-014).
 *
 * ⚠️ Różnią się WYŁĄCZNIE tym, co robią z kwotą: stawka zastępuje, dopłata dodaje się.
 * Warunki, priorytet, zawieszenie i okres obowiązywania działają identycznie w obu.
 */
enum PriceRuleKind: string
{
    /**
     * ZASTĘPUJE stawkę. Dla jednej doby i jednej roli wygrywa dokładnie jedna —
     * po priorytecie, a przy remisie po szczegółowości.
     */
    case Rate = 'rate';

    /**
     * DODAJE się do stawki. Wszystkie pasujące sumują się, więc nie ma tu czego
     * rozstrzygać — przy kwotach wynik nie zależy od kolejności (K1a).
     */
    case Surcharge = 'surcharge';

    public function label(): string
    {
        return match ($this) {
            self::Rate => __('Rate'),
            self::Surcharge => __('Surcharge'),
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
