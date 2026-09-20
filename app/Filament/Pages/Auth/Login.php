<?php

namespace App\Filament\Pages\Auth;

use App\Notifications\SendTwoFactorCode;
use App\Services\SharedFormComponents;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Facades\Filament;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;

class Login extends \Filament\Auth\Pages\Login
{
    protected function getFormActions(): array
    {
        return [
            ...parent::getFormActions(),
            ...SharedFormComponents::getSocialAuthActions('login'),
        ];
    }

    public function authenticate(): ?LoginResponse
    {
        $response = parent::authenticate();

        $user = auth()->user();

        if ($user) {
            $currentPanel = Filament::getCurrentOrDefaultPanel();
            $panelId = $currentPanel ? $currentPanel->getId() : 'admin';

            $user->generateTwoFactorCode();
            $user->notify(new SendTwoFactorCode);

            $source = $panelId === 'owner' ? 'filament_owner' : 'filament';
            session()->put('two_factor_source', $source);

            $redirectResponse = new RedirectResponse(route('verify.index'));

            throw new HttpResponseException($redirectResponse);
        }

        return $response;
    }
}
