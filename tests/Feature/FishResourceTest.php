<?php

namespace Tests\Feature;

use App\Filament\Resources\FishResource\Pages\ManageFish;
use App\Models\Fish;
use Livewire\Livewire;

/**
 * ⚠️ Zadanie 022: `FishResource` stoi na `ManageRecords` — tworzenie i edycja biegną
 * w MODALU akcji nagłówkowej / wierszowej, nie na osobnych stronach `Create*`/`Edit*`.
 * Te strony były zarejestrowane obok, ale nieosiągalne z interfejsu (przegląd
 * implementacji, 2026-09-20) — test woła teraz dokładnie tę ścieżkę, którą operator
 * faktycznie używa: akcję `create` na stronie `Manage*`.
 */
test('creating a fish through the modal action adds it to the list', function () {
    $admin = $this->createSuperAdmin();
    $this->actingAs($admin);

    Livewire::test(ManageFish::class)
        ->callAction('create', data: ['name' => 'Karp'])
        ->assertHasNoActionErrors();

    expect(Fish::where('name', 'Karp')->exists())->toBeTrue();
});
