<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Slugi adresów portalu: łowiska i województwa (zadanie 030, portal-v3 §6.1).
 *
 * ⚠️ **Slug jest STAŁY.** Powstaje raz, przy utworzeniu rekordu, i zmiana nazwy go nie rusza —
 * adres strony łowiska trafia do wyszukiwarek i na banery. Zmianę robi wyłącznie admin, ręcznie;
 * historii slugów nie prowadzimy (027 pkt 9.3).
 *
 * ⚠️ **Unikalność sprawdza surowa tabela, nie model**, więc łowiska usunięte miękko też zajmują
 * slug — indeks unikalny ich nie pomija, a zapytanie przez `Fishery` z `SoftDeletes` by je
 * przeoczyło. Z tego samego powodu klasa działa w migracji, zanim istnieją kolumny modelu.
 */
final class PortalSlugs
{
    /** Format sluga: małe litery ASCII i cyfry, pojedyncze myślniki w środku. */
    public const PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public const MAX_LENGTH = 120;

    /** Slug zastępczy dla nazwy, z której nie zostaje ani jeden znak. */
    private const FALLBACK = 'lowisko';

    public static function forFishery(string $name, ?int $ignoreId = null): string
    {
        return self::unique(
            self::base($name),
            fn (string $candidate): bool => DB::table('fisheries')
                ->where('slug', $candidate)
                ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists(),
        );
    }

    /**
     * ⚠️ Nazwy województw są w bazie **kluczami tłumaczeń po angielsku** („Greater Poland"),
     * a slug ma być polski w obu językach portalu („wielkopolskie") — to nazwa własna.
     * Dlatego slug liczy się z tłumaczenia polskiego, a bez niego z samej nazwy.
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
