<?php

// app/Http/Responses/LoginResponse.php

namespace App\Http\Responses;

use App\Notifications\SendTwoFactorCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class LoginResponse implements \Filament\Auth\Http\Responses\Contracts\LoginResponse
{
    public function toResponse($request): RedirectResponse
    {
        $user = Auth::user();

        $user->generateTwoFactorCode();

        $user->notify(new SendTwoFactorCode);

        return redirect()->intended(filament()->getUrl());
    }
}
