<?php

namespace App\Enums;

/**
 * Dlaczego usługa jest na stanowisku niedostępna — odpowiedź `PositionServices` (zadanie 020).
 *
 * ⚠️ To NIE jest powód odmowy sprzedaży doby ani pobytu. Niedostępna usługa nie wyłącza stanowiska,
 * więc nie dokłada wartości do `SaleUnavailabilityReason` — tamten enum odpowiada na inne pytanie.
 */
enum ServiceUnavailabilityReason: string
{
    /** Stanowisko nie ma wartości wymaganej cechy — „nikt się nie wypowiedział" liczy się jako brak. */
    case AttributeNotSpecified = 'attribute_not_specified';

    /** Wymagana cecha jest na stanowisku ustawiona na „nie". */
    case AttributeAbsent = 'attribute_absent';

    /** Ograniczenie zawiesza wymaganą cechę w części pokazywanego okna. */
    case AttributeSuspended = 'attribute_suspended';
}
