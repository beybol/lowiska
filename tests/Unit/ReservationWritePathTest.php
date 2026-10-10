<?php

namespace Tests\Unit;

use Symfony\Component\Finder\Finder;

/**
 * Jedna ścieżka zapisu rezerwacji (ADR-025 pkt 4, G10): sloty, egzemplarze usług i warunki pisze
 * wyłącznie `ReservationBooking`. Zapis gdzie indziej ominąłby blokadę wiersza stanowiska i ponowne
 * sprawdzenie dostępności — a działałby, więc żaden test funkcjonalny by tego nie zauważył.
 */
test('only ReservationBooking writes slots, service items and terms', function () {
    $patterns = [
        '/(ReservationSlot|ReservationServiceItem|ReservationTerms)::(query\(\)\s*->\s*)?(create|insert|update|delete|upsert|forceCreate|updateOrCreate|firstOrCreate)\b/',
        '/->\s*(slots|serviceItems|terms|reservationSlots)\(\)\s*->\s*(create|createMany|insert|update|delete|updateOrCreate|firstOrCreate|save)\b/',
        "/DB::table\\(\\s*'reservation_(slots|service_items|terms)'\\s*\\)\\s*->\\s*(insert|update|delete|upsert)/",
    ];

    $offenders = [];

    foreach ((new Finder)->files()->in(dirname(__DIR__, 2).'/app')->name('*.php') as $file) {
        if ($file->getFilename() === 'ReservationBooking.php') {
            continue;
        }

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $file->getContents()) === 1) {
                $offenders[] = $file->getRelativePathname();
            }
        }
    }

    expect(array_unique($offenders))->toBe([]);
});
