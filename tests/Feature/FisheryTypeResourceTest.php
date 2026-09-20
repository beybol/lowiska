<?php

namespace Tests\Feature;

use App\Filament\Resources\FisheryTypeResource\Pages\ManageFisheryTypes;
use App\Models\FisheryType;
use Livewire\Livewire;

/**
 * ⚠️ Zadanie 022: `FisheryTypeResource` stoi na `ManageRecords` — tworzenie i edycja
 * biegną w MODALU akcji nagłówkowej, nie na osobnej stronie `Create*`. Ta strona
 * była zarejestrowana obok, ale nieosiągalna z interfejsu (przegląd implementacji,
 * 2026-09-20) — test woła teraz dokładnie tę ścieżkę, którą operator faktycznie
 * używa: akcję `create` na stronie `Manage*`.
 */
test('creating a fishery type through the modal action adds it to the list', function () {
    $admin = $this->createSuperAdmin();
    $this->actingAs($admin);

    Livewire::test(ManageFisheryTypes::class)
        ->callAction('create', data: ['name' => 'Staw'])
        ->assertHasNoActionErrors();

    expect(FisheryType::where('name', 'Staw')->exists())->toBeTrue();
});
