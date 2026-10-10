<?php

namespace App\Enums;

/**
 * Warstwa kalendarza łowiska (zadanie 042, zasada G4): ten sam ekran i ta sama siatka, dwa pytania.
 *
 * - `Offer` — podgląd konfiguracji sprzedaży („co z tego wynika", 019). **Wyłącznie podgląd** — nic
 *   z niej nie zapisuje.
 * - `Reservations` — warstwa operacyjna: rezerwacje i blokady na stanowiskach, zakładanie i zmiana
 *   rezerwacji. Domyślna.
 */
enum CalendarLayer: string
{
    case Reservations = 'reservations';

    case Offer = 'offer';

    public function label(): string
    {
        return match ($this) {
            self::Reservations => __('Reservations'),
            self::Offer => __('Offer'),
        };
    }
}
