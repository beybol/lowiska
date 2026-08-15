<?php

namespace Tests\Unit;

use App\Providers\AppServiceProvider;

/**
 * Regresja dla zadania 005: poza local/testing aplikacja ma odmówić startu, jeśli
 * dysk uploadów rozwiązuje się do sterownika `local` — na Cloud Run kontener jest
 * efemeryczny, więc pliki znikają przy każdym restarcie/scale-to-zero.
 *
 * Test jednostkowy (nie dziedziczy po Tests\TestCase): wywołuje czystą funkcję
 * bezpośrednio z syntetycznymi wartościami, bez podnoszenia aplikacji.
 */
test('boots normally in local with a local disk', function () {
    AppServiceProvider::assertUploadDiskIsSafe('local', 'local');
})->throwsNoExceptions();

test('boots normally in testing with a local disk', function () {
    AppServiceProvider::assertUploadDiskIsSafe('testing', 'local');
})->throwsNoExceptions();

test('boots normally outside local/testing with a non-local disk', function () {
    AppServiceProvider::assertUploadDiskIsSafe('production', 'gcs');
    AppServiceProvider::assertUploadDiskIsSafe('staging', 'gcs');
})->throwsNoExceptions();

test('refuses to boot on production with a local disk', function () {
    AppServiceProvider::assertUploadDiskIsSafe('production', 'local');
})->throws(\RuntimeException::class);

test('refuses to boot on staging with a local disk', function () {
    AppServiceProvider::assertUploadDiskIsSafe('staging', 'local');
})->throws(\RuntimeException::class);
