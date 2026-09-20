<?php

namespace App\Services;

use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Nadanie roli `owner` wraz z kompletem uprawnień.
 *
 * ⚠️ Uprawnienia są tu wypisane LITERAŁAMI i muszą zgadzać się z formatem, który
 * generuje `shield:generate` (`docs/conventions/autoryzacja.md` §2). Rozjazd nie
 * wywala aplikacji — po cichu odbiera dostęp do wszystkiego.
 */
final class OwnerRoleProvisioner
{
    public static function addOwnerRole(User $user)
    {
        $companyPermissions = [
            'view_any:company',
            'view:company',
            'create:company',
            'update:company',
            'delete:company',
            'delete_any:company',
            'force_delete:company',
            'force_delete_any:company',
            'restore:company',
            'restore_any:company',
            'replicate:company',
            'reorder:company',
        ];

        foreach ($companyPermissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $fisheryPermissions = [
            'view_any:fishery',
            'view:fishery',
            'create:fishery',
            'update:fishery',
            'delete:fishery',
            'delete_any:fishery',
            'force_delete:fishery',
            'force_delete_any:fishery',
            'restore:fishery',
            'restore_any:fishery',
            'replicate:fishery',
            'reorder:fishery',
        ];

        foreach ($fisheryPermissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $role = Role::firstOrCreate(['name' => 'owner']);
        $role->syncPermissions(array_merge($companyPermissions, $fisheryPermissions));
        $user->assignRole($role);
    }
}
