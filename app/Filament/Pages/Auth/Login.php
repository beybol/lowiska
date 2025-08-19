<?php

namespace App\Filament\Pages\Auth;

use Filament\Pages\Auth\Login as BaseLogin;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Helpers\Helper;
use App\Notifications\SendTwoFactorCode;

class Login extends BaseLogin
{
    protected function getFormActions(): array
    {
        return [
            ...parent::getFormActions(),
            ...Helper::getSocialAuthActions('login'),
        ];
    }

    public function authenticate(): ?LoginResponse
    {
        $response = parent::authenticate();
        
        $user = auth()->user();

        if ($user && !$user->two_factor_code) {
            $user->generateTwoFactorCode();
            $user->notify(new SendTwoFactorCode());
            session()->put('two_factor_source', 'filament');
            $redirectResponse = new RedirectResponse(route('verify.index'));
            
            throw new HttpResponseException($redirectResponse);
        }

        return $response;
    }
}
