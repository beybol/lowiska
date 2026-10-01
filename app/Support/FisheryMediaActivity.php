<?php

namespace App\Support;

use App\Models\Fishery;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Wpisy dziennika zmian łowiska o dodaniu i usunięciu zdjęcia (zadanie 036).
 *
 * ⚠️ Zdjęcia mieszkają w tabeli `media`, nie w kolumnach `Fishery`, więc `LogsActivity` łowiska ich nie widzi.
 * Wpis idzie jawnie na łowisko, w formacie `old`/`attributes` jak wpisy automatyczne (`dziennik-zmian.md` §5).
 * Zmiana kolejności się nie loguje — nie zmienia treści, tylko układ.
 */
final class FisheryMediaActivity
{
    public static function added(Media $media): void
    {
        self::log($media, null, $media->file_name);
    }

    public static function removed(Media $media): void
    {
        self::log($media, $media->file_name, null);
    }

    private static function log(Media $media, ?string $old, ?string $new): void
    {
        $fishery = $media->model;

        if (! $fishery instanceof Fishery) {
            return;
        }

        activity()
            ->performedOn($fishery)
            ->event('updated')
            ->withChanges([
                'old' => [$media->collection_name => $old],
                'attributes' => [$media->collection_name => $new],
            ])
            ->log('updated');
    }
}
