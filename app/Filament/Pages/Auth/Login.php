<?php

namespace App\Filament\Pages\Auth;

use Filament\Pages\Auth\Login as BaseLogin;
use Filament\Actions\Action;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use App\Notifications\SendTwoFactorCode;

class Login extends BaseLogin
{
    protected function getFormActions(): array
    {
        return [
            ...parent::getFormActions(),
            Action::make('login_google')
                ->label(__('Login with Google'))
                ->color('gray')
                ->icon(fn () => view('components.icons.google'))
                ->url(route('social.redirect', 'google')),
            Action::make('login_facebook')
                ->label(__('Login with Facebook'))
                ->color('gray')
                ->icon(fn () => view('components.icons.facebook'))
                ->url(route('social.redirect', 'facebook')),
        ];
    }

    public function authenticate(): ?LoginResponse
    {
        $response = parent::authenticate();

        $user = auth()->user();

        if ($user && !$user->two_factor_code) {
            $user->generateTwoFactorCode();
            $user->notify(new SendTwoFactorCode());
        }

        return $response;
    }
}
