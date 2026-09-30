<?php

namespace App\Services;

use Illuminate\Http\Request;

/**
 * Język wędkarza dla wejść BEZ prefiksu języka: `/` i krótkiego adresu łowiska (zadanie 031).
 *
 * ⚠️ Jedna implementacja dla obu wejść — kolejność: cookie `locale` (ustawiane przy każdej
 * stronie z prefiksem, `SetPortalLocale`) → nagłówek `Accept-Language` → PL.
 */
final class PortalLocale
{
    public const COOKIE = 'locale';

    /** Rok — wybór języka ma przeżyć powrót po tygodniach, ale nie wiecznie. */
    public const COOKIE_MINUTES = 60 * 24 * 365;

    public static function resolve(Request $request): string
    {
        $fromCookie = $request->cookie(self::COOKIE);

        if (is_string($fromCookie) && in_array($fromCookie, PortalRoutes::LOCALES, true)) {
            return $fromCookie;
        }

        // Symfony zwraca PIERWSZY z podanych języków, gdy żaden nie pasuje — stąd PL na początku.
        $preferred = $request->getPreferredLanguage(PortalRoutes::LOCALES);

        return in_array($preferred, PortalRoutes::LOCALES, true) ? $preferred : PortalRoutes::DEFAULT_LOCALE;
    }
}
