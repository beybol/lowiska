<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * Doba stanowiska w warstwie „Rezerwacje". Przeszłość jest nieklikalna, doba zajęta nie daje się
 * zaznaczyć ani przeskoczyć przy wydłużaniu zakresu.
 */
final readonly class ReservationCalendarCell
{
    public function __construct(
        public CarbonImmutable $day,
        public bool $past,
        public bool $occupied,
        public bool $blocked,
    ) {}

    public function isSelectable(): bool
    {
        return ! $this->past && ! $this->occupied;
    }
}
