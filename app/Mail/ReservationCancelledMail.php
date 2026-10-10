<?php

namespace App\Mail;

use App\Models\Reservation;

/**
 * Anulacja rezerwacji przez łowisko (makieta G.2, ADR-027 pkt 4): powód, komunikat operatora,
 * informacja o pełnym zwrocie (etap 3: „nie pobieramy opłaty"), kontakt do łowiska w stopce.
 */
class ReservationCancelledMail extends PortalMailable
{
    public function __construct(public readonly Reservation $reservation, ?string $mailLocale = null)
    {
        parent::__construct($reservation->fishery, $mailLocale);
    }

    public static function key(): string
    {
        return 'reservation-cancelled';
    }

    protected function subjectLine(): string
    {
        return __('Your reservation has been cancelled');
    }

    protected function htmlView(): string
    {
        return 'emails.reservation-cancelled';
    }

    protected function textView(): string
    {
        return 'emails.reservation-cancelled-text';
    }

    protected function reason(): string
    {
        return __('You are receiving this message because a reservation was made for you at this fishery.');
    }

    protected function viewData(): array
    {
        return [
            'details' => ReservationMailDetails::rows($this->reservation),
            'cancelReason' => $this->reservation->cancel_reason?->label(),
            'fisheryMessage' => $this->reservation->cancel_message,
        ];
    }
}
