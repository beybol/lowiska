<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\SendTwoFactorCode;
use App\Services\OwnerRoleProvisioner;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
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

        if (! $user instanceof User) {
            $existing = User::query()->where('email', $email)->first();

            // 2. Adres należy do KONTA HASŁOWEGO, którego nikt jeszcze nie powiązał
            //    z tym dostawcą. Nie logujemy cicho — to jest dokładnie ten scenariusz
            //    przejęcia konta. Właściciel konta musi połączyć je świadomie.
            if ($existing instanceof User && $existing->provider === null) {
                return redirect()->route('login')->withErrors([
                    'email' => __('An account with this address already exists. Sign in with your password first.'),
                ]);
            }

            // 3. Adres powiązany z INNYM dostawcą — też nie jest to ta sama tożsamość.
            if ($existing instanceof User) {
                return redirect()->route('login')->withErrors([
                    'email' => __('This address is linked to a different sign-in provider.'),
                ]);
            }

            // ⚠️ `provider` i `provider_id` NIE są w `$fillable` i mają tam nie trafić:
            // to jest klucz tożsamości logowania, a `$fillable` to powierzchnia
            // mass-assignment. Stąd jawne `forceFill` zamiast wpisu w tablicy tworzącej.
            $user = new User([
                'email' => $email,
                'name' => $name,
                'surname' => $surname,
                'password' => bcrypt(str()->random(16)),
            ]);
            $user->forceFill(['provider' => $provider, 'provider_id' => $providerId]);
            $user->save();
            $user->wasRecentlyCreated = true;
        }

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

            return redirect()->route('verify.index');
        }

        if ($source === 'breeze') {
            return redirect()->route('dashboard');
        } else {
            $panel = Filament::getPanel($source);

            if ($panel) {
                return redirect()->to($panel->getUrl());
            }

            return redirect()->route('filament.admin.pages.dashboard');
        }
    }
}
