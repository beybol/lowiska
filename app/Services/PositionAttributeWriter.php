<?php

namespace App\Services;

use App\Enums\PositionAttributeType;
use App\Models\Position;
use App\Models\PositionAttribute;
use App\Models\PositionAttributeValue;
use App\Rules\PositionAttributeValueMatchesType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

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
 *
 * ⚠️ Każde wejście publiczne sprawdza wartości regułą `PositionAttributeValueMatchesType`
 * (ADR-011). Bramka siedzi TUTAJ, a nie w formularzu, bo formularz jest jedną z dwóch
 * ścieżek zapisu — akcja zbiorcza omijała ją i podawała surowy identyfikator opcji
 * prosto do `columnsFor()`. Wywołanie z formularza jest dodatkiem poprawiającym
 * kolejność komunikatu, nie zabezpieczeniem: zdjęcie go niczego tu nie otwiera.
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
     *
     * @throws ValidationException gdy wartość nie pasuje do typu cechy albo opcja
     *                             należy do innej cechy
     */
    public function writeForPosition(Position $position, array $values): void
    {
        $this->assertValid($values);

        $this->write($position, $values, $this->definitionsFor(array_keys($values)));
    }

    /**
     * Ustawia JEDNĄ cechę na wielu stanowiskach i zwraca liczbę objętych rekordów.
     *
     * @param  Collection<int, Position>  $positions
     *
     * @throws ValidationException
     */
    public function writeForMany(Collection $positions, PositionAttribute $attribute, mixed $rawValue): int
    {
        $values = [$attribute->id => $rawValue];

        // Sprawdzenie RAZ, przed pętlą: wartość jest jedna dla całego zbioru,
        // a reguła odpytuje słownik — per stanowisko byłoby to N+1.
        $this->assertValid($values);

        // Z tego samego powodu definicje wyciągamy przed pętlą: `$values` jest
        // identyczne dla każdego stanowiska, więc zapytanie o słownik w środku
        // pętli byłoby drugim N+1 — przy dwustu stanowiskach dwieście razy to samo.
        $definitions = $this->definitionsFor(array_keys($values));

        foreach ($positions as $position) {
            $this->write($position, $values, $definitions);
        }

        return $positions->count();
    }

    /**
     * Sprawdza komplet wartości TĄ SAMĄ regułą, którą wymusza zapis.
     *
     * Publiczne, żeby formularz stanowiska mógł pokazać błąd PRZED zapisem rekordu —
     * writer jest wołany dopiero w `afterSave()`, więc sam wyjątek zostawiłby
     * zapisane stanowisko bez cech.
     *
     * @param  array<int|string, mixed>  $values
     *
     * @throws ValidationException
     */
    public function assertValid(array $values): void
    {
        Validator::make(
            ['position_attributes' => $values],
            ['position_attributes' => [new PositionAttributeValueMatchesType]],
        )->validate();
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @param  Collection<int, PositionAttribute>  $definitions
     */
    private function write(Position $position, array $values, Collection $definitions): void
    {
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
