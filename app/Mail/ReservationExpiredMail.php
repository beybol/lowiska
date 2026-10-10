<?php

namespace App\Mail;

use App\Models\Reservation;

/**
 * Wygaśnięcie niepotwierdzonej rezerwacji (makieta G.3, zadanie 045): informacja i dane do ponownej
 * rezerwacji — kontakt do łowiska w stopce.
 */
class ReservationExpiredMail extends PortalMailable
{
    public function __construct(public readonly Reservation $reservation, ?string $mailLocale = null)
    {
        parent::__construct($reservation->fishery, $mailLocale);
    }

    public static function key(): string
    {
        return 'reservation-expired';
    }

    protected function subjectLine(): string
    {
        return __('Your reservation has expired');
    }

    protected function htmlView(): string
    {
        return 'emails.reservation-expired';
    }

    protected function textView(): string
    {
        return 'emails.reservation-expired-text';
    }

    protected function reason(): string
    {
        return __('You are receiving this message because a reservation was made for you at this fishery.');
    }

    protected function viewData(): array
    {
        return ['details' => ReservationMailDetails::rows($this->reservation)];
    }
}
