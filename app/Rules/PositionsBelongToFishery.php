<?php

namespace App\Rules;

use App\Models\Position;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Zbiór stanowisk wpisu o dostępności: niepusty i w całości z łowiska wpisu.
 *
 * ⚠️ Identyfikatory przychodzą z pola wielokrotnego wyboru, czyli od klienta.
 * Zawężenie opcji w formularzu nie wystarcza — wybór wielokrotny jest stanem
 * komponentu i da się go podmienić (`autoryzacja.md` §4). Bez tej reguły blokada
 * założona na własnym łowisku mogłaby objąć cudze stanowiska.
 */
class PositionsBelongToFishery implements ValidationRule
{
    public function __construct(private readonly int|string|null $fisheryId) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $ids = collect(is_array($value) ? $value : [])
            ->filter(fn ($id): bool => is_numeric($id))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            $fail(__('Choose at least one position.'));

            return;
        }

        if (! is_numeric($this->fisheryId)) {
            $fail(__('Choose at least one position.'));

            return;
        }

        $owned = Position::query()
            ->whereKey($ids->all())
            ->where('fishery_id', (int) $this->fisheryId)
            ->count();

        if ($owned !== $ids->count()) {
            $fail(__('Every position must belong to the same fishery as the entry.'));
        }
    }
}
