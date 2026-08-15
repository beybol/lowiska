<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;

/**
 * Regresja dla zadania 009 (upgrade Filamenta 3 → 5).
 *
 * Po migracji w repozytorium został widok wołający komponent Blade, którego
 * Filament 5 już nie ma (`<x-filament-panels::form.actions>`). Skutek: obraz
 * produkcyjny **nie wstawał** — `prod-entrypoint` woła `php artisan view:cache`,
 * a ten kompiluje WSZYSTKIE widoki naraz i wywracał się na tym jednym.
 * Cloud Run raportował to jako „container failed to start and listen on PORT".
 *
 * ⚠️ Lokalnie i w testach było zielono, bo Blade kompiluje widoki LENIWIE —
 * dopiero przy renderowaniu. Widok, do którego żaden test nie zagląda (albo
 * osierocony, jak tamten), nigdy nie przechodził przez kompilator. Ten test
 * zamyka tę lukę: robi dokładnie to, co robi wdrożenie.
 */
test('all Blade views compile, exactly as the production entrypoint requires', function () {
    $exitCode = Artisan::call('view:cache');

    expect($exitCode)->toBe(0, 'php artisan view:cache zwrócił błąd — obraz produkcyjny nie wstanie: '.Artisan::output());
})->after(function () {
    // Nie zostawiamy skompilowanych widoków po teście — `view:cache` zapisuje je
    // do storage/framework/views, a kolejne testy mają startować bez tego stanu.
    Artisan::call('view:clear');
});
