<?php

namespace Tests\Feature;

use App\Filament\Resources\FishingMethodResource;
use App\Filament\Resources\FishingMethodResource\Pages\CreateFishingMethod;
use App\Models\FishingMethod;
use Livewire\Livewire;

/**
 * Zadanie 011: po utworzeniu rekordu standardowy CRUD Filamenta ma przekierować
 * na listę zasobu, nie na widok edycji (domyślne zachowanie `CreateRecord`).
 */
test('creating a fishing method redirects to the resource list', function () {
    $admin = $this->createSuperAdmin();
    $this->actingAs($admin);

    Livewire::test(CreateFishingMethod::class)
        ->fillForm(['name' => 'Spinning'])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(FishingMethodResource::getUrl('index'));

    expect(FishingMethod::where('name', 'Spinning')->exists())->toBeTrue();
});
