<?php

namespace App\Enums;

/**
 * Stan rezerwacji (zadanie 039, rozstrzygnięcie zadania).
 *
 * „Do potwierdzenia" to `Pending` z wypełnionym `confirm_by`; rezerwacja bez wymogu potwierdzenia
 * powstaje od razu jako `Confirmed`. ⚠️ „W toku" NIE jest stanem — wynika z przedziału pobytu
 * i chwili pytania, więc kolumna z nim rozjechałaby się z zegarem.
 *
 * Sloty trzymają wyłącznie rezerwacje aktywne (`Pending`, `Confirmed`) — anulacja i wygaśnięcie
 * usuwają je (ADR-025 pkt 6).
 */
enum ReservationStatus: string
{
    case Pending = 'pending';

    case Confirmed = 'confirmed';

    case Cancelled = 'cancelled';

    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Awaiting confirmation'),
            self::Confirmed => __('Confirmed'),
            self::Cancelled => __('Cancelled'),
            self::Expired => __('Expired'),
        };
    }

    /** Rezerwacja zajmuje stanowisko (ma sloty). */
    public function isActive(): bool
    {
        return $this === self::Pending || $this === self::Confirmed;
    }

    /**
     * @return array<int, self>
     */
    public static function active(): array
    {
        return [self::Pending, self::Confirmed];
    }
}
