<?php

namespace App\Filament\Pages\Auth;

use Filament\Pages\Auth\Login as BaseLogin;
use Filament\Actions\Action;

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
}
