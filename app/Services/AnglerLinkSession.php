<?php

namespace App\Services;

/**
 * Znacznik „trwa łączenie konta z łowiskiem" w sesji (zadanie 049, rozstrzygnięcie R2).
 *
 * Ustawia go strona pod tokenem z maila, zanim wędkarz przejdzie do logowania albo rejestracji; każda
 * ścieżka logowania (hasło, Google, kod 2FA) po sukcesie pyta `pendingUrl()` i wraca na stronę łączenia
 * (`PanelHome::redirectAfterSignIn()`). Bez znacznika przekierowania się nie zmieniają.
 *
 * ⚠️ W sesji leży HASH tokenu, nie token — ten sam, który jest w bazie (`fishery_anglers.link_token_hash`).
 * Strona łączenia (`/{język}/rezerwacja/konto`) nie ma tokenu w adresie i szuka relacji po tym hashu.
 * Ważność, adres i zużycie tokenu sprawdza za każdym razem `AnglerLinking`, nie ta klasa.
 */
final class AnglerLinkSession
{
    private const KEY = 'angler_link';

    public static function remember(string $tokenHash, string $locale): void
    {
        session()->put(self::KEY, ['hash' => $tokenHash, 'locale' => $locale]);
    }

    public static function hash(): ?string
    {
        $hash = session(self::KEY.'.hash');

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    public static function forget(): void
    {
        session()->forget(self::KEY);
    }

    /** Adres strony łączenia, gdy znacznik jest w sesji — inaczej `null`. */
    public static function pendingUrl(): ?string
    {
        if (self::hash() === null) {
            return null;
        }

        $locale = session(self::KEY.'.locale');

        return PortalRoutes::reservationAccountUrl(in_array($locale, PortalRoutes::LOCALES, true) ? $locale : null);
    }
}
