<?php

namespace Tests\Feature;

use App\Filament\Resources\PositionGroupResource\Pages\ListPositionGroups;
use App\Filament\Resources\PositionResource;
use App\Filament\Resources\PositionResource\Pages\ListPositions;
use App\Models\Fishery;
use App\Models\Position;
use App\Models\PositionAttribute;
use App\Models\PositionAttributeOption;
use App\Models\PositionAttributeValue;
use App\Models\PositionGroup;
use App\Models\User;
use App\Services\OwnerRoleProvisioner;
use App\Services\PositionAttributeWriter;
use Filament\Facades\Filament;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * Akcja zbiorcza ustawiania cechy — prymityw, który ZASTĘPUJE dziedziczenie po grupie.
 *
 * ⚠️ Testy pilnują rzeczy, której nie widać po samym wyniku: wartość ma wylądować
 * WPROST na każdym stanowisku. Gdyby ktoś kiedyś „zoptymalizował" to na wskaźnik do
 * grupy rozwiązywany przy odczycie, wynik pierwszego testu byłby taki sam, a drugi
 * (własny wiersz na każdym stanowisku) by pękł.
 */
beforeEach(function () {
    Filament::setCurrentPanel('owner');
});

function ownerFisheryAndPositions(int $count = 3): array
{
    $owner = User::factory()->create(['name' => 'Wlasciciel Testowy']);
    OwnerRoleProvisioner::addOwnerRole($owner);
    $fishery = Fishery::factory()->forUser($owner)->create();

    $positions = collect(range(1, $count))->map(
        fn (int $i) => Position::factory()->create([
            'fishery_id' => $fishery->id,
            'name' => 'Stanowisko '.$i,
        ])
    );

    return [$owner, $fishery, $positions];
}

/**
 * Akcja zbiorcza dostaje od Filamenta kolekcję Eloquenta, nie `Support\Collection`
 * — pomocnik odtwarza ten sam typ przy wywołaniu z pominięciem formularza.
 *
 * @param  Collection<int, Position>  $positions
 * @return \Illuminate\Database\Eloquent\Collection<int, Position>
 */
function asEloquentCollection($positions): \Illuminate\Database\Eloquent\Collection
{
    return Position::query()->whereIn('id', $positions->pluck('id')->all())->get();
}

test('the bulk action sets the attribute on every selected position', function () {
    [$owner, $fishery, $positions] = ownerFisheryAndPositions();
    $attribute = PositionAttribute::factory()->create();

    $this->actingAs($owner);

    Livewire::withQueryParams(['fishery' => $fishery->id])
        ->test(ListPositions::class)
        ->callTableBulkAction(
            'setPositionAttribute',
            $positions,
            data: [
                'position_attribute_id' => $attribute->id,
                'value_flag' => 1,
            ],
        );

    foreach ($positions as $position) {
        expect($position->attributeValues()->where('position_attribute_id', $attribute->id)->value('value_flag'))
            ->toEqual(1);
    }
});

test('after the bulk action every position carries its own row', function () {
    [$owner, $fishery, $positions] = ownerFisheryAndPositions();
    $attribute = PositionAttribute::factory()->create();

    $this->actingAs($owner);

    Livewire::withQueryParams(['fishery' => $fishery->id])
        ->test(ListPositions::class)
        ->callTableBulkAction(
            'setPositionAttribute',
            $positions,
            data: [
                'position_attribute_id' => $attribute->id,
                'value_flag' => 1,
            ],
        );

    // ⚠️ Trzy stanowiska, trzy wiersze. Nic nie jest rozwiązywane przy odczycie —
    // to jest cała różnica wobec odrzuconego dziedziczenia po grupie.
    expect(PositionAttributeValue::where('position_attribute_id', $attribute->id)->count())
        ->toBe($positions->count());
});

test('the same action fired from a group gives the same result', function () {
    [$owner, $fishery, $positions] = ownerFisheryAndPositions();
    $attribute = PositionAttribute::factory()->create();
    $group = PositionGroup::factory()->create(['fishery_id' => $fishery->id]);
    $group->positions()->sync($positions->pluck('id')->all());

    $this->actingAs($owner);

    Livewire::withQueryParams(['fishery' => $fishery->id])
        ->test(ListPositionGroups::class)
        ->callTableAction(
            'setPositionAttribute',
            $group,
            data: [
                'position_attribute_id' => $attribute->id,
                'value_flag' => 1,
            ],
        );

    // Skrót z poziomu grupy woła TEN SAM kod z zaznaczeniem wypełnionym stanowiskami
    // grupy — wynik musi być nieodróżnialny od akcji z tabeli stanowisk.
    foreach ($positions as $position) {
        expect($position->attributeValues()->where('position_attribute_id', $attribute->id)->value('value_flag'))
            ->toEqual(1);
    }
});

test('a position outside the group is left untouched', function () {
    [$owner, $fishery, $positions] = ownerFisheryAndPositions();
    $outside = Position::factory()->create(['fishery_id' => $fishery->id, 'name' => 'Poza grupa']);
    $attribute = PositionAttribute::factory()->create();
    $group = PositionGroup::factory()->create(['fishery_id' => $fishery->id]);
    $group->positions()->sync($positions->pluck('id')->all());

    $this->actingAs($owner);

    Livewire::withQueryParams(['fishery' => $fishery->id])
        ->test(ListPositionGroups::class)
        ->callTableAction(
            'setPositionAttribute',
            $group,
            data: [
                'position_attribute_id' => $attribute->id,
                'value_flag' => 1,
            ],
        );

    expect($outside->attributeValues()->count())->toBe(0);
});

/**
 * ⚠️ Regresja z przeglądu implementacji (2026-09-20): `PositionAttributeValueMatchesType`
 * istniała i miała własny test jednostkowy — ale nie była wołana z ŻADNEJ ścieżki zapisu
 * (ADR-011 wymaga wywołania z każdej, także z akcji zbiorczej).
 *
 * ⚠️ Te testy celują w `applyAttributeAssignment()`, a NIE w `callTableBulkAction()`.
 * Powód jest konkretny: pole opcji jest `Select` z zawężoną listą, więc walidacja
 * formularza Filamenta odrzuca obcą opcję sama z siebie i test przez formularz
 * przechodził na zielono także z WYŁĄCZONĄ regułą — czyli nie sprawdzał niczego.
 * Bramka ma stać w kodzie zapisu, bo to on jest współdzielony przez oba wejścia.
 */
test('the write path refuses an option belonging to another attribute', function () {
    [$owner, , $positions] = ownerFisheryAndPositions();

    $attribute = PositionAttribute::factory()->choice()->create();
    $foreignOption = PositionAttributeOption::factory()->create();

    expect($foreignOption->position_attribute_id)->not->toBe($attribute->id);

    $this->actingAs($owner);

    // Akcja zbiorcza zamienia wyjątek na komunikat i zwraca ZERO objętych stanowisk.
    expect(PositionResource::applyAttributeAssignment(asEloquentCollection($positions), [
        'position_attribute_id' => $attribute->id,
        'position_attribute_option_id' => $foreignOption->id,
    ]))->toBe(0);

    // Odmowa jest CAŁKOWITA: żadne stanowisko nie dostaje wiersza, także pierwsze.
    expect(PositionAttributeValue::count())->toBe(0);
});

test('the write path refuses a non-numeric value for a numeric attribute', function () {
    [$owner, , $positions] = ownerFisheryAndPositions();
    $attribute = PositionAttribute::factory()->number()->create();

    $this->actingAs($owner);

    expect(PositionResource::applyAttributeAssignment(asEloquentCollection($positions), [
        'position_attribute_id' => $attribute->id,
        'value_number' => 'nie liczba',
    ]))->toBe(0);

    expect(PositionAttributeValue::count())->toBe(0);
});

test('the single position write path is guarded by the same rule', function () {
    [$owner, , $positions] = ownerFisheryAndPositions(1);

    $attribute = PositionAttribute::factory()->choice()->create();
    $foreignOption = PositionAttributeOption::factory()->create();

    $this->actingAs($owner);

    expect(fn () => app(PositionAttributeWriter::class)->writeForPosition(
        $positions->first(),
        [$attribute->id => $foreignOption->id],
    ))->toThrow(ValidationException::class);

    expect(PositionAttributeValue::count())->toBe(0);
});
