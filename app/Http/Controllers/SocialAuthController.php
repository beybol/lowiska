<?php

namespace App\Http\Controllers;

use App\Helpers\Helper;
use App\Models\User;
use App\Notifications\SendTwoFactorCode;
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

        $user = User::firstOrCreate(
            ['email' => $socialUser['email']],
            [
                'name' => $name,
                'surname' => $surname,
                'password' => bcrypt(str()->random(16)),
            ]
        );
        Helper::addOwnerRole($user);

        if ($user->wasRecentlyCreated) {
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
