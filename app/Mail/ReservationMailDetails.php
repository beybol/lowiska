<?php

namespace App\Mail;

use App\Models\Reservation;
use App\Services\AmountFormatter;

/**
 * Wiersze bloku „szczegóły rezerwacji" w mailach (zadania 041, 043, 045) — jeden dom etykiet i formatu,
 * dla wariantu HTML (`x-mail.details`) i tekstowego.
 */
final class ReservationMailDetails
{
    /**
     * @return array<string, string|null>
     */
    public static function rows(Reservation $reservation): array
    {
        $terms = $reservation->terms;
        $snapshot = $terms->position_snapshot ?? [];

        return [
            __('Fishery') => $reservation->fishery?->name,
            __('Position') => $snapshot['name'] ?? $reservation->position?->name,
            __('Stay') => $reservation->stayLabel(),
            __('Anglers') => $terms === null ? null : (string) $terms->anglers,
            __('Companions') => $terms === null || $terms->companions === 0 ? null : (string) $terms->companions,
            __('Amount') => $terms === null ? null : AmountFormatter::cents($terms->total_cents, $terms->currency),
        ];
    }
}
