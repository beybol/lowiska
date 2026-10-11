<?php

namespace App\Enums;

/**
 * Kto potwierdził rezerwację (zadanie 039): operator w panelu albo wędkarz linkiem z maila
 * (zadanie 043, wniosek W3 — „Potwierdź i połącz" potwierdza też rezerwację) albo płatność — opłacona
 * rezerwacja jest zawsze potwierdzona, niezależnie od tego, kto oznaczył płatność.
 */
enum ConfirmationSource: string
{
    case Operator = 'operator';

    case Link = 'link';

    case Payment = 'payment';

    public function label(): string
    {
        return match ($this) {
            self::Operator => __('Operator'),
            self::Link => __('Link in the email'),
            self::Payment => __('Payment'),
        };
    }
}
