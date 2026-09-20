<?php

namespace App\Services;

use App\Enums\SaleUnavailabilityReason;

/**
 * Odpowiedź na pytanie „czy tę dobę wolno sprzedać", zawsze z powodem odmowy.
 *
 * Odmowa bez powodu jest bezużyteczna dla wędkarza (trafił przed sezon czy za?)
 * i dla operatora (czy to brak konfiguracji, czy świadome zamknięcie?).
 */
final readonly class FishingDayAvailability
{
    private function __construct(
        public bool $sellable,
        public ?SaleUnavailabilityReason $reason,
    ) {}

    public static function sellable(): self
    {
        return new self(true, null);
    }

    public static function refused(SaleUnavailabilityReason $reason): self
    {
        return new self(false, $reason);
    }
}
