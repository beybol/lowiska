<?php

namespace Tests\Feature;

use App\Filament\Resources\FisheryResource\Pages\CreateFishery;
use App\Filament\Resources\FisheryResource\Pages\EditFishery;
use App\Models\Company;
use App\Models\Fishery;
use App\Models\FishingMethod;
use App\Models\State;
use App\Models\User;
use App\Services\OwnerRoleProvisioner;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Metody połowu łowiska zapisują się przez relację wiele-do-wielu `fishingMethods`.
 *
 * ⚠️ Regresja: pole `fishing_methods` miało same opcje bez `relationship()`, a takiej kolumny nie ma —
 * formularz przyjmował wybór i po cichu go gubił. Test sprawdza zapis, odczyt w formularzu i odznaczenie.
 */
test('the fishing methods picked in the form are saved, shown again and can be unpicked', function () {
    $admin = $this->createSuperAdmin();
    $fishery = Fishery::factory()->create();
    [$carp, $float, $feeder] = FishingMethod::factory()->count(3)->create()->all();

    Livewire::actingAs($admin)
        ->test(EditFishery::class, ['record' => $fishery->getRouteKey()])
        ->fillForm(['fishing_methods' => [$carp->id, $feeder->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($fishery->fresh()->fishingMethods->pluck('id')->sort()->values()->all())
        ->toBe(collect([$carp->id, $feeder->id])->sort()->values()->all());

    $form = Livewire::actingAs($admin)->test(EditFishery::class, ['record' => $fishery->getRouteKey()]);
    expect(collect($form->get('data.fishing_methods'))->map(fn ($id): int => (int) $id)->sort()->values()->all())
        ->toBe(collect([$carp->id, $feeder->id])->sort()->values()->all());

    $form->fillForm(['fishing_methods' => [$float->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($fishery->fresh()->fishingMethods->pluck('id')->all())->toBe([$float->id]);
});

/**
 * Formularz jest wspólny — ta sama regresja dotyczyła edycji w panelu właściciela i ostatniego kroku kreatora.
 */
test('the owner saves the fishing methods of their own fishery', function () {
    $owner = User::factory()->create();
    OwnerRoleProvisioner::addOwnerRole($owner);
    $this->actingAs($owner);
    Filament::setCurrentPanel('owner');
    $fishery = Fishery::factory()->forUser($owner)->create([
        'company_id' => Company::factory()->forUser($owner)->create(['is_verified' => true])->id,
    ]);
    $method = FishingMethod::factory()->create();

    Livewire::test(EditFishery::class, ['record' => $fishery->getRouteKey()])
        ->fillForm(['fishing_methods' => [$method->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($fishery->fresh()->fishingMethods->pluck('id')->all())->toBe([$method->id]);
});

test('the fishing methods picked in the last step of the owner wizard are saved', function () {
    $owner = User::factory()->create();
    OwnerRoleProvisioner::addOwnerRole($owner);
    $this->actingAs($owner);
    Filament::setCurrentPanel('owner');
    $company = Company::factory()->forUser($owner)->create(['is_verified' => true]);
    $method = FishingMethod::factory()->create();

    Livewire::test(CreateFishery::class)
        ->fillForm([
            'company_mode' => 'existing',
            'company_id' => $company->id,
            'name' => 'Łowisko z metodą',
            'state_id' => State::factory()->create()->id,
            'town' => 'Warszawa',
            'street' => 'Kwiatowa',
            'building_number' => '12',
            'zip_code' => '00-001',
            'area' => 5,
            'fishing_methods' => [$method->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Fishery::query()->where('name', 'Łowisko z metodą')->firstOrFail()->fishingMethods->pluck('id')->all())
        ->toBe([$method->id]);
});
