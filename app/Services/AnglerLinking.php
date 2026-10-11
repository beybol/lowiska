<?php

namespace App\Services;

use App\Enums\AnglerLinkStatus;
use App\Enums\CancellationReason;
use App\Enums\ConfirmationSource;
use App\Enums\ReservationStatus;
use App\Mail\ReservationRequestMail;
use App\Models\FisheryAngler;
use App\Models\Reservation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Powiązanie wędkarza z kontem i potwierdzenie rezerwacji linkiem z maila (zadanie 043, ADR-024).
 *
 * ⚠️ **Konto powstaje albo się dowiązuje WYŁĄCZNIE po kliknięciu przez samego wędkarza** — kliknięcie
 * dowodzi, że ma skrzynkę. Operator nigdy nie pisze do `users` i zawsze widzi ten sam komunikat
 * („wysłano prośbę o potwierdzenie"), więc nie da się z panelu sprawdzić, czy adres jest w portalu.
 * Telefon nie łączy kont — jest wyłącznie danymi kontaktowymi relacji.
 *
 * Dwa tokeny, oba jednorazowe, w bazie tylko hash (SHA-256), ważność `link_expires_at`:
 * - `fishery_anglers.link_token_hash` — „Potwierdź i połącz z kontem": dowiązuje relację i potwierdza
 *   oczekujące rezerwacje tego wędkarza w tym łowisku (W3);
 * - `reservations.manage_token_hash` — „Zrezygnuj / to nie ja": rezygnacja z JEDNEJ rezerwacji albo
 *   „to nie ja" (unieważnienie prośby i oznaczenie rezerwacji do weryfikacji przez operatora).
 *
 * Limity wysyłki (ADR-024 pkt 8): oczekujące prośby na jeden adres w całym portalu i wysyłki na łowisko
 * w ciągu doby. Przekroczenie nie blokuje rezerwacji — mail nie wychodzi, rezerwacja trafia do „Do kontaktu".
 */
final class AnglerLinking
{
    /** Ważność linków z maila. */
    public const VALID_DAYS = 14;

    /** Oczekujące prośby o powiązanie na jeden adres, w całym portalu. */
    public const MAX_PENDING_PER_EMAIL = 3;

    /** Wysyłki próśb z jednego łowiska w ciągu doby. */
    public const MAX_SENDS_PER_FISHERY_PER_DAY = 30;

    public function __construct(
        private readonly ReservationBooking $booking = new ReservationBooking,
        private readonly ReservationMailer $mailer = new ReservationMailer,
    ) {}

    /**
     * Prośba o potwierdzenie po zapisie rezerwacji. Bez adresu e-mail — nic (relacja zostaje niepowiązana).
     *
     * @return bool czy wiadomość wyszła
     */
    public function requestConfirmation(Reservation $reservation): bool
    {
        $reservation->loadMissing(['angler.user', 'fishery', 'terms', 'position']);
        $angler = $reservation->angler;
        $email = FisheryAngler::normalizeEmail($angler?->effectiveEmail());

        if ($angler === null || $email === null || $angler->status === AnglerLinkStatus::Revoked) {
            return false;
        }

        if ($this->limited($angler, $email, (int) $reservation->fishery_id)) {
            $this->booking->markNeedsContact($reservation);
            $this->log($angler, 'link_request_limited');

            return false;
        }

        $linkToken = Str::random(48);
        $manageToken = Str::random(48);

        $angler->forceFill([
            'link_token_hash' => self::hash($linkToken),
            'link_expires_at' => CarbonImmutable::now()->addDays(self::VALID_DAYS),
            // Token ważny wyłącznie dla adresu z chwili wysyłki (047, D2).
            'link_email' => $email,
            'status' => $angler->status === AnglerLinkStatus::Linked ? AnglerLinkStatus::Linked : AnglerLinkStatus::Pending,
        ])->save();

        $reservation->forceFill(['manage_token_hash' => self::hash($manageToken)])->save();
        RateLimiter::hit(self::fisheryKey((int) $reservation->fishery_id), 86400);
        $this->log($angler, 'link_requested');

        return $this->mailer->deliver($reservation, new ReservationRequestMail(
            $reservation,
            PortalRoutes::reservationUrl('confirm', $linkToken, PortalRoutes::DEFAULT_LOCALE),
            PortalRoutes::reservationUrl('manage', $manageToken, PortalRoutes::DEFAULT_LOCALE),
            PortalRoutes::DEFAULT_LOCALE,
        ));
    }

    /**
     * Relacja po tokenie powiązania. `expired` = token istnieje, ale minął termin; `null` = brak
     * (nieznany albo już użyty).
     *
     * @return array{angler: FisheryAngler|null, expired: bool}
     */
    public function findByLinkToken(string $token): array
    {
        $angler = FisheryAngler::query()->with(['fishery', 'user'])->where('link_token_hash', self::hash($token))->first();

        // Adres zmieniony po wysyłce — link z maila na stary adres jest nieważny (047, D2).
        if ($angler === null || ! self::sentToCurrentEmail($angler)) {
            return ['angler' => null, 'expired' => false];
        }

        $expired = $angler->link_expires_at === null || $angler->link_expires_at < CarbonImmutable::now();

        return ['angler' => $expired ? null : $angler, 'expired' => $expired];
    }

    /**
     * Rezerwacja po tokenie zarządzania — ważna, dopóki rezerwacja jest aktywna, a link nie wygasł.
     *
     * @return array{reservation: Reservation|null, expired: bool}
     */
    public function findByManageToken(string $token): array
    {
        $reservation = Reservation::query()->with(['fishery', 'angler', 'terms', 'position'])
            ->where('manage_token_hash', self::hash($token))
            ->first();

        if ($reservation === null) {
            return ['reservation' => null, 'expired' => false];
        }

        // Rezygnacja ma sens, dopóki rezerwacja jest aktywna — także po potwierdzeniu powiązania.
        $expired = ! $reservation->isActive();

        return ['reservation' => $expired ? null : $reservation, 'expired' => $expired];
    }

    /**
     * Konto, z którym nastąpi powiązanie — istniejące o tym adresie albo `null` (powstanie nowe).
     * Wołane WYŁĄCZNIE na stronie po kliknięciu linku, nigdy w panelu (brak enumeracji kont).
     */
    public function accountFor(FisheryAngler $angler): ?User
    {
        if ($angler->user !== null) {
            return $angler->user;
        }

        $email = FisheryAngler::normalizeEmail($angler->given_email);
        $user = $email === null ? null : User::query()->where('email', $email)->first();

        // ⚠️ Kolacja `utf8mb4_unicode_ci` ignoruje akcenty (`josé@` = `jose@`) — zapytanie to tylko
        // pierwsze sito, adres musi się zgadzać dokładnie (`autoryzacja.md` §6).
        return $user !== null && mb_strtolower((string) $user->email) === $email ? $user : null;
    }

    /** Numer z konta różni się od numeru podanego łowisku — wędkarz wybiera (ADR-024 pkt 5). */
    public function hasPhoneConflict(FisheryAngler $angler): bool
    {
        $account = $this->accountFor($angler);

        return $account !== null
            && filled($account->phone)
            && filled($angler->given_phone)
            && self::digits((string) $account->phone) !== self::digits((string) $angler->given_phone);
    }

    /**
     * „Potwierdź i połącz z kontem": konto powstaje (adres zweryfikowany kliknięciem, bez hasła) albo
     * się dowiązuje, relacja przechodzi w `linked`, oczekujące rezerwacje — w potwierdzone (W3).
     *
     * @param  string  $phoneChoice  przy konflikcie telefonu: `profile` · `save` · `fishery`
     */
    public function confirm(FisheryAngler $angler, string $phoneChoice = 'profile', ?string $token = null): ?FisheryAngler
    {
        $linked = DB::transaction(function () use ($angler, $phoneChoice, $token): ?FisheryAngler {
            /** @var FisheryAngler $locked */
            $locked = FisheryAngler::query()->whereKey($angler->getKey())->lockForUpdate()->firstOrFail();

            // Ważność tokenu sprawdzana PONOWNIE pod blokadą (047, D3): "To nie ja" wysłane równolegle
            // zeruje token, a adres mógł się zmienić od otwarcia strony.
            if ($token !== null && ($locked->link_token_hash !== self::hash($token)
                || $locked->link_expires_at === null || $locked->link_expires_at < CarbonImmutable::now()
                || ! self::sentToCurrentEmail($locked))) {
                return null;
            }
            $user = $this->accountFor($locked) ?? $this->createAccount($locked);
            $override = null;

            if ($this->hasPhoneConflict($locked)) {
                if ($phoneChoice === 'save') {
                    $user->forceFill(['phone' => $locked->given_phone])->save();
                } elseif ($phoneChoice === 'fishery') {
                    $override = $locked->given_phone;
                }
            } elseif (blank($user->phone) && filled($locked->given_phone)) {
                $user->forceFill(['phone' => $locked->given_phone])->save();
            }

            $locked->forceFill([
                'user_id' => $user->getKey(),
                'status' => AnglerLinkStatus::Linked,
                'linked_at' => CarbonImmutable::now(),
                'phone_override' => $override,
                'link_token_hash' => null,
                'link_expires_at' => null,
                'link_email' => null,
            ])->save();

            $this->log($locked, 'linked');

            return $locked;
        });

        if ($linked === null) {
            return null;
        }

        foreach ($linked->reservations()->where('status', ReservationStatus::Pending->value)->get() as $reservation) {
            $this->booking->confirm($reservation, ConfirmationSource::Link);
        }

        return $linked;
    }

    /** „Zrezygnuj": anulacja tej rezerwacji przez wędkarza, zwrot z warunków umowy. */
    public function resign(Reservation $reservation, ?string $reason = null): BookingOutcome
    {
        $outcome = $this->booking->cancel($reservation, new CancellationRequest(
            reason: CancellationReason::Angler,
            message: filled($reason) ? $reason : null,
        ));

        $reservation->forceFill(['manage_token_hash' => null])->save();

        return $outcome;
    }

    /**
     * „To nie ja": prośba o powiązanie przestaje działać, rezerwacja trafia do weryfikacji przez
     * operatora („Do kontaktu") — bez anulacji (makieta H).
     */
    public function notMe(Reservation $reservation): void
    {
        $angler = $reservation->angler;

        if ($angler !== null) {
            $angler->forceFill([
                'link_token_hash' => null,
                'link_expires_at' => null,
                'link_email' => null,
                'status' => $angler->status === AnglerLinkStatus::Pending ? AnglerLinkStatus::Unlinked : $angler->status,
            ])->save();
            $this->log($angler, 'not_me');
        }

        $reservation->forceFill(['manage_token_hash' => null])->save();
        $this->booking->markNeedsContact($reservation);
    }

    /** Prośba poszła na adres, który relacja ma dziś (047, D2). */
    private static function sentToCurrentEmail(FisheryAngler $angler): bool
    {
        return $angler->link_email === null || $angler->link_email === FisheryAngler::normalizeEmail($angler->effectiveEmail());
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function createAccount(FisheryAngler $angler): User
    {
        $parts = preg_split('/\s+/', trim((string) $angler->given_name), 2) ?: [];

        $user = new User([
            'name' => $parts[0] ?? (string) $angler->given_name,
            'surname' => $parts[1] ?? '',
            'email' => FisheryAngler::normalizeEmail($angler->given_email),
            'password' => Str::random(64),
        ]);

        // Adres zweryfikowany kliknięciem w link; hasła wędkarz nie zna (`autoryzacja.md` §6).
        $user->forceFill(['email_verified_at' => CarbonImmutable::now(), 'has_password' => false])->save();

        return $user;
    }

    private function limited(FisheryAngler $angler, string $email, int $fisheryId): bool
    {
        $pending = FisheryAngler::query()
            ->where('given_email_normalized', $email)
            ->where('status', AnglerLinkStatus::Pending->value)
            ->where('link_expires_at', '>', CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'))
            ->whereKeyNot($angler->getKey())
            ->count();

        return $pending >= self::MAX_PENDING_PER_EMAIL
            || RateLimiter::tooManyAttempts(self::fisheryKey($fisheryId), self::MAX_SENDS_PER_FISHERY_PER_DAY);
    }

    private static function fisheryKey(int $fisheryId): string
    {
        return "angler-link-requests:fishery:{$fisheryId}";
    }

    private static function digits(string $phone): string
    {
        return (string) preg_replace('/\D+/', '', $phone);
    }

    private function log(FisheryAngler $angler, string $event): void
    {
        activity(FisheryAngler::LOG_NAME)
            ->performedOn($angler)
            ->event($event)
            ->log($event);
    }
}
