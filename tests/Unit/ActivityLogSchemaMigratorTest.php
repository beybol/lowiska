<?php

namespace Tests\Unit;

use App\Services\ActivityLogSchemaMigrator;

/**
 * Zadanie 010: przekształcenie danych dziennika zmian z formatu 4.x
 * (`properties.attributes`/`properties.old`) na format 5.x (`attribute_changes`).
 *
 * Testowana bezpośrednio jako klasa, nie przez uruchomienie migracji —
 * `RefreshDatabase` uruchamia migracje w `setUp()`, więc nie da się zasiać
 * wierszy „w formacie v4" tak, żeby zobaczyła je migracja (patrz zadanie 010,
 * „Rozstrzygnięcia").
 */
test('splits attributes and old into attribute_changes, leaving custom properties behind', function () {
    $properties = [
        'attributes' => ['name' => 'Nowa nazwa'],
        'old' => ['name' => 'Stara nazwa'],
        'custom_key' => 'wartość dołożona przez withProperties()',
    ];

    $result = ActivityLogSchemaMigrator::splitProperties($properties);

    expect($result['attribute_changes'])->toBe([
        'attributes' => ['name' => 'Nowa nazwa'],
        'old' => ['name' => 'Stara nazwa'],
    ]);
    expect($result['properties'])->toBe([
        'custom_key' => 'wartość dołożona przez withProperties()',
    ]);
});

test('row without attributes/old keys passes through unmodified, without producing attribute_changes', function () {
    $properties = ['custom_key' => 'wartość'];

    $result = ActivityLogSchemaMigrator::splitProperties($properties);

    expect($result['attribute_changes'])->toBeNull();
    expect($result['properties'])->toBe(['custom_key' => 'wartość']);
});

test('empty properties produce both keys as null, not empty arrays', function () {
    $result = ActivityLogSchemaMigrator::splitProperties([]);

    expect($result['attribute_changes'])->toBeNull();
    expect($result['properties'])->toBeNull();
});

test('only attributes/old present leaves properties null, not an empty array', function () {
    $properties = [
        'attributes' => ['is_verified' => true],
        'old' => ['is_verified' => false],
    ];

    $result = ActivityLogSchemaMigrator::splitProperties($properties);

    expect($result['attribute_changes'])->toBe($properties);
    expect($result['properties'])->toBeNull();
});
