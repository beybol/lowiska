<?php

namespace App\Enums;

/**
 * Dlaczego wycena nie umie podać ceny doby.
 *
 * ⚠️ To NIE jest słownik odmów sprzedaży. Wycena zgłasza wyłącznie, że nie umie wycenić,
 * a na `SaleUnavailabilityReason` tłumaczy to dopiero **warstwa oferty** (ADR-015) — dzięki
 * temu wycena zostaje wolna od pojęcia sprzedawalności.
 *
 * ⚠️ **Żadna z tych sytuacji nie leci w górę wyjątkiem** (ADR-014). Błąd rzucony wyjątkiem
 * przerwałby cały widok kalendarza (019) przez jedną złą regułę — a kalendarz jest właśnie
 * tym miejscem, w którym operator ma taki błąd zobaczyć.
 *
 * ⚠️ **Wariant `UnresolvableTie` został USUNIĘTY** wraz z priorytetami i szczegółowością
 * (ADR-014, sekcja „Aktualizacja"): nachodzenie stawek rozstrzyga się na korzyść wędkarza,
 * więc nierozstrzygalność jest niemożliwa z konstrukcji. Nie przywracaj go — jego powrót
 * znaczyłby, że wróciła konkurencja między stawkami.
 */
enum PricingFailure: string
{
    /** Do tej doby nie pasuje żadna reguła `rate` — dziura w cenniku. */
    case NoMatchingRate = 'no_matching_rate';

    /**
     * Stawka pasuje, ale nie ma kwoty za osobę towarzyszącą, a zapytanie jej wymaga.
     *
     * ⚠️ **Osobny wariant, nie `NoMatchingRate`** — te dwie sytuacje prowadzą operatora
     * w przeciwne strony: tam trzeba DOPISAĆ stawkę, tu POPRAWIĆ jedno pole w stawce,
     * która już istnieje. Zlanie ich posłałoby go szukać nieistniejącej dziury w cenniku.
     */
    case NoCompanionPrice = 'no_companion_price';
}
