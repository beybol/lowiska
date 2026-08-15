<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

/**
 * Zadanie 011: po utworzeniu rekordu standardowy CRUD Filamenta ma przekierować
 * na listę zasobu, nie na widok edycji (domyślne zachowanie `CreateRecord`).
 */
test('creating a user redirects to the resource list', function () {
    $admin = $this->createSuperAdmin();
    $this->actingAs($admin);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Jan',
            'surname' => 'Testowy',
            'email' => 'jan.testowy@example.com',
            'password' => 'password123',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(UserResource::getUrl('index'));

    expect(User::where('email', 'jan.testowy@example.com')->exists())->toBeTrue();
});

/**
 * Regresja: formularz `UserResource` nie miał pola `password`, a kolumna `users.password`
 * nie ma wartości domyślnej — tworzenie użytkownika przez panel kończyło się błędem SQL
 * (odkryte przy pisaniu testu wyżej). `password => 'hashed'` w `User::$casts` haszuje
 * wartość przy zapisie, więc pole w formularzu przekazuje surowy tekst bez `Hash::make()`.
 */
test('password is required on create and hashed', function () {
    $admin = $this->createSuperAdmin();
    $this->actingAs($admin);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Anna',
            'surname' => 'Testowa',
            'email' => 'anna.testowa@example.com',
            'password' => 'sekretne-haslo',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::where('email', 'anna.testowa@example.com')->first();

    expect($user)->not->toBeNull();
    expect(Hash::check('sekretne-haslo', $user->password))->toBeTrue();
});

/**
 * `form()` jest współdzielone między CreateUser i EditUser — pole hasła musi być
 * opcjonalne przy edycji i nie może zerować istniejącego hasła, gdy zostaje puste.
 */
test('editing a user without touching the password field keeps it intact', function () {
    $admin = $this->createSuperAdmin();
    $this->actingAs($admin);

    $user = User::factory()->create(['password' => Hash::make('original-password')]);
    $originalHash = $user->password;

    Livewire::test(EditUser::class, ['record' => $user->getKey()])
        ->fillForm(['name' => 'Zmienione imię'])
        ->call('save')
        ->assertHasNoFormErrors();

    $user->refresh();

    expect($user->name)->toBe('Zmienione imię');
    expect($user->password)->toBe($originalHash);
    expect(Hash::check('original-password', $user->password))->toBeTrue();
});
