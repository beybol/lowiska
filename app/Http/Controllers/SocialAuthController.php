<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Filament\Facades\Filament;
use App\Helpers\Helper;

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

        $source = session('social_auth_source', 'breeze');
        session()->forget('social_auth_source');
        
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
