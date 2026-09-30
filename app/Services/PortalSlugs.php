<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Slugi adresów portalu: łowiska i województwa (zadanie 030, portal-v3 §6.1, ADR-021).
 *
 * ⚠️ **Slug jest STAŁY.** Powstaje raz, przy utworzeniu rekordu, i zmiana nazwy go nie rusza —
 * adres strony łowiska trafia do wyszukiwarek i na banery. Zmianę robi wyłącznie admin, ręcznie;
 * historii slugów nie prowadzimy (027 pkt 9.3).
 *
 * ⚠️ **Unikalność sprawdza surowa tabela, nie model**, więc łowiska usunięte miękko też zajmują
 * slug — indeks unikalny ich nie pomija, a zapytanie przez `Fishery` z `SoftDeletes` by je
 * przeoczyło. Z tego samego powodu klasa działa w migracji, zanim istnieją kolumny modelu.
 *
 * ⚠️ **Slug łowiska jest też KRÓTKIM ADRESEM wprost pod domeną** (`fisherya.com/klasztorne`,
 * ADR-021 opcja B), więc dzieli przestrzeń adresów pierwszego poziomu z trasami aplikacji. Kolizję
 * wykluczają warstwowo: kształt (bez kropki — pliki w `public/`; minimum 3 znaki — kody języków),
 * lista zastrzeżona (`RESERVED`) i pierwsze segmenty zarejestrowanych tras. Czwarta warstwa —
 * `portal:check-slugs` przy wdrożeniu — pilnuje kierunku „nowa trasa kontra wydrukowany slug".
 */
final class PortalSlugs
{
    /** Format sluga: małe litery ASCII i cyfry, pojedyncze myślniki w środku — BEZ KROPKI. */
    public const PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public const MAX_LENGTH = 120;

    /** Krótszy slug zderzyłby się z prefiksem języka (`pl`, `en`, każdy kod ISO 639-1). */
    public const MIN_FISHERY_LENGTH = 3;

    /**
     * Nazwy pierwszego poziomu, których slug łowiska NIGDY nie zajmie — także te, których dziś nie ma
     * w routerze: katalogi `public/` (serwuje je Caddy PRZED Laravelem), panele, trasy systemowe
     * i uwierzytelniania oraz rezerwa na przyszłość.
     *
     * ⚠️ Nowa trasa pierwszego poziomu → wpis tutaj (`strona-publiczna.md`).
     *
     * @var list<string>
     */
    public const RESERVED = [
        // katalogi public/
        'build', 'css', 'js', 'fonts', 'images', 'img', 'storage', 'vendor',
        // panele
        'admin', 'owner',
        // trasy systemowe i uwierzytelniania
        'api', 'login', 'logout', 'register', 'verify', 'verify-email', 'password', 'forgot-password',
        'reset-password', 'confirm-password', 'email', 'profile', 'auth', 'dashboard', 'up',
        'livewire', 'filament', 'sanctum',
        // rezerwa
        'app', 'www', 'mail', 'static', 'assets', 'portal', 'panel', 'help', 'status',
    ];

    /** Slug zastępczy dla nazwy, z której nie zostaje ani jeden znak. */
    private const FALLBACK = 'lowisko';

    /** @var array<string, true>|null */
    private static ?array $routeSegments = null;

    public static function forFishery(string $name, ?int $ignoreId = null): string
    {
        return self::unique(
            self::base($name),
            fn (string $candidate): bool => self::collidesWithApplication($candidate)
                || self::fisherySlugTaken($candidate, $ignoreId),
        );
    }

    /**
     * ⚠️ Nazwy województw są w bazie **kluczami tłumaczeń po angielsku** („Greater Poland"),
     * a slug ma być polski w obu językach portalu („wielkopolskie") — to nazwa własna.
     * Dlatego slug liczy się z tłumaczenia polskiego, a bez niego z samej nazwy.
     *
     * Województwo nie jest krótkim adresem (żyje pod prefiksem języka), więc nie przechodzi przez
     * `collidesWithApplication()`.
     */
    public static function forState(string $name, int $countryId, ?int $ignoreId = null): string
    {
        return self::unique(
            self::base(__($name, [], 'pl')),
            fn (string $candidate): bool => DB::table('states')
                ->where('country_id', $countryId)
                ->where('slug', $candidate)
                ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists(),
        );
    }

    /**
     * Czy slug łowiska zajęłby adres należący do aplikacji — JEDNA reguła, wołana przy nadawaniu,
     * przy ręcznej zmianie przez admina i przez `portal:check-slugs`.
     */
    public static function collidesWithApplication(string $slug): bool
    {
        return preg_match(self::PATTERN, $slug) !== 1
            || strlen($slug) < self::MIN_FISHERY_LENGTH
            || in_array($slug, self::RESERVED, true)
            || isset(self::routeSegments()[$slug]);
    }

    /**
     * Pierwsze segmenty wszystkich zarejestrowanych tras — z routera, nie z `routes/web.php`:
     * część tras rejestrują pakiety (Filament, Livewire z prefiksem z haszem, Breeze).
     *
     * @return array<string, true>
     */
    private static function routeSegments(): array
    {
        if (self::$routeSegments !== null) {
            return self::$routeSegments;
        }

        $segments = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->isFallback) {
                continue;
            }

            $first = explode('/', trim($route->uri(), '/'))[0];

            // Parametr na pierwszym miejscu (`{locale}`) nie zajmuje żadnej konkretnej nazwy —
            // jego zakres pilnuje minimum długości sluga.
            if ($first !== '' && ! str_starts_with($first, '{')) {
                $segments[$first] = true;
            }
        }

        return self::$routeSegments = $segments;
    }

    private static function fisherySlugTaken(string $candidate, ?int $ignoreId): bool
    {
        return DB::table('fisheries')
            ->where('slug', $candidate)
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists();
    }

    private static function base(string $name): string
    {
        $slug = Str::slug($name, '-', 'pl');

        // Miejsce na sufiks kolizji zostaje w limicie kolumny.
        $slug = rtrim(Str::limit($slug, self::MAX_LENGTH - 10, ''), '-');

        return $slug === '' ? self::FALLBACK : $slug;
    }

    /**
     * @param  callable(string): bool  $taken
     */
    private static function unique(string $base, callable $taken): string
    {
        $candidate = $base;

        for ($suffix = 2; $taken($candidate); $suffix++) {
            $candidate = "{$base}-{$suffix}";
        }

        return $candidate;
    }
}
