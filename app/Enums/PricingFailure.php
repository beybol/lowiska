<?php

namespace App\Enums;

/**
 * Dlaczego wycena nie umie podać ceny doby.
 *
 * ⚠️ To NIE jest słownik odmów sprzedaży. Wycena zgłasza wyłącznie, że nie umie wycenić,
 * a na `SaleUnavailabilityReason` tłumaczy to dopiero **warstwa oferty** (ADR-015) — dzięki
 * temu wycena zostaje wolna od pojęcia sprzedawalności.
 *
 * ⚠️ **Żadna z tych sytuacji nie leci w górę wyjątkiem** (ADR-014). Remis rzucony wyjątkiem
 * przerwałby cały widok kalendarza (019) przez jedną złą parę reguł — a kalendarz jest
 * właśnie tym miejscem, w którym operator ma taki błąd zobaczyć.
 */
enum PricingFailure: string
{
    /** Do tej doby, roli i obsady nie pasuje żadna reguła `rate` — dziura w cenniku. */
    case NoMatchingRate = 'no_matching_rate';

    /**
     * Dwie reguły `rate` o tym samym priorytecie i tej samej szczegółowości pasują
     * jednocześnie. Formularz tego nie zapisze; taki stan może powstać wyłącznie ścieżką
     * omijającą walidację (import, seed, przyszłe API).
     */
    case UnresolvableTie = 'unresolvable_tie';
}
