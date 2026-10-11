<?php

namespace App\Services;

use App\Models\Position;
use Carbon\CarbonImmutable;

/**
 * Wiersz stanowiska w warstwie „Rezerwacje": dni kalendarzowe, paski rezerwacji (od połowy dnia przyjazdu do
 * połowy dnia wyjazdu) i paski blokad (ten sam model połówek) przycięte do okna.
 */
final readonly class ReservationCalendarRow
{
    /**
     * @param  array<int, ReservationCalendarCell>  $cells  indeks = kolumna
     * @param  array<int, ReservationCalendarBar>  $bars  rosnąco po kolumnie
     * @param  array<int, ReservationCalendarBlock>  $blocks  rosnąco po kolumnie, rozłączne z paskami rezerwacji
     */
    public function __construct(
        public Position $position,
        public array $cells,
        public array $bars,
        public bool $withdrawn,
        public array $blocks = [],
    ) {}

    /**
     * Paski rezerwacji zaczynające się w tej kolumnie — widok kładzie je warstwą nad dniami. Bywają dwa:
     * pobyt kończący się rano pierwszego dnia okna i pobyt zaczynający się tego dnia.
     *
     * @return list<ReservationCalendarBar>
     */
    public function barsStartingAt(int $column): array
    {
        return array_values(array_filter($this->bars, fn (ReservationCalendarBar $bar): bool => $bar->column === $column));
    }

    /**
     * Paski blokad zaczynające się w tej kolumnie — jak przy rezerwacjach bywają dwa (koniec jednej blokady
     * rano i początek drugiej po południu).
     *
     * @return list<ReservationCalendarBlock>
     */
    public function blocksStartingAt(int $column): array
    {
        return array_values(array_filter($this->blocks, fn (ReservationCalendarBlock $block): bool => $block->column === $column));
    }

    /** Rezerwacja, do której należy doba zaczynająca się tego dnia (dzień samego wyjazdu — nie). */
    public function barOn(CarbonImmutable $day): ?ReservationCalendarBar
    {
        $index = $this->indexOf($day);

        foreach ($index === null ? [] : $this->bars as $bar) {
            if ($bar->coversNight($index)) {
                return $bar;
            }
        }

        return null;
    }

    public function blockOn(CarbonImmutable $day): ?ReservationCalendarBlock
    {
        $index = $this->indexOf($day);

        foreach ($index === null ? [] : $this->blocks as $block) {
            if ($block->coversNight($index)) {
                return $block;
            }
        }

        return null;
    }

    public function cellOn(CarbonImmutable $day): ?ReservationCalendarCell
    {
        $index = $this->indexOf($day);

        return $index === null ? null : $this->cells[$index];
    }

    private function indexOf(CarbonImmutable $day): ?int
    {
        foreach ($this->cells as $index => $cell) {
            if ($cell->day->isSameDay($day)) {
                return $index;
            }
        }

        return null;
    }
}
