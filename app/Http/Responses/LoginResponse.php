<?php
// app/Http/Responses/LoginResponse.php
namespace App\Http\Responses;

use App\Notifications\SendTwoFactorCode;
use Filament\Http\Responses\Auth\Contracts\LoginResponse as Contract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class LoginResponse implements Contract
{
    public function toResponse($request): RedirectResponse
    {
        $user = Auth::user();

        $user->generateTwoFactorCode();

        $user->notify(new SendTwoFactorCode());

        return redirect()->intended(filament()->getUrl());
    }
}
