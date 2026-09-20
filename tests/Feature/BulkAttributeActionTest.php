<?php

namespace Tests\Feature;

use App\Filament\Resources\PositionGroupResource\Pages\ListPositionGroups;
use App\Filament\Resources\PositionResource\Pages\ListPositions;
use App\Models\Fishery;
use App\Models\Position;
use App\Models\PositionAttribute;
use App\Models\PositionAttributeValue;
use App\Models\PositionGroup;
use App\Models\User;
use App\Services\OwnerRoleProvisioner;
use Filament\Facades\Filament;
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
