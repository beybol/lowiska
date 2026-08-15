<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Regresja dla zadania 008: MakeAdmin tworzyło konto z rolą super_admin bez
 * żadnych uprawnień (nic w bazie, bo shield:generate nigdy nie zostało
 * uruchomione) — administrator logował się do panelu i nie widział niczego.
 */
function superAdminRoleName(): string
{
    return config('filament-shield.super_admin.name');
}

test('fresh system: new admin gets the full set of Shield permissions', function () {
    expect(Permission::count())->toBe(0);

    Artisan::call('MakeAdmin', ['name' => 'Jan', 'surname' => 'Kowalski', 'email' => 'jan@example.com']);

    $user = User::where('email', 'jan@example.com')->first();
    $role = Role::where('name', superAdminRoleName())->first();

    expect($user)->not->toBeNull();
    expect($user->is_admin)->toBeTruthy();
    expect($user->hasRole($role))->toBeTrue();
    expect(Permission::count())->toBeGreaterThan(0);
    expect($role->permissions()->count())->toBe(Permission::count());
});

test('existing user is promoted and role permissions are completed', function () {
    $user = User::factory()->create(['email' => 'existing@example.com', 'is_admin' => false]);

    Artisan::call('MakeAdmin', ['name' => 'Any', 'surname' => 'Name', 'email' => 'existing@example.com']);

    $user->refresh();
    $role = Role::where('name', superAdminRoleName())->first();

    expect($user->is_admin)->toBeTruthy();
    expect($user->hasRole($role))->toBeTrue();
    expect($role->permissions()->count())->toBe(Permission::count());
});

test('running against an incomplete role completes it, without touching existing permissions', function () {
    // Odtwarza dzisiejszy stan produkcyjny: rola istnieje, ale nie ma kompletu uprawnień.
    $role = Role::firstOrCreate(['name' => superAdminRoleName()]);
    $onePermission = Permission::firstOrCreate(['name' => 'view_any:fish']);
    $role->syncPermissions([$onePermission]);

    expect($role->permissions()->count())->toBe(1);

    Artisan::call('MakeAdmin', ['name' => 'Jan', 'surname' => 'Kowalski', 'email' => 'jan2@example.com']);

    $role->refresh();
    expect($role->permissions()->count())->toBe(Permission::count());
    expect($role->hasPermissionTo('view_any:fish'))->toBeTrue();
});

test('re-running on an already-complete role is idempotent', function () {
    Artisan::call('MakeAdmin', ['name' => 'Jan', 'surname' => 'Kowalski', 'email' => 'jan@example.com']);

    $role = Role::where('name', superAdminRoleName())->first();
    $permissionCountAfterFirstRun = $role->permissions()->count();
    $totalPermissionsAfterFirstRun = Permission::count();

    // `Artisan::output()` śledzi ostatnie wywołanie Artisan::call() w procesie —
    // ponieważ MakeAdminCommand samo woła shield:generate wewnętrznie, ta globalna
    // funkcja pomocnicza zwróciłaby wyjście zagnieżdżonego wywołania, nie własny
    // komunikat komendy. `$this->artisan()` przechwytuje strumień wyjścia właściwej,
    // testowanej komendy niezależnie od tego, co ona woła wewnątrz.
    $this->artisan('MakeAdmin', ['name' => 'Jan', 'surname' => 'Kowalski', 'email' => 'jan@example.com'])
        ->expectsOutputToContain('already had the full set')
        ->assertExitCode(0);

    $role->refresh();

    expect(Permission::count())->toBe($totalPermissionsAfterFirstRun);
    expect($role->permissions()->count())->toBe($permissionCountAfterFirstRun);
});
