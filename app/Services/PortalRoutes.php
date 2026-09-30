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

    /**
     * Zakładki strony łowiska → kotwica w każdym języku (zadanie 032). Obie zakładki są w jednym
     * dokumencie; kotwica pierwszej nie trafia do adresu (adres łowiska zostaje kanoniczny).
     * „Cennik" i „Dokumenty" dochodzą tutaj w 034.
     *
     * @var array<string, array{pl: string, en: string}>
     */
    public const FISHERY_TABS = [
        'map' => ['pl' => 'mapa-i-terminy', 'en' => 'map-and-dates'],
        'details' => ['pl' => 'szczegoly', 'en' => 'details'],
    ];

    /**
     * Parametry adresu kalendarza strony łowiska → nazwa w każdym języku (zadanie 033, Rozstrzygnięcie 1).
     * Canonical ich nie zawiera; przełącznik języka przenosi stan pod nazwami drugiego języka.
     *
     * @var array<string, array{pl: string, en: string}>
     */
    public const CALENDAR_PARAMS = [
        'week' => ['pl' => 'tydzien', 'en' => 'week'],
        'anglers' => ['pl' => 'lowiacych', 'en' => 'anglers'],
        'group' => ['pl' => 'grupa', 'en' => 'group'],
        'features' => ['pl' => 'cecha', 'en' => 'feature'],
        'day' => ['pl' => 'doba', 'en' => 'night'],
        'position' => ['pl' => 'st', 'en' => 'pos'],
    ];

    /** Kotwica sekcji kalendarza — powrót na kalendarz po przeładowaniu bez skryptu. */
    public const CALENDAR_ANCHOR = ['pl' => 'kalendarz', 'en' => 'calendar'];

    public static function calendarParam(string $key, ?string $locale = null): string
    {
        return self::CALENDAR_PARAMS[$key][$locale ?? app()->getLocale()];
    }

    /**
     * Stan kalendarza z zapytania w danym języku → klucze techniczne (`week`, `anglers`, …).
     *
     * @param  array<string, mixed>  $query
     * @return array<string, string>
     */
    public static function calendarStateFrom(array $query, string $locale): array
    {
        $state = [];

        foreach (self::CALENDAR_PARAMS as $key => $names) {
            $value = $query[$names[$locale]] ?? null;

            if (is_string($value) && $value !== '') {
                $state[$key] = $value;
            }
        }

        return $state;
    }

    /**
     * Stan kalendarza → zapytanie w danym języku.
     *
     * @param  array<string, string>  $state
     * @return array<string, string>
     */
    public static function calendarQueryFor(array $state, string $locale): array
    {
        $query = [];

        foreach (self::CALENDAR_PARAMS as $key => $names) {
            if (isset($state[$key]) && $state[$key] !== '') {
                $query[$names[$locale]] = $state[$key];
            }
        }

        return $query;
    }

    /**
     * Adresy przełącznika języka — odpowiednik strony RAZEM z jej stanem kalendarza pod nazwami
     * parametrów drugiego języka. ⚠️ Canonical i `hreflang` biorą `alternates()` — bez parametrów.
     *
     * @return array<string, string>
     */
    public static function switchUrls(?Route $route, array $query): array
    {
        $alternates = self::alternates($route);

        if ($route?->getName() !== 'portal.fishery') {
            return $alternates;
        }

        $state = self::calendarStateFrom($query, (string) $route->parameter('locale'));

        foreach ($alternates as $locale => $url) {
            $translated = self::calendarQueryFor($state, $locale);

            if ($translated !== []) {
                $alternates[$locale] = $url.'?'.http_build_query($translated);
            }
        }

        return $alternates;
    }

    public static function fisheryTabAnchor(string $tab, ?string $locale = null): string
    {
        return self::FISHERY_TABS[$tab][$locale ?? app()->getLocale()];
    }

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
