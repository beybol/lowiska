<?php

namespace Tests\Unit;

use Tests\Support\TestDatabaseGuard;

/**
 * Bramka izolacji i czyszczenie schematów równoległych stoją na jednej funkcji (zadanie 026).
 *
 * Bez bazy i bez `Tests\TestCase` — jak `PhpunitConfigInvariantTest`, ten test ma działać także
 * przy zepsutej konfiguracji połączenia.
 */
test('the gate allows the test schema and parallel test schemas', function (string $database) {
    expect(TestDatabaseGuard::isAllowed($database))->toBeTrue();
})->with([
    'lowiska_test',
    'lowiska_test_test_1',
    'lowiska_test_test_6',
    'lowiska_test_test_12',
]);

test('the gate rejects every other schema', function (?string $database) {
    expect(TestDatabaseGuard::isAllowed($database))->toBeFalse();
})->with([
    'working database' => 'lowiska',
    'no suffix number' => 'lowiska_test_test_',
    'foreign suffix' => 'lowiska_test_x',
    'letters after number' => 'lowiska_test_test_1a',
    'injected tail' => 'lowiska_test_test_1; drop database lowiska',
    'prefixed' => 'xlowiska_test',
    'prefixed parallel' => 'xlowiska_test_test_1',
    'upper case' => 'LOWISKA_TEST',
    'empty' => '',
    'null' => null,
]);

test('only parallel test schemas are picked for dropping', function () {
    expect(TestDatabaseGuard::parallelSchemasAmong([
        'lowiska',
        'lowiska_test',
        'lowiska_test_x',
        'lowiska_test_test_',
        'lowiska_test_test_3',
        'lowiska_test_test_1a',
        'lowiska_test_test_10',
    ]))->toBe(['lowiska_test_test_3', 'lowiska_test_test_10']);
});
