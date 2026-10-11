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

    /** Usługa niedostępna (nieoferowana, brak cechy) bez powodu wyjątku — albo w kanale bez wyjątków (047). */
    case ServiceExceptionReasonRequired = 'service_exception_reason_required';

    /** Adres e-mail ma już inna relacja wędkarza w tym łowisku (`unique(fishery_id, given_email_normalized)`). */
    case EmailTaken = 'email_taken';

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
            self::ServiceExceptionReasonRequired => __('Give a reason to add a service that is not available'),
            self::EmailTaken => __('Another angler of this fishery already uses this email address'),
            self::NotActive => __('The reservation is no longer active'),
        };
    }
}
