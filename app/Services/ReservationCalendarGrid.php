<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * Siatka warstwy „Rezerwacje" (zadanie 042): kolumny to doby (dzień rozpoczęcia w strefie łowiska).
 */
final readonly class ReservationCalendarGrid
{
    /**
     * @param  array<int, CarbonImmutable>  $days
     * @param  array<int, ReservationCalendarRow>  $rows
     */
    public function __construct(
        public array $days,
        public array $rows,
        public CarbonImmutable $today,
    ) {}

    public function isWeekend(CarbonImmutable $day): bool
    {
        return $day->isoWeekday() >= 6;
    }

    public function isToday(CarbonImmutable $day): bool
    {
        return $day->isSameDay($this->today);
    }

    public function row(int $positionId): ?ReservationCalendarRow
    {
        foreach ($this->rows as $row) {
            if ((int) $row->position->getKey() === $positionId) {
                return $row;
            }
        }

        return null;
    }
}
