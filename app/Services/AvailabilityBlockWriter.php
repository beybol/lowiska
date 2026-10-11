<?php

namespace App\Services;

use App\Enums\BlockEffect;
use App\Enums\SelectionKind;
use App\Models\AvailabilityBlock;
use App\Models\Fishery;
use App\Models\Position;
use Illuminate\Support\Facades\DB;

/**
 * Blokada sprzedaży jednego stanowiska założona skrótem z kalendarza (zadanie 042, 047 A9).
 *
 * ⚠️ Blokada nie unieważnia rezerwacji sama z siebie (G8, ADR-027). Skrót nie ma WŁASNEJ reguły kolizji:
 * pyta `CollisionGate` (czyli `ConfigurationCollisionService::affected()`), a przy kolizji zwraca adres
 * ekranu decyzji E — tam zmiana i decyzje zapisują się razem. Bez kolizji zapisuje blokadę od razu.
 */
final class AvailabilityBlockWriter
{
    /**
     * @return string|null adres ekranu decyzji (blokada NIE zapisana); `null` = zapisano
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

        $fields = [
            'fishery_id' => (int) $fishery->getKey(),
            'effect' => BlockEffect::SaleBlocked->value,
            'position_attribute_id' => null,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'reason' => $reason,
            'reason_visible' => $reasonVisible,
            'selection_kind' => SelectionKind::Manual->value,
            'selection_label' => null,
        ];

        return DB::transaction(function () use ($position, $fields): ?string {
            // Ta sama kolejność blokad co w ścieżce zapisu rezerwacji — bez wyścigu z nową rezerwacją.
            Position::query()->whereKey($position->getKey())->lockForUpdate()->first();

            $gate = (new CollisionGate)->forBlock($fields, [(int) $position->getKey()]);

            if ($gate['redirect'] !== null) {
                return $gate['redirect'];
            }

            $block = AvailabilityBlock::query()->create($fields);
            $block->positions()->attach($position->getKey());

            return null;
        });
    }
}
