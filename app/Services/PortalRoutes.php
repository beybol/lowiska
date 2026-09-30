<?php

namespace App\Services;

use App\Models\Fishery;
use Illuminate\Routing\Route;

/**
 * Adresy portalu wędkarza — JEDYNE miejsce ze slugami stron (zadanie 031, ADR-021).
 *
 * ⚠️ **Strony informacyjne mają inny adres w każdym języku** (treści pisze się osobno, nie tłumaczy),
 * więc trasa strony istnieje raz na język: `portal.page.{strona}.{język}`. Przełącznik języka szuka
 * odpowiednika po nazwie strony, nie po adresie.
 *
 * ⚠️ Każda strona z treścią ma prefiks języka. Poza nim żyją wyłącznie wejścia przekierowujące
 * (`/`, krótki adres łowiska) i trasy techniczne — patrz `PortalSlugs::RESERVED`.
 */
final class PortalRoutes
{
    public const LOCALES = ['pl', 'en'];

    public const DEFAULT_LOCALE = 'pl';

    /**
     * Strona → slug w każdym języku. Kolejność = kolejność w mapie strony.
     *
     * @var array<string, array{pl: string, en: string}>
     */
    public const PAGES = [
        'for-fisheries' => ['pl' => 'dla-lowisk', 'en' => 'for-fisheries'],
        'how-it-works' => ['pl' => 'jak-to-dziala', 'en' => 'how-it-works'],
        'listing-rules' => ['pl' => 'jak-ukladamy-liste', 'en' => 'listing-rules'],
        'contact' => ['pl' => 'kontakt', 'en' => 'contact'],
        'terms' => ['pl' => 'dokumenty-prawne/regulamin', 'en' => 'legal/terms'],
        'privacy' => ['pl' => 'dokumenty-prawne/polityka-prywatnosci', 'en' => 'legal/privacy'],
        'cookies' => ['pl' => 'dokumenty-prawne/pliki-cookie', 'en' => 'legal/cookies'],
        'report-content' => ['pl' => 'dokumenty-prawne/zglos-nielegalna-tresc', 'en' => 'legal/report-content'],
    ];

    public static function pageRouteName(string $page, string $locale): string
    {
        return "portal.page.{$page}.{$locale}";
    }

    public static function pageUrl(string $page, ?string $locale = null): string
    {
        return route(self::pageRouteName($page, $locale ?? app()->getLocale()));
    }

    public static function homeUrl(?string $locale = null): string
    {
        return route('portal.home', ['locale' => $locale ?? app()->getLocale()]);
    }

    /**
     * Adres kanoniczny strony łowiska — `null`, gdy łowisko nie ma województwa (bez niego nie ma
     * adresu; publikacja jest wtedy zablokowana, ale województwo mogło zniknąć ze słownika).
     */
    public static function fisheryUrl(Fishery $fishery, ?string $locale = null): ?string
    {
        $stateSlug = $fishery->state?->slug;

        if ($stateSlug === null) {
            return null;
        }

        return route('portal.fishery', [
            'locale' => $locale ?? app()->getLocale(),
            'state' => $stateSlug,
            'fishery' => $fishery->slug,
        ]);
    }

    /**
     * Odpowiednik bieżącej strony w każdym języku — do `hreflang` i przełącznika języka.
     * Strona spoza portalu (404, fallback) dostaje strony główne.
     *
     * @return array<string, string> język → adres
     */
    public static function alternates(?Route $route): array
    {
        $alternates = [];

        foreach (self::LOCALES as $locale) {
            $alternates[$locale] = self::counterpart($route, $locale);
        }

        return $alternates;
    }

    private static function counterpart(?Route $route, string $locale): string
    {
        $name = $route?->getName();
        $page = $route?->defaults['page'] ?? null;

        if (is_string($page) && $name === self::pageRouteName($page, (string) ($route->defaults['locale'] ?? ''))) {
            return self::pageUrl($page, $locale);
        }

        if ($name === 'portal.fishery') {
            return route('portal.fishery', [
                'locale' => $locale,
                'state' => $route->parameter('state'),
                'fishery' => $route->parameter('fishery'),
            ]);
        }

        return self::homeUrl($locale);
    }
}
