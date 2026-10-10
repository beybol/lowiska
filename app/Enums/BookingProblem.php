<?php

namespace App\Enums;

/**
 * Powód odmowy zapisu rezerwacji, który NIE jest odmową sprzedaży doby albo pobytu.
 *
 * ⚠️ Odmowy sprzedaży (sezon, blokada, zajętość, brak ceny) niesie `SaleUnavailabilityReason` —
 * enum jest jeden dla całej sprzedaży (`dostepnosc.md` §2). Tutaj trafia wyłącznie to, co dotyczy
 * SKŁADU rezerwacji albo braków w żądaniu operatora: pojemność (ADR-025 pkt 8), usługi z limitem
 * (pkt 7) i brakujący powód wyjątku.
 */
enum BookingProblem: string
{
    case CapacityExceeded = 'capacity_exceeded';

    case ServiceUnavailable = 'service_unavailable';

    case ServiceLimitExceeded = 'service_limit_exceeded';

    /** Wyjątek od reguł sprzedaży bez powodu — albo w kanale, który wyjątków nie dopuszcza. */
    case ExceptionReasonRequired = 'exception_reason_required';

    /** Cena uzgodniona bez powodu — albo w kanale, który jej nie dopuszcza. */
    case AgreedPriceReasonRequired = 'agreed_price_reason_required';

    /** Rezerwacja nie jest aktywna, więc nie da się jej zmienić. */
    case NotActive = 'not_active';

    public function label(): string
    {
        return match ($this) {
            self::CapacityExceeded => __('The position does not take that many people'),
            self::ServiceUnavailable => __('This service is not available at the position on those nights'),
            self::ServiceLimitExceeded => __('Not enough units of this service are left on those nights'),
            self::ExceptionReasonRequired => __('Give a reason to book against the sale rules'),
            self::AgreedPriceReasonRequired => __('Give a reason for the agreed price'),
            self::NotActive => __('The reservation is no longer active'),
        };
    }
}
