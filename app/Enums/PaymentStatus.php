<?php

namespace App\Enums;

/**
 * Status płatności rezerwacji (zadanie 039).
 *
 * ⚠️ W etapie 3 nie ma płatności online — wędkarz płaci na łowisku. `Refund` jest więc STATUSEM,
 * nie zleceniem zwrotu: anulacja opłaconej rezerwacji przez łowisko go ustawia, bez rejestru
 * „do zwrotu" (ADR-027 pkt 5).
 */
enum PaymentStatus: string
{
    case Unpaid = 'unpaid';

    case Paid = 'paid';

    case OnSite = 'on_site';

    case Refund = 'refund';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => __('Unpaid'),
            self::Paid => __('Paid'),
            self::OnSite => __('Pay on site'),
            self::Refund => __('Refund'),
        };
    }
}
