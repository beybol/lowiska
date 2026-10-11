<?php

namespace App\Services;

use App\Models\AvailabilityBlock;

/**
 * Pasek blokady sprzedaży w warstwie „Rezerwacje" (zadanie 047, A6): szary kreskowany pasek z powodem.
 *
 * Model połówek jak pasek rezerwacji (`ReservationCalendarBar`): blokada obejmuje doby od „od" do „do",
 * więc pasek biegnie od połowy pierwszego dnia do połowy dnia po ostatniej dobie. `column` to pierwszy
 * dotknięty dzień, `span` liczy dni z tym ostatnim; `startsBefore`/`endsAfter` — przycięcie do okna.
 * Doby pod paskiem rezerwacji (rezerwacja zostawiona przy blokadzie decyzją z ekranu E) są pominięte.
 */
final readonly class ReservationCalendarBlock
{
    public function __construct(
        public AvailabilityBlock $block,
        public int $column,
        public int $span,
        public bool $startsBefore,
        public bool $endsAfter,
    ) {}

    public function label(): string
    {
        return filled($this->block->reason) ? (string) $this->block->reason : __('Blocked');
    }

    /** Doby blokady widoczne w oknie. */
    public function nights(): int
    {
        return $this->span - ($this->endsAfter ? 0 : 1);
    }

    public function coversNight(int $index): bool
    {
        return $index >= $this->column && $index < $this->column + $this->nights();
    }
}
