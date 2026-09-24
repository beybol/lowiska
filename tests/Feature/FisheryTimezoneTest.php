<?php

namespace Tests\Feature;

use App\Models\Fishery;

/**
 * Jeden dom strefy łowiska — `Fishery::timezoneName()` (zadanie 024).
 *
 * ⚠️ Wszystkie wyliczenia sprzedaży biegną w strefie łowiska (`dostepnosc.md` §1), a fallback strefy
 * domyślnej ma JEDNO miejsce. Drugi literał w klasie usługi to defekt.
 */
test('the fishery time zone is the stored one when set', function () {
    $fishery = Fishery::factory()->create(['timezone' => 'America/New_York']);

    expect($fishery->fresh()->timezoneName())->toBe('America/New_York');
});

test('an empty time zone falls back to the default one', function () {
    $fishery = new Fishery(['timezone' => '']);

    expect($fishery->timezoneName())->toBe(Fishery::DEFAULT_TIMEZONE)
        ->and(Fishery::DEFAULT_TIMEZONE)->toBe('Europe/Warsaw');
});

test('an unsaved fishery without a time zone gets the default one', function () {
    expect((new Fishery)->timezoneName())->toBe(Fishery::DEFAULT_TIMEZONE)
        ->and((new Fishery)->timezone)->toBe(Fishery::DEFAULT_TIMEZONE);
});

test('no class in app outside the model carries its own copy of the default time zone', function () {
    $offenders = [];

    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));

    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php'
            && str_contains((string) file_get_contents($file->getPathname()), "'".Fishery::DEFAULT_TIMEZONE."'")
            && $file->getRealPath() !== realpath(app_path('Models/Fishery.php'))) {
            $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
        }
    }

    expect($offenders)->toBe([]);
});
