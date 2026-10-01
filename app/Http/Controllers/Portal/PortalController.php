<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Fishery;
use App\Services\PortalCalendar;
use App\Services\PortalFisheries;
use App\Services\PortalFisheryPage;
use App\Services\PortalLocale;
use App\Services\PortalRoutes;
use App\Services\PortalSlugs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;
use Illuminate\View\View;

/**
 * Strony portalu wędkarza — zadanie 031, adresy wg ADR-021 (opcja B).
 *
 * ⚠️ **Portal niczego nie liczy.** Cena, sprzedawalność i zasady pochodzą z warstwy oferty
 * (032/033); tutaj są wyłącznie trasy, lista łowisk opublikowanych i strony informacyjne.
 */
class PortalController extends Controller
{
    /** `/` → strona główna w języku wędkarza (cookie → `Accept-Language` → PL). */
    public function root(Request $request): RedirectResponse
    {
        return redirect()->to(PortalRoutes::homeUrl(PortalLocale::resolve($request)), 302);
    }

    public function home(): View
    {
        $fisheries = PortalFisheries::listed();

        return view('portal.home', [
            'cards' => $fisheries->map(fn (Fishery $fishery): array => PortalFisheries::card($fishery))->all(),
        ]);
    }

    public function page(Request $request): View
    {
        $page = (string) $request->route()?->defaults['page'];

        return view("portal.pages.{$page}", [
            'page' => $page,
            'fisheryNames' => $page === 'for-fisheries'
                ? PortalFisheries::listed()->pluck('name')->all()
                : [],
        ]);
    }

    /** `/{język}/{województwo}` — strona regionu „na później" (portal-v3 §5.2); do tego czasu 302. */
    public function region(string $locale): RedirectResponse
    {
        return redirect()->to(PortalRoutes::homeUrl($locale), 302);
    }

    /**
     * Strona łowiska pod adresem kanonicznym — zakładki „Mapa i terminy" i „Szczegóły" (032).
     *
     * ⚠️ Łowisko szukane **wyłącznie po slugu**; segment województwa jest ozdobą sprawdzaną
     * przekierowaniem 301 — korekta województwa łowiska nie psuje żadnego linku (ADR-021).
     */
    public function fishery(Request $request, string $locale, string $state, string $fishery): RedirectResponse|Response
    {
        $record = $this->publishedFishery($fishery);
        $canonical = $record !== null ? PortalRoutes::fisheryUrl($record, $locale) : null;

        if ($record === null || $canonical === null) {
            return self::notFound();
        }

        if ($record->state?->slug !== $state) {
            return redirect()->to($canonical, 301);
        }

        $loaded = PortalFisheryPage::load($record);
        $page = new PortalFisheryPage($loaded);
        // Kalendarz dostaje wyciąg zasad strony — ta sama instancja w kalendarzu i w Cenniku (038).
        $calendar = new PortalCalendar($loaded, $request->query(), $locale, $page->rules());

        // Stopniowe ulepszenie (ADR-022): `portal.js` pobiera TEN SAM adres i podmienia sam fragment
        // kalendarza. Stan i werdykty są identyczne jak w pełnej stronie — to tylko inny kawałek widoku.
        // ⚠️ `Vary: X-Portal-Fragment` na OBU odpowiedziach: ten sam adres niesie dwie różne treści, a bez
        // tego nagłówka przeglądarka przy „wstecz" potrafi podać z cache sam fragment jako całą stronę.
        if ($request->header('X-Portal-Fragment') === 'calendar') {
            return response()
                ->view('portal.partials.calendar', ['calendar' => $calendar, 'fishery' => $loaded])
                ->header('Vary', 'X-Portal-Fragment')
                ->header('Cache-Control', 'no-store');
        }

        return response()
            ->view('portal.fishery', [
                'fishery' => $loaded,
                'page' => $page,
                'calendar' => $calendar,
            ])
            ->header('Vary', 'X-Portal-Fragment');
    }

    /**
     * Krótki adres łowiska wprost pod domeną (`/klasztorne`) i 404 portalu dla każdego innego
     * nieznanego adresu.
     *
     * ⚠️ To jest `Route::fallback()`, NIE trasa `/{slug}` — fallback jest zawsze ostatni, niezależnie
     * od kolejności rejestracji tras pakietów. Trasa `/{slug}` zarejestrowana przed Filamentem
     * przejęłaby `/admin` i zwracała 404, zamiast przepuścić żądanie do panelu (ADR-021).
     */
    public function fallback(Request $request): RedirectResponse|Response
    {
        $path = trim($request->path(), '/');
        $lower = strtolower($path);

        if (! str_contains($path, '/') && preg_match(PortalSlugs::PATTERN, $lower) === 1) {
            if ($path !== $lower) {
                return redirect()->to(url($lower), 301);
            }

            $record = $this->publishedFishery($lower);
            $locale = PortalLocale::resolve($request);
            $canonical = $record !== null ? PortalRoutes::fisheryUrl($record, $locale) : null;

            if ($canonical !== null) {
                return redirect()->to($canonical, 302);
            }
        }

        App::setLocale(PortalLocale::resolve($request));

        // ⚠️ Brakujący plik (adres z rozszerzeniem — obrazek, skrypt, `/storage/*`) albo żądanie nie od
        // przeglądarki dostaje LEKKIE 404, bez listy łowisk: lista to zapytania i warianty zdjęć na każde
        // trafienie bota czy zepsuty adres obrazka (038).
        if (str_contains((string) last(explode('/', $path)), '.') || ! $request->accepts('text/html')) {
            return response(__('Page not found'), 404)->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        return self::notFound();
    }

    public static function notFound(): Response
    {
        return response()->view('portal.not-found', [
            'cards' => PortalFisheries::listed()->map(fn (Fishery $fishery): array => PortalFisheries::card($fishery))->all(),
        ], 404);
    }

    private function publishedFishery(string $slug): ?Fishery
    {
        return Fishery::query()->published()->with('state')->where('slug', $slug)->first();
    }
}
