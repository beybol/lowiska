<?php

namespace Tests\Feature;

use App\Filament\Resources\PositionGroupResource\Pages\EditPositionGroup;
use App\Filament\Resources\PositionResource\Pages\EditPosition;
use App\Models\AdditionalService;
use App\Models\Fishery;
use App\Models\Position;
use App\Models\PositionGroup;
use App\Models\User;
use App\Services\AdditionalServiceSync;
use App\Services\OwnerRoleProvisioner;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Relacje wiele-do-wielu zapisywane z formularza: wartość pola jest stanem komponentu,
 * czyli danymi od klienta, a zawężenie `options()` NIE jest walidacją.
 *
 * ⚠️ Te testy powstały po security-review (2026-09-20), który wykazał sondą, że bez
 * reguły `RecordsBelongToFishery` właściciel podpinał stanowiska CUDZEGO łowiska do
 * własnej grupy — a stamtąd akcja zbiorcza „Ustaw cechę" zapisywała wiersze na tych
 * stanowiskach. Filament nie sprawdza, czy przysłane identyfikatory pochodzą
 * z wyrenderowanej listy; sprawdzone wprost, nie założone.
 *
 * ⚠️ Testy są NEGATYWNE i tak mają zostać (`autoryzacja.md` §4). Wariant „sprawdźmy,
 * że własne stanowisko da się podpiąć" przechodzi także przy całkowicie usuniętej
 * regule i niczego nie pilnuje.
 */
beforeEach(function () {
    Filament::setCurrentPanel('owner');
});

/**
 * @return array{0: User, 1: Fishery, 2: Fishery}
 */
function twoOwners(): array
{
    $attacker = User::factory()->create(['name' => 'Wlasciciel A']);
    OwnerRoleProvisioner::addOwnerRole($attacker);

    $victim = User::factory()->create(['name' => 'Wlasciciel B']);
    OwnerRoleProvisioner::addOwnerRole($victim);

    return [
        $attacker,
        Fishery::factory()->forUser($attacker)->create(),
        Fishery::factory()->forUser($victim)->create(),
    ];
}

test('a position from another fishery can not be attached to my group', function () {
    [$attacker, $mine, $theirs] = twoOwners();

    $group = PositionGroup::factory()->create(['fishery_id' => $mine->id]);
    $foreign = Position::factory()->create(['fishery_id' => $theirs->id, 'name' => 'Cudze stanowisko']);

    $this->actingAs($attacker);

    Livewire::withQueryParams(['fishery' => $mine->id])
        ->test(EditPositionGroup::class, ['record' => $group->getKey()])
        ->fillForm([
            'fishery_id' => $mine->id,
            'name' => 'Moja grupa',
            'positions' => [$foreign->id],
        ])
        ->call('save')
        ->assertHasFormErrors(['positions']);

    expect($group->fresh()->positions()->pluck('positions.id')->all())->toBe([]);
});

test('my position can not be pushed into a group of another fishery', function () {
    [$attacker, $mine, $theirs] = twoOwners();

    $position = Position::factory()->create(['fishery_id' => $mine->id, 'name' => 'Moje stanowisko']);
    $foreignGroup = PositionGroup::factory()->create(['fishery_id' => $theirs->id]);

    $this->actingAs($attacker);

    Livewire::withQueryParams(['fishery' => $mine->id])
        ->test(EditPosition::class, ['record' => $position->getKey()])
        ->fillForm([
            'fishery_id' => $mine->id,
            'name' => 'Moje stanowisko',
            'max_anglers' => 2,
            'groups' => [$foreignGroup->id],
        ])
        ->call('save')
        ->assertHasFormErrors(['groups']);

    expect($position->fresh()->groups()->count())->toBe(0);
});

test('an additional service from another fishery is dropped on sync', function () {
    [, $mine, $theirs] = twoOwners();

    $position = Position::factory()->create(['fishery_id' => $mine->id]);
    $ownService = AdditionalService::factory()->create(['fishery_id' => $mine->id]);
    $foreignService = AdditionalService::factory()->create(['fishery_id' => $theirs->id]);

    // ⚠️ Wołanie usługi WPROST, nie przez formularz: repeater nie ma pojedynczego pola,
    // do którego dałoby się przypiąć błąd, więc bramka stoi w jedynym wejściu zapisu.
    AdditionalServiceSync::syncAdditionalServices($position, [
        ['additional_service_id' => $ownService->id, 'is_required' => false],
        ['additional_service_id' => $foreignService->id, 'is_required' => true],
    ]);

    // Własna usługa zostaje, cudza wypada — bez wywracania całego zapisu.
    expect($position->additionalServices()->pluck('additional_services.id')->all())
        ->toBe([$ownService->id]);
});
