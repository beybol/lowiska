<?php

namespace App\Services;

use App\Mail\PortalMailable;
use App\Mail\ReservationCancelledMail;
use App\Models\Reservation;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Wysyłka wiadomości o rezerwacji do wędkarza (zadania 041, 043, 044, 045).
 *
 * ⚠️ **Błąd wysyłki nie cofa operacji na rezerwacji** — wiadomość idzie PO zatwierdzeniu transakcji
 * (`sync`, bez workera — ADR-004). Rezerwacja bez adresu albo z nieudaną wysyłką trafia na listę
 * „Do kontaktu" (ADR-027 pkt 4, makiety Q1), żeby operator powiadomił wędkarza inną drogą.
 *
 * Język: dane wpisuje operator, a języka wędkarza nie znamy — wiadomość idzie w języku domyślnym
 * portalu.
 */
final class ReservationMailer
{
    public function __construct(private readonly ReservationBooking $booking = new ReservationBooking) {}

    /** @return bool czy wiadomość wyszła */
    public function cancellation(Reservation $reservation): bool
    {
        return $this->deliver($reservation, new ReservationCancelledMail($reservation, PortalRoutes::DEFAULT_LOCALE));
    }

    /**
     * @return bool czy wiadomość wyszła; `false` = rezerwacja oznaczona „do kontaktu"
     */
    public function deliver(Reservation $reservation, PortalMailable $mail, bool $markOnFailure = true): bool
    {
        $email = $reservation->angler?->effectiveEmail();

        if (blank($email)) {
            if ($markOnFailure) {
                $this->booking->markNeedsContact($reservation);
            }

            return false;
        }

        try {
            Mail::to($email)->send($mail);
        } catch (Throwable $exception) {
            report($exception);

            if ($markOnFailure) {
                $this->booking->markNeedsContact($reservation);
            }

            return false;
        }

        return true;
    }
}
