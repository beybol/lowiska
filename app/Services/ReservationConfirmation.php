<?php

namespace App\Services;

use App\Models\Fishery;
use App\Models\Reservation;
use Carbon\CarbonImmutable;

/**
 * Potwierdzanie rezerwacji ręcznej (ADR-024 pkt 7) — punkt, w którym formularz operatora pyta
 * o termin potwierdzenia i po zapisie uruchamia dalszy przepływ.
 *
 * - `deadlineFor()` — `confirm_by` nowej rezerwacji; `null` = bez wygasania. Ustawienie łowiska
 *   „wymagaj potwierdzenia" dochodzi w zadaniu 045.
 * - `afterBooking()` — po zatwierdzeniu zapisu: prośba o potwierdzenie i powiązanie z kontem mailem
 *   (zadanie 043). Błąd wysyłki nie cofa rezerwacji.
 */
final class ReservationConfirmation
{
    public static function deadlineFor(Fishery $fishery, ?CarbonImmutable $now = null): ?CarbonImmutable // @phpstan-ignore return.unusedType (ustawienie łowiska dochodzi w zadaniu 045)
    {
        return null;
    }

    public static function afterBooking(Reservation $reservation): void
    {
        (new AnglerLinking)->requestConfirmation($reservation);
    }
}
