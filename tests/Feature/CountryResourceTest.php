<?php

namespace Tests\Feature;

use App\Filament\Resources\CountryResource;
use App\Filament\Resources\CountryResource\Pages\CreateCountry;
use App\Models\Country;
use Livewire\Livewire;

/**
 * Zadanie 011: po utworzeniu rekordu standardowy CRUD Filamenta ma przekierować
 * na listę zasobu, nie na widok edycji (domyślne zachowanie `CreateRecord`).
 */
test('creating a country redirects to the resource list', function () {
    $admin = $this->createSuperAdmin();
    $this->actingAs($admin);

    Livewire::test(CreateCountry::class)
        ->fillForm([
            'prefix' => '+48',
            'country_name' => 'Polska testowa',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(CountryResource::getUrl('index'));

    expect(Country::where('country_name', 'Polska testowa')->exists())->toBeTrue();
});
