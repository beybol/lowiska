<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OwnerRoleProvisioner;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Nadanie roli `owner` kontra zdefiniowanie jej uprawnień — dwie różne operacje.
 *
 * ⚠️ Testy powstały po security-review (2026-09-20). Wcześniej `addOwnerRole()`
 * kończyło się `syncPermissions()`, a woła je ścieżka rejestracji i logowania —
 * więc dowolny użytkownik jednym żądaniem przywracał uprawnienia globalnej roli
 * do literałów z kodu, kasując zmiany administratora.
 */
test('assigning the role to a second user does not rewrite its permissions', function () {
    $first = User::factory()->create();
    OwnerRoleProvisioner::addOwnerRole($first);

    $role = Role::where('name', 'owner')->firstOrFail();

    // Administrator zawęża rolę w panelu Shielda.
    $role->revokePermissionTo('delete_any:company');
    expect($role->fresh()->hasPermissionTo('delete_any:company'))->toBeFalse();

    // Kolejny użytkownik rejestruje się i dostaje rolę.
    $second = User::factory()->create();
    OwnerRoleProvisioner::addOwnerRole($second);

    // ⚠️ Zawężenie MA PRZETRWAĆ. To jest cała treść tej poprawki.
    expect($role->fresh()->hasPermissionTo('delete_any:company'))->toBeFalse()
        ->and($second->fresh()->hasRole('owner'))->toBeTrue();
});

test('an extra permission added by an administrator survives a new registration', function () {
    $first = User::factory()->create();
    OwnerRoleProvisioner::addOwnerRole($first);

    $role = Role::where('name', 'owner')->firstOrFail();
    Permission::findOrCreate('view_any:fish');
    $role->givePermissionTo('view_any:fish');

    $second = User::factory()->create();
    OwnerRoleProvisioner::addOwnerRole($second);

    expect($role->fresh()->hasPermissionTo('view_any:fish'))->toBeTrue();
});

test('the first call still bootstraps the role with its default permissions', function () {
    expect(Role::where('name', 'owner')->exists())->toBeFalse();

    $user = User::factory()->create();
    OwnerRoleProvisioner::addOwnerRole($user);

    $role = Role::where('name', 'owner')->firstOrFail();

    // Kontrola pozytywna — bez niej dwa testy wyżej przeszłyby też przy metodzie,
    // która nie nadaje żadnych uprawnień.
    expect($role->hasPermissionTo('view_any:fishery'))->toBeTrue()
        ->and($user->fresh()->hasRole('owner'))->toBeTrue();
});
