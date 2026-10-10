<?php

namespace App\Enums;

/**
 * Rodzaj slotu zajętości (ADR-025 pkt 1). Dziś jedyna wartość to doba wędkarska.
 *
 * ⚠️ Punkt rozszerzenia, nie zapowiedź: nowa jednostka sprzedaży (dzień, półdzień) dokłada wartość
 * tutaj BEZ zmiany schematu, ale wymaga osobnej decyzji o „schemacie slotów łowiska", bo
 * sprzedawalność i cennik nadal mówią „doba" (ADR-025 pkt 9). Reguła kolizji nie zależy od typu.
 */
enum SlotType: string
{
    case FishingDay = 'fishing_day';
}
