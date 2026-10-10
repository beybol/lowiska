<?php

namespace App\Enums;

/**
 * Decyzja operatora dla rezerwacji dotkniętej zmianą konfiguracji (ADR-027 pkt 3).
 */
enum CollisionDecision: string
{
    /** Anuluj z pełnym zwrotem — w etapie 3 zwrot to status płatności (ADR-027 pkt 5). */
    case Cancel = 'cancel';

    /** Zostaw — rezerwacja trwa jako jawny wyjątek w warunkach (ADR-027 pkt 7). */
    case Keep = 'keep';

    public function label(): string
    {
        return match ($this) {
            self::Cancel => __('Cancel with a full refund'),
            self::Keep => __('Keep'),
        };
    }
}
