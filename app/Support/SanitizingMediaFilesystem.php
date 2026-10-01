<?php

namespace App\Support;

use App\Services\FisheryImages;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\RemoteFile;

/**
 * Warstwa plików medialibrary, która OCZYSZCZA zdjęcie, zanim trafi do bucketu (zadanie 036, ADR-023).
 *
 * ⚠️ Podpięta w kontenerze (`AppServiceProvider`) — obejmuje obie drogi, którymi biblioteka zapisuje plik:
 * lokalną (`add()`: formularz Filamenta, `addMedia…()` w kodzie, testy) i z innego dysku (`addRemote()`:
 * `addMediaFromDisk()`). Oryginał leży na dysku prywatnym, ale warianty — na publicznym; powstają z oryginału,
 * więc bez oczyszczania niosłyby jego metadane (GPS, model urządzenia). Oczyszczony oryginał to też ochrona na
 * wypadek, gdyby dysk oryginałów kiedyś wskazał bucket publiczny (ADR-023, aktualizacja z 01.10.2026).
 *
 * Tu, a nie w zdarzeniu `MediaHasBeenAddedEvent`: plik jest jeszcze lokalny (albo zostaje pobrany do pliku
 * tymczasowego), więc nie trzeba go wysyłać do bucketu dwa razy. Konwersje powstają zaraz potem
 * (`parent::add()`), już z oczyszczonego pliku i ze znanymi wymiarami — od nich zależy, które rozmiary się
 * generuje (`FisheryImages::sizesFor()`).
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

    /**
     * Plik z innego dysku przechodzi przez plik tymczasowy i `add()` — inaczej `parent::addRemote()` skopiowałby
     * go do biblioteki bez oczyszczania (z EXIF i GPS). Usunięcie oryginału ze źródła (gdy nie ma
     * `preservingOriginal()`) zostaje po stronie biblioteki, jak dotąd.
     */
    public function addRemote(RemoteFile $file, Media $media, ?string $targetFileName = null): bool
    {
        $source = Storage::disk($file->getDisk())->readStream($file->getKey());

        if (! is_resource($source)) {
            throw new RuntimeException("Can not read [{$file->getKey()}] from disk [{$file->getDisk()}].");
        }

        $temporary = (string) tempnam(sys_get_temp_dir(), 'media-remote');
        $target = fopen($temporary, 'wb');

        try {
            if ($target === false) {
                throw new RuntimeException("Can not write the temporary file [{$temporary}].");
            }

            stream_copy_to_stream($source, $target);
            fclose($target);

            return $this->add($temporary, $media, $targetFileName ?? $file->getFilename());
        } finally {
            fclose($source);

            if (file_exists($temporary)) {
                unlink($temporary);
            }
        }
    }
}
