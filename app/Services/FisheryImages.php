<?php

namespace App\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Jcupitt\Vips\Image as VipsImage;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Zdjęcia łowiska — JEDYNE miejsce, które zna rozmiary wariantów i składa ich adresy (zadanie 036, ADR-023).
 *
 * ⚠️ **Oryginał nigdy nie trafia do HTML-a portalu.** Bucket jest publiczny (UBLA), więc oryginał jest
 * oczyszczany przy dodaniu (`sanitizeOriginal()`: obrót według EXIF, najwyżej 2560 px, bez metadanych, w tym
 * GPS), a portal dostaje wyłącznie warianty WebP. Brak wariantu → `null`, nigdy adres oryginału.
 *
 * ⚠️ **Bez powiększania.** Wariant powstaje tylko dla rozmiaru mniejszego niż dłuższy bok zdjęcia, plus jeden
 * „największy” w rozdzielczości zdjęcia (najwyżej 1920 px). Żądanie większego rozmiaru dostaje największy
 * dostępny, a `srcset` wymienia wyłącznie PRAWDZIWE szerokości plików.
 *
 * ⚠️ **Brakujący wariant powstaje przy wyświetleniu**, synchronicznie w żądaniu, pod blokadą — kolejka na
 * Cloud Run to `sync` (ADR-004), a praca „po odpowiedzi” jest tam niewiarygodna. Ścieżką podstawową jest
 * generowanie przy zapisie (konwersje `nonQueued()` modelu); to tutaj jest stanem awaryjnym.
 */
final class FisheryImages
{
    public const GALLERY = 'gallery';

    public const MAP = 'map';

    /** Najdłuższy bok oczyszczonego oryginału — tyle samo, ile skaluje przeglądarka przed wysłaniem. */
    public const ORIGINAL_MAX = 2560;

    /** Docelowe rozmiary wariantów (dłuższy bok, px). Ostatni jest „największym”. */
    public const SIZES = [480, 960, 1920];

    /** @var list<string> */
    public const ACCEPTED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /** Ile czeka drugie żądanie, zanim pokaże to, co już jest (s). */
    private const LOCK_WAIT = 20;

    /**
     * Nazwa konwersji dla rozmiaru: `w960`.
     */
    public static function conversionName(int $size): string
    {
        return 'w'.$size;
    }

    /**
     * Rozmiary, które warto wygenerować dla tego zdjęcia: każdy mniejszy niż dłuższy bok i pierwszy, który go
     * dosięga (ten wyjdzie w rozdzielczości zdjęcia, bo `Fit::Max` nie powiększa). Bez wymiarów — wszystkie.
     *
     * @return list<int>
     */
    public static function sizesFor(?Media $media): array
    {
        $longer = $media !== null ? self::longerSide($media) : null;

        if ($longer === null) {
            return self::SIZES;
        }

        $sizes = [];

        foreach (self::SIZES as $size) {
            $sizes[] = $size;

            if ($size >= $longer) {
                break;
            }
        }

        return $sizes;
    }

    /**
     * Oczyszcza plik lokalny W MIEJSCU: obrót według EXIF, najwyżej `ORIGINAL_MAX` po dłuższym boku,
     * zapis w tym samym formacie BEZ metadanych. Zwraca wymiary po oczyszczeniu.
     *
     * ⚠️ `strip` jest tu konieczne: sterownik Vips z `spatie/image` obraca przy wczytaniu, ale przy zapisie
     * zachowuje EXIF — razem z położeniem GPS. Warianty powstają z oczyszczonego oryginału, więc też go nie mają.
     *
     * @return array{width: int, height: int}
     */
    public static function sanitizeOriginal(string $path, string $mimeType): array
    {
        // `thumbnail` obraca według EXIF i zmniejsza już przy dekodowaniu (szybko, mało pamięci); `down` nie powiększa.
        $image = VipsImage::thumbnail($path, self::ORIGINAL_MAX, [
            'height' => self::ORIGINAL_MAX,
            'size' => 'down',
        ]);

        [$suffix, $options] = match ($mimeType) {
            'image/png' => ['.png', []],
            'image/webp' => ['.webp', ['Q' => 90]],
            default => ['.jpg', ['Q' => 90]],
        };

        // Do bufora, a dopiero potem na dysk: libvips czyta źródło strumieniowo, więc zapis w ten sam plik
        // w trakcie dekodowania by go uszkodził.
        $buffer = $image->writeToBuffer($suffix, $options + ['strip' => true]);
        file_put_contents($path, $buffer);

        return ['width' => (int) $image->width, 'height' => (int) $image->height];
    }

    /**
     * Warianty zdjęcia, które ISTNIEJĄ, od najmniejszego — z prawdziwymi wymiarami. Brakujące dogenerowuje
     * pod blokadą.
     *
     * @return list<array{url: string, width: int, height: int}>
     */
    public static function variants(Media $media): array
    {
        self::ensureVariants($media);

        $variants = [];

        foreach (self::sizesFor($media) as $size) {
            $name = self::conversionName($size);

            if (! $media->hasGeneratedConversion($name)) {
                continue;
            }

            [$width, $height] = self::dimensionsAt($media, $size);
            $variants[] = ['url' => $media->getUrl($name), 'width' => $width, 'height' => $height];
        }

        return $variants;
    }

    /**
     * Adres wariantu dla szerokości wyświetlania: najmniejszy, który ją pokrywa, a gdy żaden — największy.
     */
    public static function url(Media $media, int $width): ?string
    {
        $variants = self::variants($media);

        foreach ($variants as $variant) {
            if ($variant['width'] >= $width) {
                return $variant['url'];
            }
        }

        return $variants === [] ? null : $variants[array_key_last($variants)]['url'];
    }

    /** `srcset` z prawdziwymi szerokościami — pusty łańcuch, gdy nie ma żadnego wariantu. */
    public static function srcset(Media $media): string
    {
        return implode(', ', array_map(
            static fn (array $variant): string => $variant['url'].' '.$variant['width'].'w',
            self::variants($media),
        ));
    }

    /**
     * Największy wariant — dla podglądu pełnoekranowego, który potrzebuje wymiarów przed pobraniem pliku.
     *
     * @return array{url: string, width: int, height: int}|null
     */
    public static function largest(Media $media): ?array
    {
        $variants = self::variants($media);

        return $variants === [] ? null : $variants[array_key_last($variants)];
    }

    /**
     * Dogenerowuje brakujące warianty w tym żądaniu. ⚠️ Blokada na zdjęcie: drugie żądanie czeka na pierwsze
     * zamiast liczyć to samo; po przekroczeniu czasu pokazuje to, co już jest.
     */
    private static function ensureVariants(Media $media): void
    {
        if (self::missing($media) === []) {
            return;
        }

        try {
            Cache::lock('media-variants:'.$media->getKey(), 120)->block(self::LOCK_WAIT, function () use ($media): void {
                // Inne żądanie mogło je wygenerować, gdy czekaliśmy na blokadę.
                $media->refresh();
                $missing = self::missing($media);

                if ($missing !== []) {
                    app(FileManipulator::class)->createDerivedFiles($media, $missing, onlyMissing: true);
                    $media->refresh();
                }
            });
        } catch (LockTimeoutException) {
            // Pokazujemy to, co już istnieje — oryginału nie podstawiamy.
        }
    }

    /** @return list<string> */
    private static function missing(Media $media): array
    {
        return array_values(array_filter(
            array_map(self::conversionName(...), self::sizesFor($media)),
            static fn (string $name): bool => ! $media->hasGeneratedConversion($name),
        ));
    }

    /**
     * Wymiary wariantu o danym rozmiarze — z wymiarów oczyszczonego oryginału, bez powiększania.
     *
     * @return array{0: int, 1: int}
     */
    private static function dimensionsAt(Media $media, int $size): array
    {
        $width = (int) $media->getCustomProperty('width', 0);
        $height = (int) $media->getCustomProperty('height', 0);
        $longer = max($width, $height);

        if ($longer === 0) {
            return [$size, $size];
        }

        $scale = min(1, $size / $longer);

        return [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))];
    }

    private static function longerSide(Media $media): ?int
    {
        $longer = max((int) $media->getCustomProperty('width', 0), (int) $media->getCustomProperty('height', 0));

        return $longer > 0 ? $longer : null;
    }
}
