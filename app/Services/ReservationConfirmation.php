<?php

namespace App\Services;

use App\Models\Fishery;
use App\Models\Reservation;
use Carbon\CarbonImmutable;

/**
 * Potwierdzanie rezerwacji ręcznej (ADR-024 pkt 7) — punkt, w którym formularz operatora pyta
 * o termin potwierdzenia i po zapisie uruchamia dalszy przepływ.
 *
 * - `deadlineFor()` — `confirm_by` nowej rezerwacji z ustawień łowiska (zadanie 045): teraz + okno,
 *   gdy łowisko wymaga potwierdzenia; inaczej `null` (bez wygasania). ⚠️ Zmiana ustawień nie przepisuje
 *   terminu rezerwacji już założonych — konfiguracja żyje, transakcja jest zamrożona.
 * - `afterBooking()` — po zatwierdzeniu zapisu: prośba o potwierdzenie i powiązanie z kontem mailem
 *   (zadanie 043). Błąd wysyłki nie cofa rezerwacji.
 */
final class ReservationConfirmation
{
    /**
     * Termin nie przekracza początku pobytu (047, E1; ADR-024 aktualizacja 11.10): niepotwierdzona
     * rezerwacja wygasa najpóźniej w chwili rozpoczęcia — pobyt w toku nigdy nie wygasa.
     */
    public static function deadlineFor(Fishery $fishery, ?CarbonImmutable $now = null, ?CarbonImmutable $stayStartsAt = null): ?CarbonImmutable
    {
        if (! $fishery->manual_reservation_confirmation_required) {
            return null;
        }

        $hours = (int) ($fishery->manual_reservation_hold_hours ?: Fishery::DEFAULT_HOLD_HOURS);
        $deadline = CarbonImmutable::instance($now ?? now())->addHours(max(1, min(Fishery::MAX_HOLD_HOURS, $hours)));

        return $stayStartsAt !== null && $stayStartsAt < $deadline ? $stayStartsAt : $deadline;
    }

    public static function afterBooking(Reservation $reservation): void
    {
        (new AnglerLinking)->requestConfirmation($reservation);
    }
}
