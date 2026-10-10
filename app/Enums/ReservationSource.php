<?php

namespace App\Enums;

/**
 * Kanał, którym powstała rezerwacja (zadanie 039).
 *
 * ⚠️ Kanał decyduje o jednym niezmienniku: **wyjątki od reguł sprzedaży, blokady i ceny wolno
 * zapisywać wyłącznie w kanale operatora i admina** (ADR-026 pkt 7–8, ADR-027 pkt 7). Kanał
 * wędkarza nigdy nie omija blokady. `Online` dochodzi w etapie 4 (zamówienie wędkarza); dziś
 * istnieje po to, żeby granica była sprawdzalna testem, a nie konwencją.
 */
enum ReservationSource: string
{
    case Operator = 'operator';

    case Admin = 'admin';

    case Online = 'online';

    public function label(): string
    {
        return match ($this) {
            self::Operator => __('Operator'),
            self::Admin => __('Administrator'),
            self::Online => __('Online'),
        };
    }

    public function allowsExceptions(): bool
    {
        return $this !== self::Online;
    }
}
