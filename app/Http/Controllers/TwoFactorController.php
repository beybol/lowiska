<?php

namespace App\Http\Controllers;

use App\Notifications\SendTwoFactorCode;
use App\Providers\RouteServiceProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;
use Filament\Facades\Filament;

class TwoFactorController extends Controller
{
    public function index()
    {
        return view('auth.twoFactor');
    }

    public function store(Request $request): ValidationException|RedirectResponse
    {
        $request->validate([
            'two_factor_code' => ['integer', 'required'],
        ]);

        $user = auth()->user();

        if (
            !$user->two_factor_code ||
            !$user->two_factor_expires_at ||
            $user->two_factor_code !== $request->two_factor_code ||
            Carbon::parse($user->two_factor_expires_at)->isPast()
        ) {
            return back()->withErrors([
                'two_factor_code' 
                    => __('Invalid or expired verification code.'),
            ]);
        }

        $user->resetTwoFactorCode();

        return redirect()
            ->intended(Filament::getCurrentPanel()?->getUrl() ?? '/');
    }

    public function resend(): RedirectResponse
    {
        $user = auth()->user();
        $user->generateTwoFactorCode();
        $user->notify(new SendTwoFactorCode());

        return redirect()
            ->back()
            ->withStatus(__('The two factor code has been sent again.'));
    }
}
