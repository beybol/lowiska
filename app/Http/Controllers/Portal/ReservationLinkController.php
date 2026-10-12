<?php

namespace App\Http\Controllers\Portal;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\FisheryAngler;
use App\Models\Reservation;
use App\Models\User;
use App\Services\AnglerLinking;
use App\Services\AnglerLinkSession;
use App\Services\ConsentRegistry;
use App\Services\PortalRoutes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

/**
 * Strony portalu pod tokenem z maila o rezerwacji (makieta H, zadania 043 i 049).
 *
 * - „Potwierdzenie" (`confirm`, token powiązania relacji): podsumowanie i „Potwierdź rezerwację" — BEZ
 *   konta. Po potwierdzeniu zachęta do połączenia z kontem; „Połącz konto" zapisuje znacznik w sesji
 *   (`AnglerLinkSession`) i prowadzi na stronę łączenia.
 * - „Łączenie konta" (`account`, bez tokenu w adresie — relacja ze znacznika w sesji): gość widzi
 *   „Zaloguj się" (konto o adresie z prośby istnieje) albo formularz założenia konta; zalogowany na konto
 *   o adresie z prośby — zgody łowiska i „Połącz"; zalogowany na inne konto — odmowę z wylogowaniem.
 * - „Rezygnacja" (`manage`, token rezerwacji): „Zrezygnuj" z opcjonalnym powodem albo „To nie ja".
 *
 * ⚠️ Stan zmienia wyłącznie POST (formularz z CSRF) — GET tylko pokazuje. Klient pocztowy i skaner
 * linków otwierają adres GET-em; gdyby to on potwierdzał, rezerwacja potwierdzałaby się sama.
 * Limit prób na adres IP: `ATTEMPTS_PER_MINUTE` (trasy w `routes/web.php`). Strona łączenia ma też
 * middleware `two_factor`: sesja z nieprzepisanym kodem 2FA nie połączy konta.
 */
class ReservationLinkController extends Controller
{
    public const ATTEMPTS_PER_MINUTE = 10;

    public function __construct(
        private readonly AnglerLinking $linking = new AnglerLinking,
        private readonly ConsentRegistry $consents = new ConsentRegistry,
    ) {}

    public function confirm(string $token): Response
    {
        ['angler' => $angler, 'expired' => $expired] = $this->linking->findByLinkToken($token);

        if ($angler === null) {
            return $this->invalid($expired);
        }

        $reservations = $angler->reservations()->active()->with(['terms', 'position', 'fishery'])->orderBy('starts_at')->get();

        return response()->view('portal.reservation.confirm', [
            'angler' => $angler,
            'fishery' => $angler->fishery,
            'reservations' => $reservations,
            'pending' => $reservations->contains(fn (Reservation $reservation): bool => $reservation->status === ReservationStatus::Pending),
            'hasAccount' => $this->linking->accountFor($angler) !== null,
            'token' => $token,
        ]);
    }

    public function confirmSubmit(Request $request, string $token): Response|RedirectResponse
    {
        ['angler' => $angler, 'expired' => $expired] = $this->linking->findByLinkToken($token);

        if ($angler === null) {
            return $this->invalid($expired);
        }

        // „Połącz konto" — znacznik w sesji przed przejściem do logowania albo rejestracji (R2).
        if ($request->input('action') === 'account') {
            AnglerLinkSession::remember(AnglerLinking::hash($token), app()->getLocale());

            return redirect()->to(PortalRoutes::reservationAccountUrl());
        }

        if ($this->linking->confirmReservations($angler, $token) === null) {
            return $this->invalid(false);
        }

        return response()->view('portal.reservation.confirmed', [
            'fishery' => $angler->fishery,
            'hasAccount' => $this->linking->accountFor($angler) !== null,
            'token' => $token,
        ]);
    }

    public function account(Request $request): Response
    {
        $angler = $this->sessionAngler();

        if ($angler === null) {
            return $this->invalid(false);
        }

        $user = $request->user();

        return response()->view('portal.reservation.account', [
            'angler' => $angler,
            'fishery' => $angler->fishery,
            'email' => AnglerLinking::linkEmail($angler),
            'mode' => $this->mode($angler, $user),
            'fisheryDocuments' => $this->consents->requiredFisheryDocuments($angler->fishery),
            'platformDocuments' => $this->consents->requiredPlatformDocuments(),
            'phoneConflict' => $user instanceof User && $this->linking->hasPhoneConflict($angler, $user),
            'accountPhone' => $user?->phone,
        ]);
    }

    public function accountSubmit(Request $request): Response|RedirectResponse
    {
        $angler = $this->sessionAngler();

        if ($angler === null) {
            return $this->invalid(false);
        }

        $tokenHash = (string) AnglerLinkSession::hash();
        $user = $request->user();
        $mode = $this->mode($angler, $user);

        return match ($request->input('action')) {
            'link' => $mode === 'link' && $user instanceof User
                ? $this->link($request, $angler, $user, $tokenHash)
                : redirect()->to(PortalRoutes::reservationAccountUrl()),
            'register' => $mode === 'register'
                ? $this->register($request, $angler, $tokenHash)
                : redirect()->to(PortalRoutes::reservationAccountUrl()),
            'logout' => $this->logoutKeepingLink($request, $tokenHash),
            default => redirect()->to(PortalRoutes::reservationAccountUrl()),
        };
    }

    public function manage(string $token): Response
    {
        ['reservation' => $reservation, 'expired' => $expired] = $this->linking->findByManageToken($token);

        if ($reservation === null) {
            return $this->invalid($expired);
        }

        return response()->view('portal.reservation.manage', [
            'reservation' => $reservation,
            'fishery' => $reservation->fishery,
            'token' => $token,
        ]);
    }

    public function manageSubmit(Request $request, string $token): Response
    {
        ['reservation' => $reservation, 'expired' => $expired] = $this->linking->findByManageToken($token);

        if ($reservation === null) {
            return $this->invalid($expired);
        }

        $data = $request->validate([
            'action' => ['required', 'in:resign,not_me'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($data['action'] === 'not_me') {
            $this->linking->notMe($reservation);

            return response()->view('portal.reservation.done', [
                'heading' => __('Thank you for letting us know'),
                'message' => __('The request has been withdrawn. The fishery will check the reservation — your address will not receive further messages about it.'),
                'fishery' => $reservation->fishery,
            ]);
        }

        $this->linking->resign($reservation, $data['reason'] ?? null);

        return response()->view('portal.reservation.done', [
            'heading' => __('Reservation cancelled'),
            // Prawda o skutku (047, H2): operator widzi anulację przez wędkarza na liście i w kalendarzu.
            'message' => __('Your reservation has been cancelled. The fishery sees the cancellation in its reservation list.'),
            'fishery' => $reservation->fishery,
        ]);
    }

    /**
     * Co widzi posiadacz linku na stronie łączenia:
     * `sign-in` (gość, konto o adresie z prośby istnieje) · `register` (gość, konta nie ma) ·
     * `link` (zalogowany na konto o adresie z prośby) · `wrong-account` (zalogowany na inne konto).
     */
    private function mode(FisheryAngler $angler, ?User $user): string
    {
        if ($user instanceof User) {
            return AnglerLinking::matchesAccount($angler, $user) ? 'link' : 'wrong-account';
        }

        return $this->linking->accountFor($angler) !== null ? 'sign-in' : 'register';
    }

    private function link(Request $request, FisheryAngler $angler, User $user, string $tokenHash): Response
    {
        $documents = $this->consents->requiredFisheryDocuments($angler->fishery);
        $data = $request->validate(
            ['phone' => ['nullable', 'in:profile,save,fishery'], ...ConsentRegistry::rules($documents)],
            ConsentRegistry::messages($documents),
        );

        if ($this->linking->link($angler, $user, $tokenHash, $documents, $data['phone'] ?? 'profile', $request->ip()) === null) {
            AnglerLinkSession::forget();

            return $this->invalid(false);
        }

        AnglerLinkSession::forget();

        return $this->linked($angler);
    }

    private function register(Request $request, FisheryAngler $angler, string $tokenHash): Response
    {
        $platformDocuments = $this->consents->requiredPlatformDocuments();
        $fisheryDocuments = $this->consents->requiredFisheryDocuments($angler->fishery);

        // ⚠️ Adresu NIE ma w regułach: konto dostaje adres z prośby (`AnglerLinking::linkEmail()`), nigdy
        // wartość przysłaną w żądaniu.
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'surname' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
            ...ConsentRegistry::rules($platformDocuments),
            ...ConsentRegistry::rules($fisheryDocuments),
        ], [
            ...ConsentRegistry::messages($platformDocuments),
            ...ConsentRegistry::messages($fisheryDocuments),
        ]);

        $user = $this->linking->registerAndLink($angler, $tokenHash, [
            'name' => $data['name'],
            'surname' => $data['surname'],
            'password' => $data['password'],
        ], $platformDocuments, $fisheryDocuments, $request->ip());

        if (! $user instanceof User) {
            return $this->invalid(false);
        }

        Auth::login($user);
        $request->session()->regenerate();
        AnglerLinkSession::forget();

        return $this->linked($angler);
    }

    /**
     * „Wyloguj się" przy koncie o innym adresie — sesja jest unieważniana jak przy zwykłym wylogowaniu,
     * a znacznik łączenia wraca do nowej sesji, żeby wędkarz mógł zalogować się właściwym kontem.
     */
    private function logoutKeepingLink(Request $request, string $tokenHash): RedirectResponse
    {
        $locale = app()->getLocale();

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        AnglerLinkSession::remember($tokenHash, $locale);

        return redirect()->to(PortalRoutes::reservationAccountUrl());
    }

    private function sessionAngler(): ?FisheryAngler
    {
        $angler = $this->linking->findByLinkHash(AnglerLinkSession::hash())['angler'];

        if ($angler === null) {
            AnglerLinkSession::forget();
        }

        return $angler;
    }

    private function linked(FisheryAngler $angler): Response
    {
        return response()->view('portal.reservation.done', [
            'heading' => __('Your account is linked'),
            'message' => __('Your Fisherya account is now linked with this fishery. Your reservations at this fishery will appear in your account.'),
            'fishery' => $angler->fishery,
        ]);
    }

    private function invalid(bool $expired): Response
    {
        return response()->view('portal.reservation.done', [
            'heading' => $expired ? __('This link has expired') : __('This link is no longer valid'),
            'message' => $expired
                ? __('The link from the email has expired. Contact the fishery to confirm or change the reservation.')
                : __('The link has already been used or is incorrect. If you need to change the reservation, contact the fishery.'),
            'fishery' => null,
        ], $expired ? 410 : 404);
    }
}
