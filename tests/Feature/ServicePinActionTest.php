<?php

namespace Tests\Feature;

use App\Enums\ServiceScope;
use App\Filament\Resources\FisheryResource\Pages\ManagePositionGroups;
use App\Filament\Resources\FisheryResource\Pages\ManagePositions;
use App\Filament\Resources\PositionResource;
use App\Models\AdditionalService;
use App\Models\Fishery;
use App\Models\Position;
use App\Models\PositionAttribute;
use App\Models\PositionAttributeValue;
use App\Models\PositionGroup;
use App\Models\User;
use App\Services\AdditionalServiceSync;
use App\Services\OwnerRoleProvisioner;
use App\Services\PositionServices;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * Akcje „Przypnij usługę" i „Odepnij usługę" — zamiast przypisania usługi do grupy (zadanie 020).
 *
 * ⚠️ Testy pilnują, że przypięcie ląduje WPROST na każdym stanowisku, a inne przypięcia zostają
 * nietknięte. `sync()` całej listy dałby ten sam wynik dla pojedynczej usługi i skasował pozostałe.
 */
beforeEach(function () {
    Filament::setCurrentPanel('owner');
});

/**
 * @return array{0: User, 1: Fishery, 2: Collection<int, Position>}
 */
function pinOwnerFishery(int $count = 3): array
{
    $owner = User::factory()->create(['name' => 'Wlasciciel Przypiec']);
    OwnerRoleProvisioner::addOwnerRole($owner);
    $fishery = Fishery::factory()->forUser($owner)->create();

    foreach (range(1, $count) as $i) {
        Position::factory()->create(['fishery_id' => $fishery->id, 'name' => 'Stanowisko '.$i]);
    }

    return [$owner, $fishery, Position::query()->where('fishery_id', $fishery->id)->orderBy('id')->get()];
}

function pinnableService(Fishery $fishery, string $name = 'Lodka'): AdditionalService
{
    return AdditionalService::factory()->create([
        'fishery_id' => $fishery->id,
        'name' => $name,
        'is_active' => true,
        'scope' => ServiceScope::SelectedPositions->value,
    ]);
}

test('the bulk pin writes the pin on every selected position and keeps their other pins', function () {
    [$owner, $fishery, $positions] = pinOwnerFishery();
    $boat = pinnableService($fishery, 'Lodka');
    $grill = pinnableService($fishery, 'Grill');
    $positions[0]->additionalServices()->attach($grill->id, ['is_required' => false]);

    $this->actingAs($owner);

    Livewire::test(ManagePositions::class, ['record' => $fishery->getKey()])
        ->callTableBulkAction('pinAdditionalService', $positions, data: [
            'additional_service_id' => $boat->id,
            'is_required' => true,
        ])
        ->assertHasNoTableBulkActionErrors();

    foreach ($positions as $position) {
        expect((bool) $position->additionalServices()->whereKey($boat->id)->first()?->pivot->is_required)->toBeTrue();
    }

    expect($positions[0]->additionalServices()->whereKey($grill->id)->exists())->toBeTrue();
});

test('pinning again updates is_required of an existing pin', function () {
    [$owner, $fishery, $positions] = pinOwnerFishery(1);
    $boat = pinnableService($fishery);
    $positions[0]->additionalServices()->attach($boat->id, ['is_required' => true]);

    $this->actingAs($owner);

    PositionResource::applyServicePin($positions, ['additional_service_id' => $boat->id, 'is_required' => false]);

    expect((bool) $positions[0]->additionalServices()->whereKey($boat->id)->first()->pivot->is_required)->toBeFalse()
        ->and($positions[0]->additionalServices()->count())->toBe(1);
});

test('the bulk unpin removes only that service and skips positions without it', function () {
    [$owner, $fishery, $positions] = pinOwnerFishery();
    $boat = pinnableService($fishery, 'Lodka');
    $grill = pinnableService($fishery, 'Grill');
    $positions[0]->additionalServices()->attach([$boat->id => ['is_required' => false], $grill->id => ['is_required' => false]]);
    $positions[1]->additionalServices()->attach($boat->id, ['is_required' => false]);

    $this->actingAs($owner);

    Livewire::test(ManagePositions::class, ['record' => $fishery->getKey()])
        ->callTableBulkAction('unpinAdditionalService', $positions, data: ['additional_service_id' => $boat->id])
        ->assertHasNoTableBulkActionErrors();

    expect($positions[0]->additionalServices()->pluck('additional_services.id')->all())->toBe([$grill->id])
        ->and($positions[1]->additionalServices()->count())->toBe(0)
        ->and($positions[2]->additionalServices()->count())->toBe(0);
});

test('the group shortcut pins and unpins on the positions of the group only', function () {
    [$owner, $fishery, $positions] = pinOwnerFishery();
    $outside = Position::factory()->create(['fishery_id' => $fishery->id, 'name' => 'Poza grupa']);
    $boat = pinnableService($fishery);
    $group = PositionGroup::factory()->create(['fishery_id' => $fishery->id]);
    $group->positions()->sync($positions->pluck('id')->all());

    $this->actingAs($owner);

    $page = Livewire::test(ManagePositionGroups::class, ['record' => $fishery->getKey()])
        ->callTableAction('pinAdditionalService', $group, data: ['additional_service_id' => $boat->id, 'is_required' => false])
        ->assertHasNoTableActionErrors();

    expect($boat->positions()->pluck('positions.id')->sort()->values()->all())->toBe($positions->pluck('id')->sort()->values()->all())
        ->and($outside->additionalServices()->count())->toBe(0);

    $page->callTableAction('unpinAdditionalService', $group, data: ['additional_service_id' => $boat->id])
        ->assertHasNoTableActionErrors();

    expect($boat->positions()->count())->toBe(0);
});

test('the pin warns about positions lacking a required attribute', function () {
    [$owner, $fishery, $positions] = pinOwnerFishery(3);
    $vehicle = PositionAttribute::factory()->create();
    $trailer = pinnableService($fishery, 'Przyczepa');
    $trailer->requiredAttributes()->attach($vehicle->id);
    PositionAttributeValue::query()->create([
        'position_id' => $positions[0]->id,
        'position_attribute_id' => $vehicle->id,
        'value_flag' => true,
    ]);
    PositionAttributeValue::query()->create([
        'position_id' => $positions[1]->id,
        'position_attribute_id' => $vehicle->id,
        'value_flag' => false,
    ]);

    $this->actingAs($owner);

    expect(PositionServices::countLackingRequiredAttributes($trailer, $positions))->toBe(2);

    Livewire::test(ManagePositions::class, ['record' => $fishery->getKey()])
        ->callTableBulkAction('pinAdditionalService', $positions, data: ['additional_service_id' => $trailer->id])
        ->assertNotified(trans_choice(
            'The service was pinned to :count position|The service was pinned to :count positions',
            3,
            ['count' => 3],
        ));
});

/*
 * Bramka przynależności — testy NEGATYWNE, wzorem `CrossTenantRelationTest`: identyfikatory
 * stanowisk i usługi pochodzą od klienta, a zawężenie listy opcji nie jest walidacją.
 */

test('a service of another fishery can not be pinned to my positions', function () {
    [$attacker, , $mine] = pinOwnerFishery(2);
    [, $theirFishery] = pinOwnerFishery(1);
    $theirService = pinnableService($theirFishery, 'Cudza usluga');

    $this->actingAs($attacker);

    expect(AdditionalServiceSync::pin($theirService->id, $mine, false))->toBe([])
        ->and($theirService->positions()->count())->toBe(0);
});

test('my service can not be pinned to positions of another fishery', function () {
    [$attacker, $myFishery] = pinOwnerFishery(1);
    [, , $theirs] = pinOwnerFishery(2);
    $myService = pinnableService($myFishery);

    $this->actingAs($attacker);

    expect(AdditionalServiceSync::pin($myService->id, $theirs, true))->toBe([])
        ->and($myService->positions()->count())->toBe(0);
});

test('a service can not be unpinned from positions of another fishery', function () {
    [$attacker] = pinOwnerFishery(1);
    [, $theirFishery, $theirs] = pinOwnerFishery(1);
    $theirService = pinnableService($theirFishery);
    $theirs[0]->additionalServices()->attach($theirService->id, ['is_required' => false]);

    $this->actingAs($attacker);

    expect(AdditionalServiceSync::unpin($theirService->id, $theirs))->toBe(0)
        ->and($theirService->positions()->count())->toBe(1);
});

test('a whole-fishery service can not be pinned by the action either', function () {
    [$owner, $fishery, $positions] = pinOwnerFishery(2);
    $everywhere = AdditionalService::factory()->create([
        'fishery_id' => $fishery->id,
        'scope' => ServiceScope::WholeFishery->value,
    ]);

    $this->actingAs($owner);

    expect(AdditionalServiceSync::pin($everywhere->id, $positions, true))->toBe([])
        ->and($everywhere->positions()->count())->toBe(0);
});

test('the service options of the action list only pinnable services of the fishery', function () {
    [$owner, $fishery] = pinOwnerFishery(1);
    [, $theirFishery] = pinOwnerFishery(1);
    $boat = pinnableService($fishery, 'Lodka');
    AdditionalService::factory()->create(['fishery_id' => $fishery->id, 'scope' => ServiceScope::WholeFishery->value, 'name' => 'Wszedzie']);
    pinnableService($theirFishery, 'Cudza');

    $this->actingAs($owner);

    $options = PositionResource::serviceAssignmentSchema($fishery->id, withRequired: true)[0]->getOptions();

    expect($options)->toBe([$boat->id => 'Lodka']);
});

/**
 * ⚠️ Relacja wiele-do-wielu nie loguje się sama (`dziennik-zmian.md` §4) — akcja zapisuje wpis
 * jawnie, po jednym na stanowisko, i tylko wtedy, gdy coś się zmieniło.
 */
test('pinning and unpinning leave one activity entry per changed position', function () {
    [$owner, $fishery, $positions] = pinOwnerFishery(2);
    $boat = pinnableService($fishery);
    $positions[0]->additionalServices()->attach($boat->id, ['is_required' => true]);

    $this->actingAs($owner);

    $entries = fn (Position $position) => Activity::query()
        ->where('subject_type', $position->getMorphClass())
        ->where('subject_id', $position->id)
        ->get()
        ->filter(fn ($activity): bool => isset($activity->attribute_changes['attributes']['additional_services']))
        ->values();

    // Pierwsze stanowisko ma już identyczne przypięcie — bez zmiany nie ma wpisu.
    AdditionalServiceSync::pin($boat->id, $positions, true);

    expect($entries($positions[0]))->toHaveCount(0)
        ->and($entries($positions[1]))->toHaveCount(1)
        ->and($entries($positions[1])[0]->attribute_changes['attributes']['additional_services'])->toBe([$boat->id => true])
        ->and($entries($positions[1])[0]->causer_id)->toBe($owner->id);

    AdditionalServiceSync::unpin($boat->id, $positions);

    expect($entries($positions[0]))->toHaveCount(1)
        ->and($entries($positions[0])[0]->attribute_changes['old']['additional_services'])->toBe([$boat->id => true])
        ->and($entries($positions[0])[0]->attribute_changes['attributes']['additional_services'])->toBe([]);
});
