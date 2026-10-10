<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\AnglerLinking;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Strony portalu pod tokenem z maila o rezerwacji (makieta H, zadanie 043) — bez logowania.
 *
 * - „Potwierdzenie" (`confirm`, token powiązania relacji): podsumowanie, lista danych widocznych dla
 *   łowiska, wybór telefonu przy konflikcie, przycisk „Potwierdź".
 * - „Rezygnacja" (`manage`, token rezerwacji): „Zrezygnuj" z opcjonalnym powodem albo „To nie ja".
 *
 * ⚠️ Stan zmienia wyłącznie POST (formularz z CSRF) — GET tylko pokazuje. Klient pocztowy i skaner
 * linków otwierają adres GET-em; gdyby to on potwierdzał, rezerwacja potwierdzałaby się sama.
 * Limit prób na adres IP: `ATTEMPTS_PER_MINUTE` (trasy w `routes/web.php`).
 */
class ReservationLinkController extends Controller
{
    public const ATTEMPTS_PER_MINUTE = 10;

    public function __construct(private readonly AnglerLinking $linking = new AnglerLinking) {}

    public function confirm(string $token): Response
    {
        ['angler' => $angler, 'expired' => $expired] = $this->linking->findByLinkToken($token);

        if ($angler === null) {
            return $this->invalid($expired);
        }

        return response()->view('portal.reservation.confirm', [
            'angler' => $angler,
            'fishery' => $angler->fishery,
            'reservations' => $angler->reservations()->active()->with(['terms', 'position', 'fishery'])->orderBy('starts_at')->get(),
            'phoneConflict' => $this->linking->hasPhoneConflict($angler),
            'accountPhone' => $this->linking->accountFor($angler)?->phone,
            'token' => $token,
        ]);
    }

    public function confirmSubmit(Request $request, string $token): Response
    {
        ['angler' => $angler, 'expired' => $expired] = $this->linking->findByLinkToken($token);

        if ($angler === null) {
            return $this->invalid($expired);
        }

        $choice = $request->validate(['phone' => ['nullable', 'in:profile,save,fishery']])['phone'] ?? 'profile';
        $this->linking->confirm($angler, $choice);

        return response()->view('portal.reservation.done', [
            'heading' => __('Reservation confirmed'),
            'message' => __('Thank you. Your reservation is confirmed and linked with your Fisherya account. You can set a password with "Forgot password" when you want to sign in.'),
            'fishery' => $angler->fishery,
        ]);
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
            'message' => __('Your reservation has been cancelled and the fishery has been informed.'),
            'fishery' => $reservation->fishery,
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
