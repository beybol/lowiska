<?php

namespace App\Services;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;

/**
 * Dokąd trafia użytkownik po logowaniu, rejestracji, weryfikacji adresu i 2FA (zadanie 031).
 *
 * ⚠️ JEDYNE miejsce tej reguły. Pulpitu Breeze (`/dashboard`) nie ma: każde konto jest kontem panelu —
 * administrator (`is_admin`, jedyne źródło prawdy, ADR-018) trafia do panelu administratora, pozostali do
 * panelu właściciela.
 *
 * ⚠️ Wyjątek (zadanie 049, R2): gdy w sesji jest znacznik łączenia konta z łowiskiem (`AnglerLinkSession`),
 * logowanie wraca na stronę łączenia — `redirectAfterSignIn()`, wołane przez logowanie hasłem, Google,
 * kod 2FA i rejestrację. Bez znacznika przekierowanie się nie zmienia.
 */
final class PanelHome
{
    public static function urlFor(?User $user): string
    {
        $panel = $user?->is_admin ? 'admin' : 'owner';

        return Filament::getPanel($panel)->getUrl();
    }

    /**
     * Przekierowanie po udanym logowaniu: strona łączenia konta, gdy trwa łączenie; inaczej panel —
     * z `intended()` tam, gdzie ścieżka go używała.
     */
    public static function redirectAfterSignIn(?User $user, bool $intended = true): RedirectResponse
    {
        $linkUrl = AnglerLinkSession::pendingUrl();

        if ($linkUrl !== null) {
            return redirect()->to($linkUrl);
        }

        return $intended ? redirect()->intended(self::urlFor($user)) : redirect()->to(self::urlFor($user));
    }
}
