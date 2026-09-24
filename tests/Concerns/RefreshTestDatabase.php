<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TestDatabaseGuard;

/**
 * `RefreshDatabase` z trybem dla procesów mutantów (zadanie 026).
 *
 * Pest uruchamia każdego mutanta w NOWYM procesie, więc zwykłe `RefreshDatabase` robiło przed
 * pierwszym testem pełne `migrate:fresh` — ok. 8–12 s na mutanta, 60–85% jego czasu. W procesie
 * mutanta schemat jest już aktualny (zostawiła go faza pokrycia albo poprzedni mutant na tym
 * samym schemacie równoległym), więc wystarcza `migrate`: przy aktualnym schemacie to jedno
 * zapytanie do tabeli migracji. Izolacja testów bez zmian — każdy test w transakcji wycofywanej
 * po teście, jak w `RefreshDatabase`.
 *
 * ⚠️ To musi być trait dołączany w `tests/Pest.php`, nie nadpisanie w `TestCase`: Pest dokłada
 * trait do klasy testu, a metoda traitu przebija metodę dziedziczoną z `TestCase`.
 * `class_uses_recursive()` nadal widzi `RefreshDatabase`, więc mechanizm równoległy Laravela
 * (`TestDatabases::bootTestDatabase()`) działa bez zmian.
 */
trait RefreshTestDatabase
{
    use RefreshDatabase;

    /**
     * Zmienna, którą Pest ustawia WYŁĄCZNIE procesom mutantów
     * (`Pest\Mutate\Plugins\Mutate::ENV_MUTATION_TESTING`).
     */
    private const MUTANT_PROCESS_VARIABLE = 'PEST_MUTATION_TESTING';

    protected function migrateDatabases()
    {
        if (getenv(self::MUTANT_PROCESS_VARIABLE) !== false) {
            $this->artisan('migrate');

            return;
        }

        // ⚠️ Tylko proces zwykły I niezrównoleglony — proces równoległy usuwałby schematy
        // procesom-sąsiadom w trakcie ich przebiegu. Świeżość schematów równoległych i tak jest
        // zapewniona: przy `--mutate --parallel` faza pokrycia biegnie w procesach równoległych,
        // a każdy z nich robi poniższe `migrate:fresh` na WŁASNYM schemacie `lowiska_test_test_{N}`
        // — tych samych numerów, których potem używają mutanty. Czyszczenie tutaj usuwa więc
        // tylko pozostałości (np. schematy o numerach wyższych niż bieżąca liczba procesów).
        if (! $this->runningInParallel()) {
            $this->dropParallelSchemas();
        }

        $this->artisan('migrate:fresh', $this->migrateFreshUsing());
    }

    /**
     * Usuwa schematy równoległe pozostałe po poprzednich przebiegach.
     *
     * Bez tego migracja poprawiona w miejscu (bez nowej nazwy) zostawiałaby w schemacie
     * równoległym stary kształt tabel — `migrate` sprawdza tylko nazwy migracji — a wynik mutacji
     * kłamałby po cichu.
     *
     * ⚠️ `LIKE` jest wyłącznie wstępnym filtrem. O usunięciu decyduje zakotwiczony wzorzec
     * z `TestDatabaseGuard`, sprawdzany jeszcze raz przed KAŻDYM `DROP`.
     */
    private function dropParallelSchemas(): void
    {
        $prefix = str_replace('_', '\\_', TestDatabaseGuard::TEST_DATABASE.'_test_').'%';

        $candidates = array_map(
            static fn (object $row): string => (string) $row->name,
            DB::select('select schema_name as name from information_schema.schemata where schema_name like ?', [$prefix]),
        );

        foreach (TestDatabaseGuard::parallelSchemasAmong($candidates) as $schema) {
            if (! TestDatabaseGuard::isParallelSchema($schema)) {
                continue;
            }

            Schema::dropDatabaseIfExists($schema);
        }
    }

    /**
     * Ten sam warunek, co `ParallelTesting::inParallel()` (metoda chroniona).
     */
    private function runningInParallel(): bool
    {
        return ! empty($_SERVER['LARAVEL_PARALLEL_TESTING']) && ! empty($_SERVER['TEST_TOKEN']);
    }
}
