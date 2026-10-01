<?php

namespace App\Support;

use App\Services\FisheryImages;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Warstwa plików medialibrary, która OCZYSZCZA zdjęcie, zanim trafi do bucketu (zadanie 036, ADR-023).
 *
 * ⚠️ Podpięta w kontenerze (`AppServiceProvider`) — dzięki temu obejmuje KAŻDĄ drogę dodania pliku
 * (formularz Filamenta, `addMedia…()` w kodzie, testy), a nie tylko jeden formularz. Bucket jest publiczny
 * (UBLA), więc oryginał z GPS w EXIF byłby dostępny pod odgadywalnym adresem obok swoich wariantów.
 *
 * Tu, a nie w zdarzeniu `MediaHasBeenAddedEvent`: plik jest jeszcze lokalny, więc nie trzeba go pobierać
 * z bucketu i wysyłać drugi raz. Konwersje powstają zaraz potem (`parent::add()`), już z oczyszczonego pliku
 * i ze znanymi wymiarami — od nich zależy, które rozmiary się generuje (`FisheryImages::sizesFor()`).
 */
class SanitizingMediaFilesystem extends Filesystem
{
    public function add(string $file, Media $media, ?string $targetFileName = null): bool
    {
        $mimeType = (string) mime_content_type($file);

        if (in_array($mimeType, FisheryImages::ACCEPTED_MIME_TYPES, true)) {
            $dimensions = FisheryImages::sanitizeOriginal($file, $mimeType);

            clearstatcache(true, $file);

            $media->setCustomProperty('width', $dimensions['width']);
            $media->setCustomProperty('height', $dimensions['height']);
            $media->size = (int) filesize($file);
            $media->save();
        }

        return parent::add($file, $media, $targetFileName);
    }
}
