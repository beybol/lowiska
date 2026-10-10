<?php

namespace App\Mail;

use App\Models\Reservation;

/**
 * Rezerwacja założona przez łowisko — prośba o potwierdzenie (makieta G.1, ADR-024 pkt 8): dane
 * rezerwacji, kwota, zasady zwrotu, dwa przyciski „Potwierdź i połącz z kontem" oraz „Zrezygnuj /
 * to nie ja", lista danych, które łowisko zobaczy po połączeniu.
 */
class ReservationRequestMail extends PortalMailable
{
    public function __construct(
        public readonly Reservation $reservation,
        public readonly string $confirmUrl,
        public readonly string $manageUrl,
        ?string $mailLocale = null,
    ) {
        parent::__construct($reservation->fishery, $mailLocale);
    }

    public static function key(): string
    {
        return 'reservation-request';
    }

    protected function subjectLine(): string
    {
        return __('Please confirm your reservation');
    }

    protected function htmlView(): string
    {
        return 'emails.reservation-request';
    }

    protected function textView(): string
    {
        return 'emails.reservation-request-text';
    }

    protected function reason(): string
    {
        return __('You are receiving this message because a reservation was made for you at this fishery.');
    }

    protected function viewData(): array
    {
        $tiers = $this->reservation->terms->refund_policy ?? [];

        return [
            'details' => ReservationMailDetails::rows($this->reservation),
            'refundLines' => array_map(
                fn (array $tier): string => __(':percent% when cancelled at least :days days before', $tier),
                $tiers,
            ),
        ];
    }
}
