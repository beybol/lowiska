<?php

namespace Tests\Feature;

use App\Filament\Resources\StateResource;
use App\Filament\Resources\StateResource\Pages\CreateState;
use App\Models\Country;
use App\Models\State;
use Livewire\Livewire;

/**
 * Zadanie 011: po utworzeniu rekordu standardowy CRUD Filamenta ma przekierować
 * na listę zasobu, nie na widok edycji (domyślne zachowanie `CreateRecord`).
 */
test('creating a state redirects to the resource list', function () {
    $admin = $this->createSuperAdmin();
    $this->actingAs($admin);

    $country = Country::factory()->create(['is_active' => true]);

    Livewire::test(CreateState::class)
        ->fillForm([
            'name' => 'Mazowieckie',
            'country_id' => $country->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(StateResource::getUrl('index'));

    expect(State::where('name', 'Mazowieckie')->exists())->toBeTrue();
});
