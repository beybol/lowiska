<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\User;

abstract class TestCase extends BaseTestCase
{
    protected function createSuperAdminOwner(array $attributes = []): User
    {
        $superAdminRole = Role::firstOrCreate(['name' => 'super_admin']);
        $permissions = [
            'view_any_company',
            'view_company', 
            'create_company',
            'update_company',
            'delete_company',
        ];
        
        foreach ($permissions as $permission) {
            $perm = Permission::firstOrCreate(['name' => $permission]);
            $superAdminRole->givePermissionTo($perm);
        }

        $defaultAttributes = ['is_owner' => 1];
        $user = User::factory()->create(
            array_merge($defaultAttributes, $attributes),
        );
        $user->assignRole($superAdminRole);
        
        return $user;
    }
}
