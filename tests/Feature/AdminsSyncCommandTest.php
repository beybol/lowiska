<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OwnerRoleProvisioner;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * `admins:sync` — `is_admin` jedynym źródłem prawdy o super adminie (ADR-018, zadanie 025).
 *
 * ⚠️ Testy pilnują KIERUNKU zależności: flaga → rola. Konto z flagą dostaje rolę, konto bez flagi
 * ją traci — także wtedy, gdy ktoś nadał ją ręcznie w panelu Shielda.
 */
function adminRole(): Role
{
    return Role::firstOrCreate(['name' => config('filament-shield.super_admin.name')]);
}

test('on a fresh system every is_admin account gets the role with every permission', function () {
    $first = User::factory()->create(['is_admin' => true]);
    $second = User::factory()->create(['is_admin' => true]);
    $regular = User::factory()->create(['is_admin' => false]);

    $this->artisan('admins:sync')->assertSuccessful();

    $role = adminRole();

    expect(Permission::count())->toBeGreaterThan(0)
        ->and($role->permissions()->count())->toBe(Permission::count())
        ->and($first->fresh()->hasRole($role))->toBeTrue()
        ->and($second->fresh()->hasRole($role))->toBeTrue()
        ->and($regular->fresh()->hasRole($role))->toBeFalse();
});

test('a permission added later reaches the role without MakeAdmin', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $this->artisan('admins:sync')->assertSuccessful();

    // Symuluje uprawnienie nowego zasobu, którego rola jeszcze nie ma.
    Permission::create(['name' => 'view_any:brand_new_resource']);

    $this->artisan('admins:sync')->assertSuccessful();

    expect($admin->fresh()->can('view_any:brand_new_resource'))->toBeTrue()
        ->and(adminRole()->permissions()->count())->toBe(Permission::count());
});

test('the flag decides: removing is_admin takes the role away, setting it gives the role', function () {
    $former = User::factory()->create(['is_admin' => false]);
    $former->assignRole(adminRole());
    $newcomer = User::factory()->create(['is_admin' => true]);

    $this->artisan('admins:sync')
        ->expectsOutputToContain("Role revoked from {$former->email}")
        ->expectsOutputToContain("Role granted to {$newcomer->email}")
        ->assertSuccessful();

    expect($former->fresh()->hasRole(adminRole()))->toBeFalse()
        ->and($newcomer->fresh()->hasRole(adminRole()))->toBeTrue();
});

test('a manual change of the super admin role is undone', function () {
    User::factory()->create(['is_admin' => true]);
    $this->artisan('admins:sync')->assertSuccessful();

    $role = adminRole();
    $role->revokePermissionTo($role->permissions()->first());

    $this->artisan('admins:sync')->assertSuccessful();

    expect($role->fresh()->permissions()->count())->toBe(Permission::count());
});

test('a second run with no changes changes nothing and says so', function () {
    User::factory()->create(['is_admin' => true]);
    $this->artisan('admins:sync')->assertSuccessful();

    $permissions = Permission::count();

    $this->artisan('admins:sync')
        ->expectsOutputToContain('Nothing changed.')
        ->assertSuccessful();

    expect(Permission::count())->toBe($permissions);
});

test('no admin account is a warning with exit code 0', function () {
    User::factory()->create(['is_admin' => false]);

    $this->artisan('admins:sync')
        ->expectsOutputToContain('No account has is_admin')
        ->assertExitCode(0);

    expect(adminRole()->permissions()->count())->toBe(Permission::count());
});

test('the owner role and its permissions are left untouched', function () {
    $owner = User::factory()->create(['is_admin' => false]);
    OwnerRoleProvisioner::addOwnerRole($owner);
    $ownerRole = Role::where('name', 'owner')->firstOrFail();
    $before = $ownerRole->permissions()->pluck('name')->sort()->values()->all();

    $this->artisan('admins:sync')->assertSuccessful();

    expect($ownerRole->fresh()->permissions()->pluck('name')->sort()->values()->all())->toBe($before)
        ->and($owner->fresh()->hasRole('owner'))->toBeTrue()
        ->and($owner->fresh()->hasRole(adminRole()))->toBeFalse();
});
