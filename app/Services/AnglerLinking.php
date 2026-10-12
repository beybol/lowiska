<?php

namespace App\Services;

use App\Enums\AnglerLinkStatus;
use App\Enums\CancellationReason;
use App\Enums\ConfirmationSource;
use App\Enums\ConsentContext;
use App\Enums\ReservationStatus;
use App\Mail\ReservationRequestMail;
use App\Models\Document;
use App\Models\FisheryAngler;
use App\Models\Reservation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Potwierdzenie rezerwacji linkiem z maila i połączenie wędkarza z kontem (zadania 043 i 049, ADR-024
 * z aktualizacją 2026-10-11, ADR-028).
 *
 * ⚠️ **Potwierdzenie rezerwacji NIE wymaga konta** (`confirmReservations()`): kliknięcie dowodzi dostępu do
 * skrzynki, a tego potrzebuje łowisko. Nie tworzy i nie zmienia żadnego rekordu `users` i nie zużywa tokenu.
 *
 * ⚠️ **Połączenie z kontem wymaga konta o adresie RÓWNYM adresowi z prośby** (`link_email`) — istniejące
 * wyłącznie po zalogowaniu (`link()`), nowe zakłada sam wędkarz hasłem (`registerAndLink()`) albo przez
 * Google. System nie zakłada kont bez udziału wędkarza. Przy połączeniu wędkarz akceptuje wymagane
 * dokumenty łowiska, przy założeniu konta — także Fisherya (rejestr: `ConsentRegistry`). Zapis telefonu
 * w profilu (konflikt telefonu, ADR-024 pkt 5) jest możliwy wyłącznie w kroku połączenia.
 *
 * Operator nigdy nie pisze do `users` i zawsze widzi ten sam komunikat („wysłano prośbę o potwierdzenie"),
 * więc nie da się z panelu sprawdzić, czy adres jest w portalu. Telefon nie łączy kont.
 *
 * Dwa tokeny, w bazie tylko hash (SHA-256), ważność `link_expires_at`:
 * - `fishery_anglers.link_token_hash` — „Potwierdź rezerwację": potwierdza oczekujące rezerwacje wędkarza
 *   w łowisku i pozwala połączyć konto do upływu ważności (R1); zużywa go dopiero połączenie;
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
        private readonly ConsentRegistry $consents = new ConsentRegistry,
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
        return $this->findByLinkHash(self::hash($token));
    }

    /**
     * Relacja po HASHU tokenu powiązania — strona łączenia konta zna tylko hash ze znacznika w sesji
     * (`AnglerLinkSession`). Te same warunki co `findByLinkToken()`.
     *
     * @return array{angler: FisheryAngler|null, expired: bool}
     */
    public function findByLinkHash(?string $hash): array
    {
        $angler = $hash === null ? null : FisheryAngler::query()->with(['fishery', 'user'])->where('link_token_hash', $hash)->first();

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
     * Adres, na który poszła prośba — tylko z kontem o TYM adresie wolno połączyć relację (049).
     * Prośby sprzed zadania 047 nie mają `link_email`; wtedy obowiązuje adres podany łowisku.
     */
    public static function linkEmail(FisheryAngler $angler): ?string
    {
        return $angler->link_email ?? FisheryAngler::normalizeEmail($angler->given_email);
    }

    /**
     * Istniejące konto o adresie z prośby albo `null`. Wołane WYŁĄCZNIE na stronach pod tokenem i na stronie
     * łączenia (posiadacz linku), nigdy w panelu — brak enumeracji kont.
     */
    public function accountFor(FisheryAngler $angler): ?User
    {
        if ($angler->user !== null) {
            return $angler->user;
        }

        $email = self::linkEmail($angler);
        $user = $email === null ? null : User::query()->where('email', $email)->first();

        return $user !== null && self::sameAddress($user, $email) ? $user : null;
    }

    /** Czy konto ma DOKŁADNIE adres z prośby — warunek połączenia relacji z kontem (049). */
    public static function matchesAccount(FisheryAngler $angler, User $user): bool
    {
        $email = self::linkEmail($angler);

        return $email !== null && self::sameAddress($user, $email);
    }

    /** Numer z konta różni się od numeru podanego łowisku — wędkarz wybiera (ADR-024 pkt 5). */
    public function hasPhoneConflict(FisheryAngler $angler, ?User $account = null): bool
    {
        $account ??= $this->accountFor($angler);

        return $account !== null
            && filled($account->phone)
            && filled($angler->given_phone)
            && self::digits((string) $account->phone) !== self::digits((string) $angler->given_phone);
    }

    /**
     * „Potwierdź rezerwację" — BEZ konta (049): oczekujące rezerwacje wędkarza w łowisku przechodzą
     * w potwierdzone (`ConfirmationSource::Link`). Nie tworzy ani nie zmienia rekordu `users` i nie zużywa
     * tokenu — link dalej pozwala połączyć konto do `link_expires_at` (R1).
     *
     * @return int|null liczba potwierdzonych rezerwacji albo `null`, gdy token przestał być ważny
     */
    public function confirmReservations(FisheryAngler $angler, string $token): ?int
    {
        // Ważność tokenu sprawdzana PONOWNIE pod blokadą (047, D3): "To nie ja" wysłane równolegle
        // zeruje token, a adres mógł się zmienić od otwarcia strony.
        $valid = DB::transaction(function () use ($angler, $token): bool {
            $locked = FisheryAngler::query()->whereKey($angler->getKey())->lockForUpdate()->firstOrFail();

            return self::tokenStillValid($locked, self::hash($token));
        });

        if (! $valid) {
            return null;
        }

        $confirmed = 0;

        foreach ($angler->reservations()->where('status', ReservationStatus::Pending->value)->get() as $reservation) {
            $this->booking->confirm($reservation, ConfirmationSource::Link);
            $confirmed++;
        }

        if ($confirmed > 0) {
            $this->log($angler, 'reservations_confirmed');
        }

        return $confirmed;
    }

    /**
     * Połączenie relacji z kontem ZALOGOWANEGO wędkarza (049). Warunki sprawdzane pod blokadą wiersza
     * relacji: token wciąż ważny, prośba poszła na adres, który relacja ma dziś (047, D2/D3), konto ma adres
     * równy adresowi z prośby. Zapisuje akceptację wymaganych dokumentów łowiska, rozstrzyga konflikt
     * telefonu, zużywa token i potwierdza oczekujące rezerwacje (kliknięcie dowiodło skrzynki).
     *
     * @param  list<Document>  $fisheryDocuments  wymagane dokumenty łowiska zaakceptowane w formularzu
     * @param  string  $phoneChoice  przy konflikcie telefonu: `profile` · `save` · `fishery`
     */
    public function link(
        FisheryAngler $angler,
        User $user,
        string $tokenHash,
        array $fisheryDocuments,
        string $phoneChoice = 'profile',
        ?string $ipAddress = null,
    ): ?FisheryAngler {
        $linked = DB::transaction(function () use ($angler, $user, $tokenHash, $fisheryDocuments, $phoneChoice, $ipAddress): ?FisheryAngler {
            /** @var FisheryAngler $locked */
            $locked = FisheryAngler::query()->whereKey($angler->getKey())->lockForUpdate()->firstOrFail();

            if (! self::tokenStillValid($locked, $tokenHash) || ! self::matchesAccount($locked, $user)) {
                return null;
            }

            $override = null;

            if ($this->hasPhoneConflict($locked, $user)) {
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

            $this->consents->recordDocuments($user, $fisheryDocuments, ConsentContext::AnglerLink, $locked, $ipAddress);
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

    /**
     * Założenie konta hasłem ze strony łączenia i od razu połączenie (049). Adres konta to adres z prośby —
     * NIGDY wartość z żądania; kliknięcie w link go zweryfikowało, więc konto ma `email_verified_at` bez
     * osobnego maila. Konto wędkarza nie dostaje roli. Akceptacje Fisherya zapisują się z kontekstem
     * rejestracji, łowiska — z kontekstem połączenia.
     *
     * @param  array{name: string, surname: string, password: string}  $data
     * @param  list<Document>  $platformDocuments
     * @param  list<Document>  $fisheryDocuments
     */
    public function registerAndLink(
        FisheryAngler $angler,
        string $tokenHash,
        array $data,
        array $platformDocuments,
        array $fisheryDocuments,
        ?string $ipAddress = null,
    ): ?User {
        return DB::transaction(function () use ($angler, $tokenHash, $data, $platformDocuments, $fisheryDocuments, $ipAddress): ?User {
            $locked = FisheryAngler::query()->whereKey($angler->getKey())->lockForUpdate()->firstOrFail();
            $email = self::linkEmail($locked);

            if ($email === null || ! self::tokenStillValid($locked, $tokenHash) || User::query()->where('email', $email)->exists()) {
                return null;
            }

            $user = new User([
                'name' => $data['name'],
                'surname' => $data['surname'],
                'email' => $email,
                'password' => $data['password'],
            ]);
            // Adres zweryfikowany kliknięciem w link; hasło ustawił człowiek (`has_password` domyślnie `true`).
            $user->forceFill(['email_verified_at' => CarbonImmutable::now()])->save();

            $this->consents->recordDocuments($user, $platformDocuments, ConsentContext::Registration, null, $ipAddress);

            return $this->link($locked, $user, $tokenHash, $fisheryDocuments, 'profile', $ipAddress) === null ? null : $user;
        });
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

    /** Token w bazie wciąż ten sam, nie wygasł, a prośba poszła na adres, który relacja ma dziś. */
    private static function tokenStillValid(FisheryAngler $angler, string $tokenHash): bool
    {
        return $angler->link_token_hash !== null
            && hash_equals($angler->link_token_hash, $tokenHash)
            && $angler->link_expires_at !== null
            && $angler->link_expires_at >= CarbonImmutable::now()
            && self::sentToCurrentEmail($angler);
    }

    /**
     * ⚠️ Kolacja `utf8mb4_unicode_ci` ignoruje akcenty (`josé@` = `jose@`) — zapytanie po adresie to tylko
     * pierwsze sito, adres musi się zgadzać dokładnie (`autoryzacja.md` §6).
     */
    private static function sameAddress(User $user, string $normalizedEmail): bool
    {
        return mb_strtolower((string) $user->email) === $normalizedEmail;
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
