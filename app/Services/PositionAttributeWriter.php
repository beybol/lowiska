<?php

namespace App\Services;

use App\Enums\PositionAttributeType;
use App\Models\Position;
use App\Models\PositionAttribute;
use App\Models\PositionAttributeValue;
use Illuminate\Support\Collection;

/**
 * Zapis wartości cech na stanowiskach — jedno miejsce, które wie, do której z trzech
 * kolumn trafia wartość danego typu (ADR-011).
 *
 * ⚠️ Wołają to ZARÓWNO formularz stanowiska, JAK I akcja zbiorcza. Drugi zapis
 * rozkładający wartość na kolumny po swojemu rozjechałby się przy pierwszym nowym
 * typie cechy — i to po cichu, bo baza przyjmie każdą z trzech kolumn.
 *
 * ⚠️ Akcja zbiorcza zapisuje wartość WPROST na każdym stanowisku, zamiast ją skądkolwiek
 * dziedziczyć. Po wykonaniu każde stanowisko niesie własną wartość i nic nie jest
 * rozwiązywane przy odczycie — to jest cała różnica wobec odrzuconego dziedziczenia
 * po grupie (zadanie 014, „Rozstrzygnięcia").
 */
final readonly class PositionAttributeWriter
{
    /**
     * Zapisuje komplet cech jednego stanowiska.
     *
     * Klucz bez wartości (`null`) KASUJE wiersz zamiast zapisywać fałsz — brak wiersza
     * to trzeci stan i musi dać się do niego wrócić.
     *
     * @param  array<int|string, mixed>  $values  mapa `id cechy => wartość`
     */
    public function writeForPosition(Position $position, array $values): void
    {
        $definitions = $this->definitionsFor(array_keys($values));

        foreach ($values as $attributeId => $rawValue) {
            $definition = $definitions->get((int) $attributeId);

            if (! $definition instanceof PositionAttribute) {
                continue;
            }

            if ($rawValue === null || $rawValue === '') {
                $position->attributeValues()
                    ->where('position_attribute_id', $definition->id)
                    ->delete();

                continue;
            }

            PositionAttributeValue::updateOrCreate(
                [
                    'position_id' => $position->id,
                    'position_attribute_id' => $definition->id,
                ],
                $this->columnsFor($definition->type, $rawValue),
            );
        }
    }

    /**
     * Ustawia JEDNĄ cechę na wielu stanowiskach i zwraca liczbę objętych rekordów.
     *
     * @param  Collection<int, Position>  $positions
     */
    public function writeForMany(Collection $positions, PositionAttribute $attribute, mixed $rawValue): int
    {
        foreach ($positions as $position) {
            $this->writeForPosition($position, [$attribute->id => $rawValue]);
        }

        return $positions->count();
    }

    /**
     * Rozkład wartości na kolumny. Pozostałe dwie są jawnie zerowane, żeby zmiana
     * typu cechy w słowniku nie zostawiła po sobie wartości w martwej kolumnie.
     *
     * @return array<string, mixed>
     */
    private function columnsFor(PositionAttributeType $type, mixed $rawValue): array
    {
        $columns = [
            'value_flag' => null,
            'value_number' => null,
            'position_attribute_option_id' => null,
        ];

        $columns[$type->valueColumn()] = match ($type) {
            PositionAttributeType::Flag => (bool) $rawValue,
            PositionAttributeType::Number => $rawValue,
            PositionAttributeType::Choice => (int) $rawValue,
        };

        return $columns;
    }

    /**
     * @param  array<int, int|string>  $attributeIds
     * @return Collection<int, PositionAttribute>
     */
    private function definitionsFor(array $attributeIds): Collection
    {
        return PositionAttribute::query()
            ->whereIn('id', $attributeIds)
            ->get()
            ->keyBy('id');
    }
}
