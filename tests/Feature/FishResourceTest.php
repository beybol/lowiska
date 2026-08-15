<?php

namespace Tests\Feature;

use App\Filament\Resources\FishResource;
use App\Filament\Resources\FishResource\Pages\CreateFish;
use App\Models\Fish;
use Livewire\Livewire;

/**
 * Zadanie 011: po utworzeniu rekordu standardowy CRUD Filamenta ma przekierować
 * na listę zasobu, nie na widok edycji (domyślne zachowanie `CreateRecord`).
 */
test('creating a fish redirects to the resource list', function () {
    $admin = $this->createSuperAdmin();
    $this->actingAs($admin);

    Livewire::test(CreateFish::class)
        ->fillForm(['name' => 'Karp'])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(FishResource::getUrl('index'));

    expect(Fish::where('name', 'Karp')->exists())->toBeTrue();
});
