<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;

/**
 * Każdy rekord wskazany w polu wielokrotnego wyboru należy do TEGO łowiska.
 *
 * ⚠️ Istnieje, bo zawężenie `options()` NIE jest walidacją. Wartość pola
 * wielokrotnego wyboru jest stanem komponentu Livewire i da się ją podmienić
 * w żądaniu — Filament nie sprawdza, czy przysłane identyfikatory pochodzą
 * z listy, którą wyrenderował. Zweryfikowane wprost (security-review, 2026-09-20):
 * bez tej reguły właściciel podpinał stanowiska CUDZEGO łowiska do własnej grupy,
 * a potem akcją zbiorczą zapisywał na nich wartości cech.
 *
 * ⚠️ Pustą wartość przepuszcza — „nic nie wybrano" jest poprawnym stanem relacji.
 * Gdy zbiór ma być niepusty, użyj [`PositionsBelongToFishery`](PositionsBelongToFishery.php),
 * która dokłada ten warunek dla wpisów o dostępności.
 */
class RecordsBelongToFishery implements ValidationRule
{
    /**
     * @param  class-string<Model>  $model  model, w którym szukamy rekordów
     * @param  int|string|null  $fisheryId  łowisko rekordu nadrzędnego — samo pochodzi
     *                                      z pola `Hidden`, więc bramka zapisu i tak
     *                                      weryfikuje je osobno
     */
    public function __construct(
        private readonly string $model,
        private readonly int|string|null $fisheryId,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $ids = self::normalize($value);

        if ($ids === []) {
            return;
        }

        if (! self::allBelongTo($this->model, $ids, $this->fisheryId)) {
            $fail(__('Every selected record must belong to the same fishery.'));
        }
    }

    /**
     * Wspólny rdzeń sprawdzenia — używa go też `PositionsBelongToFishery`,
     * żeby warunek przynależności miał jeden dom.
     *
     * @param  class-string<Model>  $model
     * @param  array<int, int>  $ids
     */
    public static function allBelongTo(string $model, array $ids, int|string|null $fisheryId): bool
    {
        if (! is_numeric($fisheryId)) {
            return false;
        }

        return $model::query()
            ->whereKey($ids)
            ->where('fishery_id', (int) $fisheryId)
            ->count() === count($ids);
    }

    /**
     * @return array<int, int>
     */
    public static function normalize(mixed $value): array
    {
        return collect(is_array($value) ? $value : [])
            ->filter(fn ($id): bool => is_numeric($id))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
