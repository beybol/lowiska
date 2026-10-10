<?php

namespace App\Mail;

use App\Models\Fishery;
use App\Models\FisheryAngler;
use App\Models\Position;
use App\Models\Reservation;
use App\Models\ReservationTerms;
use Closure;

/**
 * Rejestr podglądu wiadomości z danymi przykładowymi (zadanie 040) — trasa `/dev/mail-preview`.
 *
 * ⚠️ **Nowa wiadomość dopisuje tu swój wpis** — inaczej nie da się obejrzeć jej wyglądu bez wysyłki,
 * a test podglądu przestaje ją obejmować. Wpis buduje wiadomość bez zapisu w bazie (modele `make()`
 * albo obiekty w pamięci), bo podgląd nie może zostawiać śladów w danych.
 *
 * Podgląd działa wyłącznie w środowiskach `local` i `testing` — trasa nie jest rejestrowana nigdzie
 * indziej, a kontroler sprawdza środowisko jeszcze raz.
 */
final class MailPreviews
{
    /** @var array<int, string> */
    public const ENVIRONMENTS = ['local', 'testing'];

    public static function allowed(): bool
    {
        return app()->environment(self::ENVIRONMENTS);
    }

    /**
     * @return array<string, Closure(): PortalMailable>
     */
    public static function all(): array
    {
        return [
            VerifyEmailMail::key() => fn (): PortalMailable => new VerifyEmailMail(url('/verify-email/1/preview')),
            TwoFactorCodeMail::key() => fn (): PortalMailable => new TwoFactorCodeMail('123456', url('/verify')),
            ReservationRequestMail::key() => fn (): PortalMailable => new ReservationRequestMail(
                self::sampleReservation(),
                url('/pl/rezerwacja/potwierdzenie/preview-token-preview-token'),
                url('/pl/rezerwacja/rezygnacja/preview-token-preview-token'),
            ),
            ReservationExpiredMail::key() => fn (): PortalMailable => new ReservationExpiredMail(self::sampleReservation(['status' => 'expired'])),
            ReservationCancelledMail::key() => fn (): PortalMailable => new ReservationCancelledMail(self::sampleReservation(
                ['status' => 'cancelled', 'cancel_reason' => 'fishery_decision', 'cancel_message' => "Awaria pomostu na stanowisku.\nPrzepraszamy za kłopot."],
            )),
        ];
    }

    /**
     * Rezerwacja przykładowa w pamięci — bez zapisu w bazie.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function sampleReservation(array $attributes = []): Reservation
    {
        $fishery = new Fishery(['name' => 'Łowisko Pod Lasem', 'phone' => '+48 500 100 200', 'email' => 'kontakt@example.com', 'timezone' => Fishery::DEFAULT_TIMEZONE]);
        $position = new Position(['name' => 'Stanowisko 7']);
        $angler = new FisheryAngler(['given_name' => 'Jan Kowalski', 'given_email' => 'jan@example.com', 'given_phone' => '+48 600 000 000']);

        $reservation = new Reservation(array_merge([
            'status' => 'confirmed',
            'payment_status' => 'unpaid',
            'starts_at' => '2026-07-10 13:00:00',
            'ends_at' => '2026-07-12 13:00:00',
            'first_night_on' => '2026-07-10',
            'nights' => 2,
            'source' => 'operator',
        ], $attributes));

        $terms = new ReservationTerms([
            'anglers' => 2,
            'companions' => 1,
            'total_cents' => 28000,
            'currency' => 'PLN',
            'position_snapshot' => ['name' => 'Stanowisko 7'],
        ]);

        $reservation->setRelation('fishery', $fishery);
        $reservation->setRelation('position', $position);
        $reservation->setRelation('angler', $angler);
        $reservation->setRelation('terms', $terms);

        return $reservation;
    }

    public static function make(string $key): ?PortalMailable
    {
        $factory = self::all()[$key] ?? null;

        return $factory === null ? null : $factory();
    }
}
