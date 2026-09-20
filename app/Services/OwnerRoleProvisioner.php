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
 *
 * ⚠️ **Przypisanie roli i zdefiniowanie jej uprawnień to DWIE różne operacje** i nie
 * wolno ich z powrotem zlepić. Wcześniej `addOwnerRole()` kończyło się
 * `syncPermissions()`, więc każde jego wywołanie NADPISYWAŁO zestaw uprawnień
 * globalnej roli `owner` literałami z kodu. Ponieważ woła je ścieżka rejestracji
 * i logowania, dowolny użytkownik jednym żądaniem cofał zmiany, które administrator
 * zrobił w panelu Shielda — a odebrana komuś rola wracała przy następnym logowaniu
 * (security-review, 2026-09-20). Dziś `syncPermissions()` wykonuje się WYŁĄCZNIE
 * wtedy, gdy roli jeszcze nie ma, czyli raz na instalację.
 */
final class OwnerRoleProvisioner
{
    private const ROLE = 'owner';

    /**
     * Przypisuje rolę `owner` użytkownikowi. Istniejącej roli NIE dotyka.
     */
    public static function addOwnerRole(User $user): void
    {
        $role = Role::where('name', self::ROLE)->first();

        if (! $role instanceof Role) {
            // Bootstrap pierwszego użycia: rola jeszcze nie istnieje, więc nie ma
            // czyich ustawień nadpisać.
            $role = self::provisionRole();
        }

        $user->assignRole($role);
    }

    /**
     * Tworzy rolę `owner` i ustawia jej uprawnienia na wartości domyślne.
     *
     * ⚠️ Operacja administracyjna — wołaj z seedera, komendy albo z bootstrapu
     * w `addOwnerRole()`. Wywołana w obsłudze żądania zwykłego użytkownika przywraca
     * uprawnienia do stanu z kodu i kasuje zmiany administratora.
     */
    public static function provisionRole(): Role
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

        $role = Role::firstOrCreate(['name' => self::ROLE]);
        $role->syncPermissions(array_merge($companyPermissions, $fisheryPermissions));

        return $role;
    }
}
