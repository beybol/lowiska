<?php

namespace App\Services;

use App\Enums\BlockEffect;
use App\Enums\SelectionKind;
use App\Models\AvailabilityBlock;
use App\Models\Fishery;
use App\Models\Position;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Blokada sprzedaży jednego stanowiska założona skrótem z kalendarza (zadanie 042).
 *
 * ⚠️ Blokada nie unieważnia rezerwacji sama z siebie (G8, ADR-027). Skrót nie podejmuje decyzji
 * o kolizji — gdy okno blokady przecina aktywną rezerwację, odmawia i odsyła do ekranu blokad,
 * gdzie operator decyduje jawnie. Okno blokady to całe dni w strefie łowiska (`dostepnosc.md` §3),
 * więc przecina także dobę rozpoczętą dzień wcześniej.
 */
final class AvailabilityBlockWriter
{
    /**
     * @return string|null komunikat odmowy; `null` = zapisano
     */
    public static function blockPosition(
        Fishery $fishery,
        Position $position,
        string $startsOn,
        string $endsOn,
        string $reason,
        bool $reasonVisible = true,
    ): ?string {
        if ((int) $position->fishery_id !== (int) $fishery->getKey()) {
            abort(404);
        }

        return DB::transaction(function () use ($fishery, $position, $startsOn, $endsOn, $reason, $reasonVisible): ?string {
            // Ta sama kolejność blokad co w ścieżce zapisu rezerwacji — bez wyścigu z nową rezerwacją.
            Position::query()->whereKey($position->getKey())->lockForUpdate()->first();

            if (self::collidingReservations($fishery, $position, $startsOn, $endsOn) > 0) {
                return __('The block overlaps a reservation on this position. Use the availability blocks screen to decide what happens to it.');
            }

            $block = AvailabilityBlock::query()->create([
                'fishery_id' => $fishery->getKey(),
                'effect' => BlockEffect::SaleBlocked->value,
                'position_attribute_id' => null,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'reason' => $reason,
                'reason_visible' => $reasonVisible,
                'selection_kind' => SelectionKind::Manual->value,
                'selection_label' => null,
            ]);

            $block->positions()->attach($position->getKey());

            return null;
        });
    }

    /** Aktywne rezerwacje stanowiska przecinające okno blokady (całe dni w strefie łowiska). */
    public static function collidingReservations(Fishery $fishery, Position $position, string $startsOn, ?string $endsOn): int
    {
        $timezone = $fishery->timezoneName();
        $from = CarbonImmutable::parse($startsOn, $timezone)->startOfDay()->utc();
        $query = Reservation::query()
            ->where('position_id', $position->getKey())
            ->active()
            ->where('ends_at', '>', $from->format('Y-m-d H:i:s'));

        if ($endsOn !== null) {
            $to = CarbonImmutable::parse($endsOn, $timezone)->endOfDay()->utc();
            $query->where('starts_at', '<', $to->format('Y-m-d H:i:s'));
        }

        return $query->count();
    }
}
