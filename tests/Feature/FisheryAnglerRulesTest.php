<?php

namespace Tests\Feature;

use App\Filament\Resources\FisheryResource\Pages\CreateFishery;
use App\Filament\Resources\FisheryResource\Pages\EditFishery;
use App\Models\Company;
use App\Models\Fishery;
use App\Models\State;
use App\Models\User;
use App\Services\OwnerRoleProvisioner;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Wymagania wobec wędkarza jako dane łowiska — karta, wędki, no-kill, ogniska (zadanie 021).
 *
 * ⚠️ Testy pilnują TRZECIEGO STANU: niewypełnione łowisko nie może ogłaszać „karta niewymagana"
 * ani „można zabrać rybę". A „nie" z bazy wraca do formularza jako „nie", nie jako „nie podano"
 * (`panel-admina.md` §2 — pułapka `(string) false`).
 */
function anglerRulesOwner(): User
{
    $owner = User::factory()->create();
    OwnerRoleProvisioner::addOwnerRole($owner);
    test()->actingAs($owner);
    Filament::setCurrentPanel('owner');

    return $owner;
}

test('a fishery starts with every angler rule not specified', function () {
    $fishery = Fishery::factory()->create()->fresh();

    expect($fishery->fishing_license_required)->toBeNull()
        ->and($fishery->rods_included)->toBeNull()
        ->and($fishery->no_kill)->toBeNull()
        ->and($fishery->campfires_banned)->toBeNull();
});

test('the wizard saves the angler rules together with the fishery', function () {
    $owner = anglerRulesOwner();
    $company = Company::factory()->forUser($owner)->create(['is_verified' => true]);
    $state = State::factory()->create();

    Livewire::test(CreateFishery::class)
        ->fillForm([
            'company_mode' => 'existing',
            'company_id' => $company->id,
            'name' => 'Lowisko z regulami',
            'state_id' => $state->id,
            'town' => 'Warszawa',
            'street' => 'Kwiatowa',
            'building_number' => '12',
            'zip_code' => '00-001',
            'area' => 5,
            'positions_count' => 10,
            'fishing_license_required' => 1,
            'rods_included' => 2,
            'no_kill' => 1,
            'campfires_banned' => 0,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $fishery = Fishery::query()->where('name', 'Lowisko z regulami')->firstOrFail();

    expect($fishery->fishing_license_required)->toBeTrue()
        ->and($fishery->rods_included)->toBe(2)
        ->and($fishery->no_kill)->toBeTrue()
        ->and($fishery->campfires_banned)->toBeFalse();
});

test('a no comes back to the edit form as no, and not specified stays empty', function () {
    $owner = anglerRulesOwner();
    $fishery = Fishery::factory()->forUser($owner)->create([
        'fishing_license_required' => true,
        'campfires_banned' => false,
        'no_kill' => null,
    ]);

    $data = Livewire::test(EditFishery::class, ['record' => $fishery->getRouteKey()])->get('data');

    // Filament trzyma stan `Select`a jako łańcuch — ważne, że „nie" to '0', a nie pusty stan.
    expect($data['fishing_license_required'])->toBe('1')
        ->and($data['campfires_banned'])->toBe('0')
        ->and($data['no_kill'])->toBeNull()
        ->and($data['rods_included'])->toBeNull();
});

test('clearing a rule stores not specified, not no', function () {
    $owner = anglerRulesOwner();
    $company = Company::factory()->forUser($owner)->create();
    $fishery = Fishery::factory()->forUser($owner)->create(['company_id' => $company->id, 'no_kill' => true]);

    Livewire::test(EditFishery::class, ['record' => $fishery->getRouteKey()])
        ->fillForm(['no_kill' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($fishery->fresh()->no_kill)->toBeNull();
});
