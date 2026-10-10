<?php

namespace App\Enums;

/**
 * Rodzaj anulacji (makiety etapu 3, wniosek W4). Komunikat dla wędkarza żyje osobno
 * (`reservations.cancel_message`), a wyjątek od zwrotu — w warunkach rezerwacji.
 */
enum CancellationReason: string
{
    /** Decyzja łowiska (modal anulacji). */
    case FisheryDecision = 'fishery_decision';

    /** Kolizja ze zmianą konfiguracji — blokada, wycofanie stanowiska (ADR-027). */
    case Collision = 'collision';

    /** Rezygnacja wędkarza (zadanie 043). */
    case Angler = 'angler';

    public function label(): string
    {
        return match ($this) {
            self::FisheryDecision => __('Cancelled by the fishery'),
            self::Collision => __('Cancelled due to a change at the fishery'),
            self::Angler => __('Cancelled by the angler'),
        };
    }

    /**
     * Anulacja przez łowisko to zawsze pełny zwrot (G8, `cennik.md` §8) — polityka zwrotu
     * dotyczy wyłącznie rezygnacji wędkarza.
     */
    public function isByFishery(): bool
    {
        return $this !== self::Angler;
    }
}
