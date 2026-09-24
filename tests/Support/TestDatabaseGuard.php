<?php

namespace Tests\Support;

/**
 * Jedyne miejsce, które mówi, na jakim schemacie wolno uruchamiać testy (ADR-001, zadanie 026).
 *
 * Wolno na dokładnie dwóch rodzajach nazw:
 * - `lowiska_test` — przebieg zwykły;
 * - `lowiska_test_test_{liczba}` — schemat procesu równoległego, który Laravel tworzy sam
 *   (`TestDatabases::testDatabase()`), gdy Pest uruchamia mutacje z `--parallel`.
 *
 * ⚠️ Z tej samej funkcji korzystają obie kontrole bramki w `TestCase` ORAZ czyszczenie schematów
 * równoległych w `RefreshTestDatabase`. Poluzowanie wzorca poluzowuje więc naraz bramkę
 * i listę schematów, które wolno usunąć — nie dopisuj tu wyjątków.
 */
final class TestDatabaseGuard
{
    public const TEST_DATABASE = 'lowiska_test';

    public static function isAllowed(?string $database): bool
    {
        return $database === self::TEST_DATABASE || self::isParallelSchema($database);
    }

    /**
     * Czy nazwa to schemat procesu równoległego — i nic więcej.
     *
     * Wzorzec jest zakotwiczony z obu stron: `lowiska_test_test_1a`, `lowiska_test_test_`
     * ani `lowiska_test_x` nim nie są.
     */
    public static function isParallelSchema(?string $database): bool
    {
        if ($database === null) {
            return false;
        }

        return preg_match('/^'.preg_quote(self::TEST_DATABASE, '/').'_test_[0-9]+$/', $database) === 1;
    }

    /**
     * Wybiera z listy nazw wyłącznie schematy równoległe — kandydatów do usunięcia.
     *
     * @param  array<int, string>  $databases
     * @return array<int, string>
     */
    public static function parallelSchemasAmong(array $databases): array
    {
        return array_values(array_filter(
            $databases,
            static fn (string $database): bool => self::isParallelSchema($database),
        ));
    }
}
