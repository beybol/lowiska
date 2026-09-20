<?php

namespace App\Services;

use App\Enums\SelectionKind;
use App\Models\Fishery;
use App\Models\Position;
use App\Models\PositionGroup;
use Illuminate\Support\Collection;

/**
 * Przelicza kryterium wyboru na listę stanowisk — na żądanie, w formularzu.
 *
 * ⚠️ To jest jedyne miejsce, które wie, co znaczy „całe łowisko", „grupa" albo
 * „stanowiska z cechą". Wynik trafia do zmaterializowanego zbioru wpisu i od tej
 * chwili kryterium już nic nie rozstrzyga: zmiana cechy na stanowisku NIE wciąga
 * go do blokady ani z niej nie wypycha. Kryterium podpowiada, lista przesądza.
 */
final readonly class AvailabilityBlockSelectionResolver
{
    /**
     * @return Collection<int, int> identyfikatory stanowisk łowiska spełniające kryterium
     */
    public function resolve(Fishery $fishery, SelectionKind $kind, int|string|null $criterion): Collection
    {
        $positions = Position::query()->where('fishery_id', $fishery->id);

        $ids = match ($kind) {
            SelectionKind::Fishery => $positions->pluck('id'),
            SelectionKind::Group => is_numeric($criterion)
                ? PositionGroup::query()
                    ->whereKey((int) $criterion)
                    ->where('fishery_id', $fishery->id)
                    ->first()
                    ?->positions()->pluck('positions.id') ?? collect()
                : collect(),
            SelectionKind::Attribute => is_numeric($criterion)
                ? $positions
                    ->whereHas('attributeValues', function ($query) use ($criterion): void {
                        $query
                            ->where('position_attribute_id', (int) $criterion)
                            ->where('value_flag', true);
                    })
                    ->pluck('id')
                : collect(),
            // Wybór ręczny nie ma kryterium — nic nie przeliczamy, lista jest źródłem.
            SelectionKind::Manual => collect(),
        };

        /** @var Collection<int, int> $ids */
        return $ids->map(fn ($id): int => (int) $id)->values();
    }
}
