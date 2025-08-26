<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class OwnerRoleSeeder extends Seeder
{
    public function run(): void
    {
        $role = Role::firstOrCreate(['name' => 'Owner']);
        $permissions = [
            // Company permissions
            'view_any_company', 
            'view_company', 
            'create_company', 
            'update_company', 
            'delete_company',
            // Fishery permissions
            'view_any_fishery', 
            'view_fishery', 
            'create_fishery', 
            'update_fishery', 
            'delete_fishery',
        ];

        foreach ($permissions as $permission) {
            $perm = Permission::firstOrCreate(['name' => $permission]);
            $role->givePermissionTo($perm);
        }
    }
}
