<?php

namespace Tests\Feature;

use App\Models\Fishery;
use App\Services\FisheryImages;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\StayFixtures;

/**
 * Przypadki brzegowe oczyszczania zdjęć przy zapisie (zadanie 036, ADR-023) wskazane przez testy mutacyjne
 * (02.10.2026). Obrót i usuwanie metadanych sprawdza `FisheryGalleryTest`.
 *
 * ⚠️ Sprzątania pliku tymczasowego w `addRemote()` celowo NIE sprawdzamy przez porównanie katalogu tymczasowego
 * przed i po: katalog jest wspólny dla procesów równoległych (testy mutacyjne, `--parallel`), więc cudzy plik
 * tymczasowy wywracał test i zawyżał wynik mutacji.
 */
beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');
});

function sanitizingFishery(): Fishery
{
    [$fishery] = StayFixtures::fisheryWithPosition();

    return $fishery;
}

test('the stored size is the size of the sanitised original, not of the upload', function () {
    $upload = UploadedFile::fake()->image('photo.jpg', 3000, 2000);
    $uploadSize = $upload->getSize();

    $media = sanitizingFishery()->addMedia($upload)->toMediaCollection(FisheryImages::GALLERY)->fresh();
    $storedSize = Storage::disk(FisheryImages::originalsDisk())->size($media->getPathRelativeToRoot());

    expect($media->size)->toBe($storedSize)
        ->and($storedSize)->not->toBe($uploadSize);
});

test('a photo from another disk keeps the file name it was given', function () {
    Storage::disk('local')->putFileAs('incoming', UploadedFile::fake()->image('photo.jpg', 1200, 800), 'photo.jpg');

    $media = sanitizingFishery()->addMediaFromDisk('incoming/photo.jpg', 'local')
        ->usingFileName('brzeg-jeziora.jpg')
        ->toMediaCollection(FisheryImages::GALLERY);

    expect($media->file_name)->toBe('brzeg-jeziora.jpg')
        ->and(Storage::disk(FisheryImages::originalsDisk())->exists($media->getPathRelativeToRoot()))->toBeTrue();
});
