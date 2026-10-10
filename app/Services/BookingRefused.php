<?php

namespace App\Services;

use RuntimeException;

/**
 * Odmowa wewnątrz transakcji `ReservationBooking` — rzucona, żeby wycofać zapis, i zamieniana na
 * `BookingOutcome` poza transakcją. Nie wydostaje się poza serwis.
 *
 * @internal
 */
final class BookingRefused extends RuntimeException
{
    public function __construct(public readonly BookingOutcome $outcome)
    {
        parent::__construct('Booking refused.');
    }
}
