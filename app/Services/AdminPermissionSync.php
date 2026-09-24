<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Synchronizacja roli `super_admin` z flagą `users.is_admin` — JEDYNE miejsce tej reguły (ADR-018).
 *
 * ⚠️ **`is_admin` jest jedynym źródłem prawdy o super adminie.** Rola Shielda jest jej POCHODNĄ:
 * kierunek zależności flaga → rola, nigdy odwrotnie. Stąd cztery kroki na każde uruchomienie:
 * wygenerowanie uprawnień Shielda dla obu paneli, komplet uprawnień dla roli, rola dla każdego
 * konta z flagą i zdjęcie roli z kont bez flagi.
 *
 * ⚠️ **Ręczna zmiana uprawnień roli `super_admin` w panelu Shielda jest cofana** przy następnym
 * uruchomieniu — czyli przy każdym wdrożeniu (`admins:sync` w `deploy.yml`). Nie ogranicza się
 * administratora przez Shielda; węższy administrator to osobna rola (`autoryzacja.md` §1).
 *
 * ⚠️ Roli `owner` ta klasa NIE dotyka — jej uprawnienia ustawia `OwnerRoleProvisioner`, a nowe
 * uprawnienia istniejącej roli dokładają migracje (`autoryzacja.md` §7).
 *
 * Operacja jest idempotentna: drugie uruchomienie bez zmian w danych niczego nie zmienia.
 */
final class AdminPermissionSync
{
    public function sync(): AdminPermissionSyncReport
    {
        // `shield:generate` nie uruchamia się nigdzie samo — bez tego rola dostałaby tylko to,
        // co już jest w bazie. `--option=permissions` trzyma generator z dala od `app/Policies/`,
        // które projekt utrzymuje ręcznie (`policies.generate = false`, `autoryzacja.md` §3).
        foreach (['admin', 'owner'] as $panel) {
            Artisan::call('shield:generate', [
                '--panel' => $panel,
                '--option' => 'permissions',
                '--all' => true,
                '--silent' => true,
            ]);
        }

        $role = Role::firstOrCreate(['name' => config('filament-shield.super_admin.name')]);

        $allPermissions = Permission::all();
        $before = $role->permissions()->pluck('id')->sort()->values()->all();
        $after = $allPermissions->pluck('id')->sort()->values()->all();

        if ($before !== $after) {
            $role->syncPermissions($allPermissions);
        }

        $granted = [];

        foreach (User::query()->where('is_admin', true)->get() as $admin) {
            if (! $admin->hasRole($role)) {
                $admin->assignRole($role);
                $granted[] = $admin->email;
            }
        }

        $revoked = [];

        foreach (User::role($role)->where('is_admin', false)->get() as $former) {
            $former->removeRole($role);
            $revoked[] = $former->email;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return new AdminPermissionSyncReport(
            roleName: $role->name,
            permissionsBefore: count($before),
            permissionsAfter: count($after),
            granted: $granted,
            revoked: $revoked,
            adminCount: User::query()->where('is_admin', true)->count(),
        );
    }
}
