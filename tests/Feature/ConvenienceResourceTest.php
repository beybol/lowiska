<?php

namespace Tests\Feature;

use App\Filament\Resources\ConvenienceResource\Pages\ManageConveniences;
use App\Models\Convenience;
use Livewire\Livewire;

/**
 * ⚠️ Zadanie 022: `ConvenienceResource` stoi na `ManageRecords` — tworzenie i edycja
 * biegną w MODALU akcji nagłówkowej, nie na osobnej stronie `Create*`. Ta strona
 * była zarejestrowana obok, ale nieosiągalna z interfejsu (przegląd implementacji,
 * 2026-09-20) — test woła teraz dokładnie tę ścieżkę, którą operator faktycznie
 * używa: akcję `create` na stronie `Manage*`.
 */
test('creating a convenience through the modal action adds it to the list', function () {
    $admin = $this->createSuperAdmin();
    $this->actingAs($admin);

    Livewire::test(ManageConveniences::class)
        ->callAction('create', data: ['name' => 'Parking'])
        ->assertHasNoActionErrors();

    expect(Convenience::where('name', 'Parking')->exists())->toBeTrue();
});
