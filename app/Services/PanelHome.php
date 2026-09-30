<?php

namespace App\Services;

use App\Models\User;
use Filament\Facades\Filament;

/**
 * Dokąd trafia użytkownik po logowaniu, rejestracji, weryfikacji adresu i 2FA (zadanie 031).
 *
 * ⚠️ JEDYNE miejsce tej reguły. Pulpitu Breeze (`/dashboard`) nie ma: kont wędkarzy nie ma do
 * etapu 4, więc każde konto jest kontem panelu — administrator (`is_admin`, jedyne źródło prawdy,
 * ADR-018) trafia do panelu administratora, pozostali do panelu właściciela.
 */
final class PanelHome
{
    public static function urlFor(?User $user): string
    {
        $panel = $user?->is_admin ? 'admin' : 'owner';

        return Filament::getPanel($panel)->getUrl();
    }
}
