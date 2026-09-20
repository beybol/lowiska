<?php

namespace App\Http\Controllers;

use App\Notifications\SendTwoFactorCode;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class TwoFactorController extends Controller
{
    /** Po tylu pudłach kod przestaje być ważny i trzeba poprosić o nowy. */
    private const MAX_ATTEMPTS = 5;

    /** Okno licznika — tyle samo, ile żyje kod, więc licznik nie przeżywa sekretu. */
    private const DECAY_SECONDS = 600;

    public function index()
    {
        return view('auth.twoFactor');
    }

    private static function throttleKey(int|string $userId): string
    {
        return 'two-factor:'.$userId;
    }

    public function store(Request $request): ValidationException|RedirectResponse
    {
        $request->validate([
            'two_factor_code' => ['integer', 'required'],
        ]);

        $user = auth()->user();
        $key = self::throttleKey($user->id);

        // ⚠️ Kod ma tylko sześć cyfr i żyje dziesięć minut, a sesja guarda `web`
        // istnieje JUŻ w momencie wyzwania — bez licznika napastnik z samym hasłem
        // przechodził przez milion kombinacji w pętli (security-review, 2026-09-20).
        // Sam `throttle` na trasie nie wystarcza: ogranicza tempo, nie liczbę prób
        // na konkretnym sekrecie.
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $user->resetTwoFactorCode();

            return back()->withErrors([
                'two_factor_code' => __('Too many attempts. The code has been invalidated — request a new one.'),
            ]);
        }

        if (
            ! $user->two_factor_code ||
            ! $user->two_factor_expires_at ||
            // ⚠️ Porównanie o stałym czasie — kod 2FA jest sekretem, a `!==` na stringach
            // kończy się na pierwszym różnym bajcie (audyt bezpieczeństwa, zadanie 012).
            ! hash_equals((string) $user->two_factor_code, (string) $request->two_factor_code) ||
            Carbon::parse($user->two_factor_expires_at)->isPast()
        ) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            return back()->withErrors([
                'two_factor_code' => __('Invalid or expired verification code.'),
            ]);
        }

        RateLimiter::clear($key);
        $user->resetTwoFactorCode();
        $source = session('two_factor_source', 'breeze');
        session()->forget('two_factor_source');

        if ($source === 'filament') {
            $currentPanel = Filament::getCurrentOrDefaultPanel();

            return redirect()->intended($currentPanel?->getUrl() ?? '/admin');
        }

        if ($source === 'filament_owner') {
            return redirect()->intended('/owner');
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }

    public function resend(): RedirectResponse
    {
        $user = auth()->user();

        // Nowy kod to nowy sekret — licznik prób startuje od zera, ale samo
        // wysyłanie jest ograniczone `throttle` na trasie, żeby odświeżanie kodu
        // nie stało się obejściem licznika.
        RateLimiter::clear(self::throttleKey($user->id));
        $user->generateTwoFactorCode();
        $user->notify(new SendTwoFactorCode);

        return redirect()
            ->back()
            ->withStatus(__('The two factor code has been sent again.'));
    }
}
