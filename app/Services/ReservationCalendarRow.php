<?php

namespace App\Services;

use App\Models\Position;
use Carbon\CarbonImmutable;

/**
 * Wiersz stanowiska w warstwie „Rezerwacje": komórki dób i paski rezerwacji przycięte do okna.
 */
final readonly class ReservationCalendarRow
{
    /**
     * @param  array<int, ReservationCalendarCell>  $cells  indeks = kolumna
     * @param  array<int, ReservationCalendarBar>  $bars  rosnąco po kolumnie
     */
    public function __construct(
        public Position $position,
        public array $cells,
        public array $bars,
        public bool $withdrawn,
    ) {}

    /** Pasek zaczynający się w tej kolumnie — widok renderuje go jednym `colspan`. */
    public function barStartingAt(int $column): ?ReservationCalendarBar
    {
        foreach ($this->bars as $bar) {
            if ($bar->column === $column) {
                return $bar;
            }
        }

        return null;
    }

    public function isCoveredByBar(int $column): bool
    {
        foreach ($this->bars as $bar) {
            if ($column > $bar->column && $column < $bar->column + $bar->span) {
                return true;
            }
        }

        return false;
    }

    public function barOn(CarbonImmutable $day): ?ReservationCalendarBar
    {
        foreach ($this->cells as $index => $cell) {
            if ($cell->day->isSameDay($day)) {
                foreach ($this->bars as $bar) {
                    if ($index >= $bar->column && $index < $bar->column + $bar->span) {
                        return $bar;
                    }
                }
            }
        }

        return null;
    }

    public function cellOn(CarbonImmutable $day): ?ReservationCalendarCell
    {
        foreach ($this->cells as $cell) {
            if ($cell->day->isSameDay($day)) {
                return $cell;
            }
        }

        return null;
    }
}
