<?php

namespace Tests\Feature;

use App\Filament\Resources\CurrencyResource;
use App\Filament\Resources\CurrencyResource\Pages\CreateCurrency;
use App\Models\Currency;
use Livewire\Livewire;

/**
 * Zadanie 011: po utworzeniu rekordu standardowy CRUD Filamenta ma przekierować
 * na listę zasobu, nie na widok edycji (domyślne zachowanie `CreateRecord`).
 */
test('creating a currency redirects to the resource list', function () {
    $admin = $this->createSuperAdmin();
    $this->actingAs($admin);

    Livewire::test(CreateCurrency::class)
        ->fillForm(['name' => 'PLN'])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(CurrencyResource::getUrl('index'));

    expect(Currency::where('name', 'PLN')->exists())->toBeTrue();
});
