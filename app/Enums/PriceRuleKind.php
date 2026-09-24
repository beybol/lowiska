<?php

namespace App\Enums;

/**
 * Rodzaj reguły cenowej — jedna mechanika wpisu z flagą, nie dwa byty (O3, ADR-014).
 *
 * ⚠️ Różnią się tym, co robią z kwotą — stawka zastępuje, dopłata dodaje się — oraz
 * kształtem: stawka zna wyłącznie daty, dopłata niesie cały ciężar warunkowy (`cennik.md` §1).
 */
enum PriceRuleKind: string
{
    /**
     * ZASTĘPUJE cenę doby. Dla jednej doby wygrywa dokładnie jedna — najtańsza dla
     * wędkarza (ADR-014, sekcja „Aktualizacja").
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
}
