<?php

namespace App\Http\Controllers;

use App\Enums\ConsentContext;
use App\Enums\SignInMethod;
use App\Models\User;
use App\Notifications\SendTwoFactorCode;
use App\Services\ConsentRegistry;
use App\Services\OwnerRoleProvisioner;
use App\Services\PanelHome;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    /** Dane od dostawcy czekające na zgody przed założeniem konta (049). */
    private const PENDING_KEY = 'social_pending_account';

    public function __construct(private readonly ConsentRegistry $consents = new ConsentRegistry) {}

    public function redirect($provider, Request $request)
    {
        $source = $request->get('source', 'breeze');
        session(['social_auth_source' => $source]);

        return Socialite::driver($provider)->redirect();
    }

    public function callback($provider)
    {
        $socialUser = Socialite::driver($provider)->user()->user;
        $name = '';
        $surname = '';

        if ($provider === 'google') {
            $name = $socialUser['given_name'];
            $surname = $socialUser['family_name'];
        } elseif ($provider === 'facebook') {
            $fullName = $socialUser['name'];
            $name = explode(' ', $fullName)[0];
            $surname = explode(' ', $fullName)[1];
        }

        $providerId = (string) ($socialUser['id'] ?? '');
        $email = (string) ($socialUser['email'] ?? '');

        if ($providerId === '' || $email === '') {
            return redirect()->route('login')->withErrors([
                'email' => __('The provider did not return enough data to sign you in.'),
            ]);
        }

        // ⚠️ Adres MUSI być potwierdzony po stronie dostawcy, jeśli dostawca w ogóle
        // się w tej sprawie wypowiada. Bez tego wystarczyło u dostawcy ustawić adres
        // równy adresowi cudzego konta, żeby dostać do niego dostęp.
        if (array_key_exists('email_verified', $socialUser) && $socialUser['email_verified'] !== true) {
            return redirect()->route('login')->withErrors([
                'email' => __('Confirm your address with the provider before signing in this way.'),
            ]);
        }

        // 1. Znany dostawca + znane ID — to jest ten sam człowiek, co poprzednio.
        $user = User::query()
            ->where('provider', $provider)
            ->where('provider_id', $providerId)
            ->first();

        $status = null;

        if (! $user instanceof User) {
            // ⚠️ Dopasowanie po adresie BEZ rozróżniania wielkości liter i bez normalizacji
            // kropek czy aliasów — `Jan@Example.com` to ten sam adres, `jan.k@` i `jank@` już nie.
            // Kolacja kolumny (`utf8mb4_unicode_ci`) załatwia wielkość liter — ale też ignoruje akcenty,
            // stąd dokładne porównanie niżej.
            $existing = User::query()->where('email', $email)->first();

            // ⚠️ Kolacja `utf8mb4_unicode_ci` ignoruje nie tylko wielkość liter, ale też AKCENTY
            // (`josé@` = `jose@`), a to są różne skrzynki. Dopasowanie musi być DOKŁADNE po
            // sprowadzeniu do małych liter — inaczej adres z akcentem zająłby cudze konto. Nowego
            // konta też nie da się wtedy założyć (ta sama kolacja w indeksie unikalności).
            if ($existing instanceof User && mb_strtolower($existing->email) !== mb_strtolower($email)) {
                return redirect()->route('login')->withErrors([
                    'email' => __('An account with a similar address already exists. Sign in with your password.'),
                ]);
            }

            // 2. Adres należy do konta, którego nikt jeszcze nie powiązał z dostawcą → DOWIĄZANIE
            //    (ADR-019). Wyłącznie gdy dostawca JAWNIE potwierdza adres — brak klucza nie
            //    wystarcza. Bezpieczeństwo stoi na trzech warunkach naraz: potwierdzenie
            //    u dostawcy, weryfikacja adresu w obu panelach i 2FA wysyłane na adres KONTA.
            //    Zmiana któregokolwiek wymaga ponownej oceny ADR-019.
            if ($existing instanceof User && $existing->provider === null) {
                if (($socialUser['email_verified'] ?? null) !== true) {
                    return redirect()->route('login')->withErrors([
                        'email' => __('Confirm your address with the provider before signing in this way.'),
                    ]);
                }

                $status = $this->linkProvider($existing, $provider, $providerId);
                $user = $existing;
            } elseif ($existing instanceof User) {
                // 3. Adres powiązany z INNYM dostawcą — jedno powiązanie na konto.
                return redirect()->route('login')->withErrors([
                    'email' => __('This address is linked to a different sign-in provider.'),
                ]);
            } else {
                // 4. Nowe konto — ⚠️ NIE powstaje bez wymaganych zgód Fisherya (049, R3). Gdy są dokumenty
                // do zaakceptowania, dane od dostawcy czekają w sesji, a konto zakłada `consentsSubmit()`.
                $pending = compact('provider', 'providerId', 'email', 'name', 'surname');

                if ($this->consents->requiredPlatformDocuments() !== []) {
                    session()->put(self::PENDING_KEY, $pending);

                    return redirect()->route('social.consents');
                }

                $user = $this->createAccount($pending);
            }
        }

        return $this->finishSignIn($user, $status);
    }

    /** Ekran zgód przed założeniem konta przez dostawcę logowania (049). */
    public function consents(): View|RedirectResponse
    {
        if (! is_array(session(self::PENDING_KEY))) {
            return redirect()->route('login');
        }

        return view('auth.social-consents', [
            'documents' => $this->consents->requiredPlatformDocuments(),
            'email' => (string) session(self::PENDING_KEY.'.email'),
        ]);
    }

    public function consentsSubmit(Request $request): RedirectResponse
    {
        $pending = session(self::PENDING_KEY);

        if (! is_array($pending)) {
            return redirect()->route('login');
        }

        $documents = $this->consents->requiredPlatformDocuments();
        $request->validate(ConsentRegistry::rules($documents), ConsentRegistry::messages($documents));

        // Adres mógł zostać zajęty w międzyczasie (np. rejestracją hasłem) — wtedy zwykłe logowanie od nowa.
        if (User::query()->where('email', $pending['email'])->exists()) {
            session()->forget(self::PENDING_KEY);

            return redirect()->route('login')->withErrors([
                'email' => __('An account with this address already exists. Sign in again.'),
            ]);
        }

        $user = DB::transaction(function () use ($pending, $documents, $request): User {
            $user = $this->createAccount($pending);
            $this->consents->recordDocuments($user, $documents, ConsentContext::Registration, null, $request->ip());

            return $user;
        });

        session()->forget(self::PENDING_KEY);

        return $this->finishSignIn($user, null);
    }

    /**
     * Nowe konto od dostawcy. ⚠️ `provider`, `provider_id` i `has_password` NIE są w `$fillable` i mają tam
     * nie trafić — to klucz tożsamości logowania i powierzchnia mass-assignment. Hasło jest LOSOWE, więc
     * `has_password = false` (zadanie 028).
     *
     * @param  array{provider: string, providerId: string, email: string, name: string, surname: string}  $pending
     */
    private function createAccount(array $pending): User
    {
        $user = new User([
            'email' => $pending['email'],
            'name' => $pending['name'],
            'surname' => $pending['surname'],
            'password' => bcrypt(str()->random(16)),
        ]);
        $user->forceFill(['provider' => $pending['provider'], 'provider_id' => $pending['providerId'], 'has_password' => false]);
        $user->save();
        $user->wasRecentlyCreated = true;

        return $user;
    }

    private function finishSignIn(User $user, ?string $status): RedirectResponse
    {
        if ($user->wasRecentlyCreated) {
            // ⚠️ Rola nadawana WYŁĄCZNIE przy zakładaniu konta. Wołanie tego przy każdym
            // logowaniu przywracało rolę odebraną wcześniej przez administratora
            // (security-review, 2026-09-20).
            OwnerRoleProvisioner::addOwnerRole($user);
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        Auth::login($user);

        // ⚠️ Odnowienie identyfikatora sesji po zalogowaniu — dokładnie jak robi to
        // Breeze w `AuthenticatedSessionController::store()`. Bez tego ścieżka
        // społecznościowa zostawiała fiksację sesji (audyt bezpieczeństwa, zadanie 012).
        request()->session()->regenerate();

        $source = session('social_auth_source', 'breeze');
        session()->forget('social_auth_source');

        // ⚠️ Drugi składnik obowiązuje TAK SAMO jak przy logowaniu hasłem. Wcześniej
        // ta ścieżka wołała samo `Auth::login()`, więc ktokolwiek przeszedł flow
        // dostawcy dla adresu odpowiadającego lokalnemu użytkownikowi — w tym konta
        // `is_admin` — dostawał pełną sesję z pominięciem 2FA. `TwoFactorMiddleware`
        // tego nie łapał, bo traktuje PUSTY kod jako „brak oczekującego wyzwania".
        if (! $user->two_factor_code) {
            $user->generateTwoFactorCode();
            $user->notify(new SendTwoFactorCode);
            session()->put('two_factor_source', $source);

            // Komunikat po przejęciu konta niezweryfikowanego trafia na ekran 2FA — to pierwszy
            // ekran po powrocie od dostawcy (zadanie 028).
            return redirect()->route('verify.index')->with('status', $status);
        }

        // ⚠️ Konto ma już OCZEKUJĄCY kod 2FA (np. z wcześniejszego logowania hasłem), więc kod nie
        // jest generowany drugi raz. Komunikat o przejęciu i tak musi trafić na ekran 2FA:
        // przy przekierowaniu na pulpit middleware 2FA odbija dalej i flash ginie.
        if ($status !== null) {
            session()->put('two_factor_source', $source);

            return redirect()->route('verify.index')->with('status', $status);
        }

        if ($source === 'breeze') {
            // Pulpitu Breeze nie ma (zadanie 031) — konto trafia do swojego panelu, a przy łączeniu konta
            // z łowiskiem na stronę łączenia (049, R2).
            return PanelHome::redirectAfterSignIn($user, intended: false);
        } else {
            $panel = Filament::getPanel($source);

            if ($panel) {
                return redirect()->to($panel->getUrl());
            }

            return redirect()->route('filament.admin.pages.dashboard');
        }
    }

    /**
     * Dowiązanie dostawcy do istniejącego konta bez dostawcy (ADR-019).
     *
     * - konto ZWERYFIKOWANE → dostawca zapisany, hasło zostaje — konto hybrydowe;
     * - konto NIEZWERYFIKOWANE → było martwe dla swojego twórcy (oba panele wymagają weryfikacji),
     *   więc przejmuje je właściciel skrzynki: weryfikacja ustawiona, hasło zastąpione losowym.
     *
     * Wpis w dzienniku zmian zawiera wyłącznie NAZWĘ dostawcy, bez `provider_id` — identyfikator
     * konta u dostawcy to dana osobowa, a do audytu wystarcza fakt i moment dowiązania.
     *
     * @return string|null komunikat dla użytkownika na ekranie 2FA
     */
    private function linkProvider(User $user, string $provider, string $providerId): ?string
    {
        $wasVerified = $user->email_verified_at !== null;

        $user->forceFill(['provider' => $provider, 'provider_id' => $providerId]);

        if (! $wasVerified) {
            $user->forceFill([
                'email_verified_at' => now(),
                'password' => bcrypt(str()->random(16)),
                'has_password' => false,
            ]);
        }

        $user->save();

        activity()
            ->performedOn($user)
            ->causedBy($user)
            ->event('updated')
            ->withChanges([
                'old' => ['provider' => null],
                'attributes' => ['provider' => $provider],
            ])
            ->log('updated');

        return $wasVerified
            ? null
            : __('Your account now signs in with :provider. To sign in with a password as well, set one with "Forgot your password?".', [
                'provider' => SignInMethod::providerName($provider),
            ]);
    }
}
