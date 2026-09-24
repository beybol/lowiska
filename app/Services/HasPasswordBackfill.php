<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Ustawienie `users.has_password` istniejącym kontom — dane migracji
 * `2026_09_27_100000_add_has_password_to_users_table` (zadanie 028).
 *
 * ⚠️ Logika przepisania danych mieszka w `app/`, nie w ciele migracji (`dziennik-zmian.md` §2):
 * `RefreshDatabase` uruchamia migracje przed testem, więc kodu zaszytego w migracji nie da się
 * sprawdzić na zasianych danych.
 *
 * Reguła: do zadania 028 jedyną drogą do powiązania z dostawcą było ZAŁOŻENIE konta przez dostawcę
 * (dowiązania do konta hasłowego nie było), więc każde konto z dostawcą ma hasło losowe.
 */
final class HasPasswordBackfill
{
    /**
     * @return int liczba kont oznaczonych jako bez znanego hasła
     */
    public static function run(): int
    {
        return DB::table('users')->whereNotNull('provider')->update(['has_password' => false]);
    }
}
