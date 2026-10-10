<?php

namespace App\Services;

use App\Models\Reservation;

/**
 * Pasek rezerwacji w siatce: kolumna początku i liczba kolumn po przycięciu do okna.
 * `startsBefore` — pobyt zaczął się przed oknem (znacznik ◀); `endsAfter` — kończy się po nim.
 * `kind`: `pending` · `confirmed` · `paid` · `in_progress` (kolor paska).
 */
final readonly class ReservationCalendarBar
{
    public function __construct(
        public Reservation $reservation,
        public int $column,
        public int $span,
        public bool $startsBefore,
        public bool $endsAfter,
        public string $kind,
    ) {}

    public function label(): string
    {
        $contact = $this->reservation->angler?->effectiveContact() ?? [];

        return (string) ($contact['name'] ?? $this->reservation->terms->contact_snapshot['name'] ?? '#'.$this->reservation->getKey());
    }
}
