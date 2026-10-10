<?php

namespace App\Console\Commands;

use App\Enums\ReservationStatus;
use App\Mail\ReservationExpiredMail;
use App\Models\Reservation;
use App\Services\PortalRoutes;
use App\Services\ReservationBooking;
use App\Services\ReservationMailer;
use Illuminate\Console\Command;

/**
 * `reservations:expire` — wygasza niepotwierdzone rezerwacje po `confirm_by` i zwalnia ich sloty
 * (ADR-024 pkt 7, zadanie 045). Uruchamiana harmonogramem co 5 minut (`routes/console.php`);
 * na Cloud Run wyzwala ją Cloud Scheduler (`docs/operations/obraz-produkcyjny.md` §13).
 *
 * ⚠️ **Idempotentna i bezpieczna przy równoległym uruchomieniu:** każde wygaszenie idzie przez
 * `ReservationBooking::expire()` — blokada wiersza rezerwacji i ponowne sprawdzenie stanu i terminu.
 * Drugie uruchomienie (albo drugi proces) nic nie zmienia. Potwierdzona rezerwacja nie wygasa.
 *
 * Po wygaszeniu mail do wędkarza z adresem (decyzja autora, zadanie 045). Błąd wysyłki nie cofa
 * wygaszenia; rezerwacja bez adresu nie trafia do „Do kontaktu" — wygaśnięcie nie wymaga kontaktu.
 */
class ExpireReservationsCommand extends Command
{
    protected $signature = 'reservations:expire';

    protected $description = 'Expire unconfirmed reservations past their confirmation deadline and free their positions';

    public function handle(ReservationBooking $booking, ReservationMailer $mailer): int
    {
        $expired = 0;

        Reservation::query()
            ->where('status', ReservationStatus::Pending->value)
            ->whereNotNull('confirm_by')
            ->where('confirm_by', '<=', now()->utc()->format('Y-m-d H:i:s'))
            ->orderBy('id')
            ->chunkById(100, function ($reservations) use ($booking, $mailer, &$expired): void {
                foreach ($reservations as $reservation) {
                    if (! $booking->expire($reservation)) {
                        continue;
                    }

                    $expired++;
                    $fresh = $reservation->fresh(['fishery', 'angler.user', 'terms', 'position']) ?? $reservation;
                    $mailer->deliver($fresh, new ReservationExpiredMail($fresh, PortalRoutes::DEFAULT_LOCALE), markOnFailure: false);
                }
            });

        $this->info("Expired reservations: {$expired}.");

        return self::SUCCESS;
    }
}
