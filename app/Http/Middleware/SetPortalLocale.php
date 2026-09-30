<?php

namespace App\Http\Middleware;

use App\Services\PortalLocale;
use App\Services\PortalRoutes;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Język strony portalu z prefiksu adresu (`/pl/…`, `/en/…`) — zadanie 031.
 *
 * ⚠️ Podpięty na GRUPIE tras portalu w `routes/web.php`, nie w `bootstrap/app.php`: panele
 * Filamenta mają własny przełącznik języka i nie mogą dziedziczyć języka z adresu portalu.
 *
 * Zapamiętuje wybór w cookie — z niego korzystają wejścia bez prefiksu (`PortalLocale`).
 */
class SetPortalLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->route('locale');

        if (! is_string($locale) || ! in_array($locale, PortalRoutes::LOCALES, true)) {
            $locale = PortalRoutes::DEFAULT_LOCALE;
        }

        App::setLocale($locale);
        Cookie::queue(PortalLocale::COOKIE, $locale, PortalLocale::COOKIE_MINUTES);

        return $next($request);
    }
}
