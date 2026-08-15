<?php

namespace App\Services;

class ActivityLogSchemaMigrator
{
    /**
     * Klucze, które w formacie 4.x siedziały wewnątrz `properties` i w 5.x
     * mają własną kolumnę `attribute_changes` (zadanie 010).
     */
    private const CHANGE_KEYS = ['attributes', 'old'];

    /**
     * Rozdziela zdekodowaną tablicę `properties` z wiersza dziennika w formacie
     * 4.x na nowy podział 5.x: `attribute_changes` (zmiany atrybutów modelu)
     * i pozostałe `properties` (własne dane dołożone przez `withProperties()`).
     *
     * Wydzielone z migracji celowo — `RefreshDatabase` uruchamia migracje
     * w `setUp()`, więc logiki zaszytej w ciele migracji nie dałoby się
     * przetestować na zasianych wierszach (patrz zadanie 010, „Rozstrzygnięcia").
     *
     * @param  array<string, mixed>  $properties
     * @return array{attribute_changes: array<string, mixed>|null, properties: array<string, mixed>|null}
     */
    public static function splitProperties(array $properties): array
    {
        $changes = array_intersect_key($properties, array_flip(self::CHANGE_KEYS));
        $remaining = array_diff_key($properties, array_flip(self::CHANGE_KEYS));

        return [
            'attribute_changes' => $changes === [] ? null : $changes,
            'properties' => $remaining === [] ? null : $remaining,
        ];
    }
}
