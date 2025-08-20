<?php

namespace App\Filament\Pages\Auth;

use Filament\Pages\Auth\Login as BaseLogin;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Helpers\Helper;
use App\Notifications\SendTwoFactorCode;
use Filament\Facades\Filament;

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

        if ($user) {
            $currentPanel = Filament::getCurrentPanel();
            $panelId = $currentPanel ? $currentPanel->getId() : 'admin';
            
            $user->generateTwoFactorCode();
            $user->notify(new SendTwoFactorCode());
            
            $source = $panelId === 'owner' ? 'filament_owner' : 'filament';
            session()->put('two_factor_source', $source);
            
            $redirectResponse = new RedirectResponse(route('verify.index'));
            
            throw new HttpResponseException($redirectResponse);
        }

        return $response;
    }
}
