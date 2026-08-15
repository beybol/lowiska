<?php

namespace Tests\Feature;

use App\Filament\Resources\FisheryTypeResource;
use App\Filament\Resources\FisheryTypeResource\Pages\CreateFisheryType;
use App\Models\FisheryType;
use Livewire\Livewire;

/**
 * Zadanie 011: po utworzeniu rekordu standardowy CRUD Filamenta ma przekierować
 * na listę zasobu, nie na widok edycji (domyślne zachowanie `CreateRecord`).
 */
test('creating a fishery type redirects to the resource list', function () {
    $admin = $this->createSuperAdmin();
    $this->actingAs($admin);

    Livewire::test(CreateFisheryType::class)
        ->fillForm(['name' => 'Staw'])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(FisheryTypeResource::getUrl('index'));

    expect(FisheryType::where('name', 'Staw')->exists())->toBeTrue();
});
