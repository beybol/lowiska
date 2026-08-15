<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use Livewire\Livewire;

/**
 * Zadanie 011: po utworzeniu rekordu standardowy CRUD Filamenta ma przekierować
 * na listę zasobu, nie na widok edycji (domyślne zachowanie `CreateRecord`).
 *
 * ⚠️ Nie testujemy przez pełny cykl `fillForm()->call('create')`, jak w pozostałych
 * dziewięciu zasobach — `UserResource::form()` nie ma pola `password`, a kolumna
 * `users.password` nie ma wartości domyślnej w bazie, więc rzeczywiste utworzenie
 * użytkownika przez ten formularz panelu **kończy się błędem SQL** (`Field 'password'
 * doesn't have a default value`). To realny, przedwdrożony defekt niezwiązany z tym
 * zadaniem (zakres 011 to wyłącznie redirect) — zgłoszony osobno, nie naprawiany tutaj.
 * Test woła `getRedirectUrl()` bezpośrednio na zamontowanym komponencie, żeby
 * zweryfikować samą zmianę z tego zadania bez wchodzenia w niepowiązany błąd.
 */
test('creating a user redirects to the resource list', function () {
    $admin = $this->createSuperAdmin();
    $this->actingAs($admin);

    $url = Livewire::test(CreateUser::class)->instance()->getRedirectUrl();

    expect($url)->toBe(UserResource::getUrl('index'));
});
