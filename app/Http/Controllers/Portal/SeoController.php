<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Fishery;
use App\Services\PortalFisheries;
use App\Services\PortalRoutes;
use Illuminate\Http\Response;

/**
 * `robots.txt` i `sitemap.xml` portalu (zadanie 031).
 *
 * ⚠️ **`robots.txt` jest trasą, nie plikiem w `public/`** — tylko tak zamyka indeksowanie poza
 * produkcją (staging, lokalnie) i wskazuje mapę strony pod adresem środowiska. Nie przywracaj
 * `public/robots.txt`: serwer podałby go przed Laravelem, dla każdego środowiska ten sam.
 */
class SeoController extends Controller
{
    public function robots(): Response
    {
        $body = app()->isProduction()
            ? "User-agent: *\nAllow: /\n\nSitemap: ".url('sitemap.xml')."\n"
            : "User-agent: *\nDisallow: /\n";

        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /**
     * Strony główne, strony informacyjne i łowiska opublikowane — w obu językach, z `hreflang`.
     * Generowana przy żądaniu: łowisk jest kilka, bufor byłby tylko kolejnym miejscem rozjazdu.
     */
    public function sitemap(): Response
    {
        $entries = [];

        $entries[] = $this->entry(fn (string $locale): string => PortalRoutes::homeUrl($locale));

        foreach (array_keys(PortalRoutes::PAGES) as $page) {
            $entries[] = $this->entry(fn (string $locale): string => PortalRoutes::pageUrl($page, $locale));
        }

        foreach (PortalFisheries::listed() as $fishery) {
            /** @var Fishery $fishery */
            $entries[] = $this->entry(fn (string $locale): string => (string) PortalRoutes::fisheryUrl($fishery, $locale));
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .view('portal.sitemap', ['entries' => array_merge(...$entries)])->render();

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /**
     * Jedna strona = po jednym wpisie na język, każdy z kompletem wersji językowych.
     *
     * @param  callable(string): string  $url
     * @return array<int, array{loc: string, alternates: array<string, string>}>
     */
    private function entry(callable $url): array
    {
        $alternates = [];

        foreach (PortalRoutes::LOCALES as $locale) {
            $alternates[$locale] = $url($locale);
        }

        return array_map(fn (string $loc): array => ['loc' => $loc, 'alternates' => $alternates], array_values($alternates));
    }
}
