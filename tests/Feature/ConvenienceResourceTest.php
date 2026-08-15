<?php

namespace Tests\Feature;

use App\Filament\Resources\ConvenienceResource;
use App\Filament\Resources\ConvenienceResource\Pages\CreateConvenience;
use App\Models\Convenience;
use Livewire\Livewire;

/**
 * Zadanie 011: po utworzeniu rekordu standardowy CRUD Filamenta ma przekierować
 * na listę zasobu, nie na widok edycji (domyślne zachowanie `CreateRecord`).
 */
test('creating a convenience redirects to the resource list', function () {
    $admin = $this->createSuperAdmin();
    $this->actingAs($admin);

    Livewire::test(CreateConvenience::class)
        ->fillForm(['name' => 'Parking'])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(ConvenienceResource::getUrl('index'));

    expect(Convenience::where('name', 'Parking')->exists())->toBeTrue();
});
