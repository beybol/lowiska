<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\User;

abstract class TestCase extends BaseTestCase
{
    private array $companyPermissions = [
        'view_any_company', 
        'view_company', 
        'create_company', 
        'update_company', 
        'delete_company',
    ];
    private array $fisheryPermissions = [
        'view_any_fishery', 
        'view_fishery', 
        'create_fishery', 
        'update_fishery', 
        'delete_fishery',
    ];
    private array $conveniencePermissions = [
        'view_any_convenience',
        'view_convenience',
        'create_convenience',
        'update_convenience',
        'delete_convenience',
    ];
    private array $countryPermissions = [
        'view_any_country',
        'view_country',
        'create_country',
        'update_country',
        'delete_country',
    ];
    private array $fishPermissions = [
        'view_any_fish',
        'view_fish',
        'create_fish',
        'update_fish',
        'delete_fish',
    ];
    private array $fisheryTypePermissions = [
        'view_any_fishery::type',
        'view_fishery::type',
        'create_fishery::type',
        'update_fishery::type',
        'delete_fishery::type',
    ];
    private array $fishingMethodPermissions = [
        'view_any_fishing::method',
        'view_fishing::method',
        'create_fishing::method',
        'update_fishing::method',
        'delete_fishing::method',
    ];
    private array $statePermissions = [
        'view_any_state',
        'view_state',
        'create_state',
        'update_state',
        'delete_state',
    ];
    private array $userPermissions = [
        'view_any_user',
        'view_user',
        'create_user',
        'update_user',
        'delete_user',
    ];

    protected function createOwner(array $attributes = []): User
    {
        $ownerRole = Role::firstOrCreate(['name' => 'Owner']);
        $permissions = array_merge(
            $this->companyPermissions,
            $this->fisheryPermissions,
        );

        foreach ($permissions as $permission) {
            $perm = Permission::firstOrCreate(['name' => $permission]);
            $ownerRole->givePermissionTo($perm);
        }

        $defaultAttributes = ['is_owner' => 1];
        $owner = User::factory()->create(
            array_merge($defaultAttributes, $attributes),
        );
        $owner->assignRole($ownerRole);

        return $owner;
    }

    protected function createSuperAdmin(array $attributes = []): User
    {
        $superAdminRole = Role::firstOrCreate(['name' => 'Super Admin']);
        $requiredPermissions = array_merge(
            $this->companyPermissions,
            $this->fisheryPermissions,
            $this->conveniencePermissions,
            $this->countryPermissions,
            $this->fishPermissions,
            $this->fisheryTypePermissions,
            $this->fishingMethodPermissions,
            $this->statePermissions,
            $this->userPermissions,
        );

        foreach ($requiredPermissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $allPermissions = Permission::all();
        $superAdminRole->syncPermissions($allPermissions);
        $defaultAttributes = ['is_admin' => 1];
        $admin = User::factory()->create(
            array_merge($defaultAttributes, $attributes),
        );
        $admin->assignRole($superAdminRole);

        return $admin;
    }
}
