<?php

namespace Tests\Unit;

/**
 * Czwarta z pięciu warstw izolacji pakietu testów (ADR-001).
 *
 * Czerwienieje w chwili, gdy ktoś zdejmie force="true" z dowolnej zmiennej DB_*
 * albo zmieni schemat testowy. Dziedziczy po klasie bazowej PHPUnit (a nie po
 * Tests\TestCase), żeby nie uruchamiać bramki z warstwy trzeciej — ten test ma
 * działać także wtedy, gdy konfiguracja jest zepsuta.
 */
function phpunitEnvNodes(): array
{
    $path = dirname(__DIR__, 2).'/phpunit.xml';

    $xml = simplexml_load_file($path);

    if ($xml === false) {
        throw new \RuntimeException("Nie udało się sparsować {$path}.");
    }

    $nodes = [];

    foreach ($xml->php->env as $env) {
        $nodes[(string) $env['name']] = [
            'value' => (string) $env['value'],
            'force' => (string) $env['force'] === 'true',
        ];
    }

    return $nodes;
}

test('every DB_* variable in phpunit.xml is forced', function () {
    $nodes = phpunitEnvNodes();

    $required = [
        'DB_CONNECTION',
        'DB_HOST',
        'DB_PORT',
        'DB_DATABASE',
        'DB_USERNAME',
        'DB_PASSWORD',
        'DB_URL',
    ];

    foreach ($required as $name) {
        $this->assertArrayHasKey(
            $name,
            $nodes,
            "phpunit.xml musi deklarować {$name} — bez tego zmienna ze środowiska ".
            'decyduje o tym, w którą bazę trafi RefreshDatabase.'
        );

        $this->assertTrue(
            $nodes[$name]['force'],
            "phpunit.xml musi mieć force=\"true\" na {$name}. Bez tego atrybutu ".
            'deklaracja przegrywa ze zmienną ze środowiska i pakiet testów potrafi '.
            'trafić w bazę roboczą.'
        );
    }
});

test('phpunit.xml points at the test schema', function () {
    $nodes = phpunitEnvNodes();

    $this->assertSame(
        'lowiska_test',
        $nodes['DB_DATABASE']['value'],
        'Schemat testowy jest ustalony w ADR-001 i powielony w bramce '.
        'Tests\TestCase — zmiana w jednym miejscu rozjeżdża oba.'
    );

    $this->assertSame(
        '',
        $nodes['DB_URL']['value'],
        'DB_URL musi być wymuszony pusty — DATABASE_URL ze środowiska '.
        'przesłoniłby host i schemat ustawione powyżej.'
    );

    $this->assertSame('testing', $nodes['APP_ENV']['value']);
    $this->assertTrue($nodes['APP_ENV']['force']);
});
