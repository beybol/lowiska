<?php

namespace Tests\Feature;

use App\Filament\Resources\FisheryResource\Pages\EditFishery;
use App\Models\Company;
use App\Models\Fishery;
use App\Models\State;
use App\Services\FisheryImages;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Jcupitt\Vips\Image as VipsImage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Support\StayFixtures;

/**
 * Galeria i mapa łowiska w medialibrary, warianty rozmiarów i ich pokazywanie w portalu (zadanie 036, ADR-023).
 *
 * ⚠️ Zdjęcia są PRAWDZIWYMI plikami, skalowanymi przez libvips — na udawanych dyskach (`Storage::fake`):
 * oryginały na prywatnym `local`, warianty na publicznym `public` (ADR-023, aktualizacja z 01.10.2026).
 */
beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');
});

/** Łowisko opublikowane pod `/pl/wielkopolskie/klasztorne`. */
function galleryFishery(): Fishery
{
    [$fishery] = StayFixtures::fisheryWithPosition([
        'name' => 'Klasztorne',
        'state_id' => State::factory()->create(['name' => 'Greater Poland'])->id,
        'company_id' => Company::factory()->create()->id,
        'published_at' => now(),
    ]);

    return $fishery->fresh();
}

function addPhoto(Fishery $fishery, int $width = 1200, int $height = 800, string $collection = FisheryImages::GALLERY): Media
{
    $file = UploadedFile::fake()->image('photo.jpg', $width, $height);

    return $fishery->addMedia($file)->toMediaCollection($collection);
}

function galleryPage(string $path = '/pl/wielkopolskie/klasztorne'): string
{
    return test()->get($path)->assertOk()->getContent();
}

test('a small photo is not upscaled: only real sizes, the largest in its own resolution', function () {
    $media = addPhoto(galleryFishery(), 800, 600);

    expect(array_keys(array_filter($media->generated_conversions)))->toBe(['w480', 'w960'])
        ->and(FisheryImages::srcset($media))->toEndWith('w480.webp 480w, '.$media->getUrl('w960').' 800w')
        ->and(FisheryImages::largest($media))->toMatchArray(['width' => 800, 'height' => 600])
        // Żądanie 1920 dostaje największy dostępny wariant.
        ->and(FisheryImages::url($media, 1920))->toBe($media->getUrl('w960'));
});

test('a large photo gets all sizes and its original is capped at 2560 px', function () {
    $media = addPhoto(galleryFishery(), 3000, 2000);

    expect($media->getCustomProperty('width'))->toBe(2560)
        ->and($media->getCustomProperty('height'))->toBe(1707)
        ->and(array_keys(array_filter($media->generated_conversions)))->toBe(['w480', 'w960', 'w1920'])
        ->and(FisheryImages::largest($media))->toMatchArray(['width' => 1920, 'height' => 1280]);

    [$width] = getimagesizefromstring(Storage::disk('public')->get($media->getPathRelativeToRoot('w1920')));
    expect($width)->toBe(1920);
});

test('the original is rotated by EXIF and stored without any metadata', function () {
    // JPEG 300×100 z orientacją „obróć o 90°" w EXIF — tak zapisuje zdjęcia pionowe telefon. Plik na udawanym
    // dysku, nie w /tmp: `addMedia()` go przenosi, a z `tempnam()` zostawałby pusty plik po każdym przebiegu.
    Storage::fake('local');
    $path = Storage::disk('local')->path('exif.jpg');
    $image = VipsImage::black(300, 100)->linear([1], [128])->copy();
    $image->set('orientation', 6);
    $image->writeToFile($path);
    expect(exif_read_data($path)['Orientation'] ?? null)->toBe(6);

    $media = galleryFishery()->addMedia($path)->toMediaCollection(FisheryImages::GALLERY);
    $stored = Storage::disk(FisheryImages::originalsDisk())->path($media->getPathRelativeToRoot());

    expect([$media->getCustomProperty('width'), $media->getCustomProperty('height')])->toBe([100, 300])
        // Bez jednej sekcji metadanych (IFD0, EXIF, GPS…) — `exif` zwraca wtedy same dane pliku.
        ->and(exif_read_data($stored)['SectionsFound'] ?? null)->toBe('');
});

test('the portal never puts the original in the HTML, only variants', function () {
    $fishery = galleryFishery();
    $photo = addPhoto($fishery);
    $map = addPhoto($fishery, collection: FisheryImages::MAP);

    $html = galleryPage();

    expect($html)
        ->toContain($photo->getUrl('w960'))
        ->toContain($map->getUrl('w960'))
        ->not->toContain('"'.$photo->getUrl().'"')
        ->not->toContain('"'.$map->getUrl().'"');
});

test('a missing variant is generated when the photo is displayed', function () {
    $fishery = galleryFishery();
    $media = addPhoto($fishery);
    Storage::disk('public')->delete($media->getPathRelativeToRoot('w960'));
    $media->markAsConversionNotGenerated('w960');

    galleryPage();

    $media->refresh();
    expect($media->hasGeneratedConversion('w960'))->toBeTrue();
    Storage::disk('public')->assertExists($media->getPathRelativeToRoot('w960'));
});

test('the header layout follows the number of photos', function (int $photos, ?string $grid, bool $label) {
    $fishery = galleryFishery();

    for ($i = 0; $i < $photos; $i++) {
        addPhoto($fishery);
    }

    $html = galleryPage();

    if ($grid === null) {
        expect($html)->not->toContain('data-pswp-width');

        return;
    }

    expect($html)->toContain($grid);
    $label
        ? expect($html)->toContain('Wszystkie zdjęcia · '.$photos)
        : expect($html)->not->toContain('Wszystkie zdjęcia');
})->with([
    'no photo — no header' => [0, null, false],
    'one — full width' => [1, 'grid-cols-1 gap-1.5', false],
    'two — halves' => [2, 'grid-cols-2 gap-1.5 p-1.5', false],
    'three — one large and two small' => [3, 'grid-cols-[2fr_1fr_1fr]', true],
    'more — the count of all' => [5, 'grid-cols-[2fr_1fr_1fr]', true],
]);

test('every photo is in the preview with its size and a generated alt text', function () {
    $fishery = galleryFishery();
    addPhoto($fishery, 1200, 800);
    addPhoto($fishery, 1200, 800);

    expect(galleryPage())
        ->toContain('data-pswp-width="1200" data-pswp-height="800"')
        ->toContain('alt="Klasztorne — zdjęcie 1"')
        ->toContain('alt="Klasztorne — zdjęcie 2"')
        ->toContain('+') // kafelki na telefonie
        ->toContain('data-gallery');
});

test('the order from the panel decides the cover on the home page card', function () {
    $fishery = galleryFishery();
    $first = addPhoto($fishery);
    $second = addPhoto($fishery);

    expect(galleryPage('/pl'))->toContain($first->getUrl('w480'));

    Media::setNewOrder([$second->id, $first->id]);

    expect(galleryPage('/pl'))->toContain($second->getUrl('w480'))->not->toContain($first->getUrl('w480'));
});

test('the home page card without a photo has no image', function () {
    galleryFishery();

    expect(galleryPage('/pl'))->not->toContain('srcset=');
});

test('adding and removing a photo is written to the fishery activity log', function () {
    $fishery = galleryFishery();
    $media = addPhoto($fishery);
    $media->delete();

    $entries = Activity::query()
        ->where('subject_type', $fishery->getMorphClass())
        ->where('subject_id', $fishery->id)
        ->get()
        ->map(fn (Activity $activity): array => $activity->attribute_changes?->toArray() ?? [])
        ->filter(fn (array $changes): bool => array_key_exists(FisheryImages::GALLERY, $changes['attributes'] ?? []))
        ->values();

    expect($entries)->toHaveCount(2)
        ->and($entries[0]['attributes'][FisheryImages::GALLERY])->toBe($media->file_name)
        ->and($entries[1]['old'][FisheryImages::GALLERY])->toBe($media->file_name);
});

/**
 * Regresja zadania 005: zdjęcia podążają za skonfigurowanymi dyskami, nie za przybitymi w kodzie — sprawdzane
 * przestawieniem konfiguracji na buckety w trakcie testu. Oryginał trafia na PRYWATNY `gcs-private`, warianty
 * na PUBLICZNY `gcs` (ADR-023, aktualizacja z 01.10.2026).
 */
test('the photo upload follows the configured disks: original private, variants public', function () {
    config([
        'filesystems.default' => 'gcs-private',
        'filament.default_filesystem_disk' => 'gcs',
        'media-library.disk_name' => 'gcs-private',
        'media-library.conversions_disk_name' => 'gcs',
    ]);
    Storage::fake('gcs');
    Storage::fake('gcs-private');
    $admin = $this->createSuperAdmin();
    $fishery = Fishery::factory()->create();

    Livewire::actingAs($admin)
        ->test(EditFishery::class, ['record' => $fishery->getRouteKey()])
        ->fillForm([
            FisheryImages::GALLERY => [UploadedFile::fake()->image('a.jpg', 1000, 700), UploadedFile::fake()->image('b.jpg', 1000, 700)],
            FisheryImages::MAP => UploadedFile::fake()->image('map.jpg', 1000, 700),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $gallery = $fishery->fresh()->getMedia(FisheryImages::GALLERY);

    expect($gallery)->toHaveCount(2)
        ->and($fishery->fresh()->getMedia(FisheryImages::MAP))->toHaveCount(1);

    foreach ($gallery as $media) {
        expect($media->disk)->toBe('gcs-private')
            ->and($media->conversions_disk)->toBe('gcs');
        Storage::disk('gcs-private')->assertExists($media->getPathRelativeToRoot());
        Storage::disk('gcs')->assertMissing($media->getPathRelativeToRoot());
        Storage::disk('gcs')->assertExists($media->getPathRelativeToRoot('w960'));
    }
});

/**
 * ⚠️ FilePond domyślnie dopisuje nowe pliki NA POCZĄTEK listy, więc wybrane A, B, C zapisywały się jako C, B, A,
 * a okładką zostawał ostatni wybrany plik. To zachowanie przeglądarki — serwer zapisuje kolejność ze stanu
 * formularza — dlatego test pilnuje ustawienia pola, a drugi kolejności zapisu.
 */
test('new photos are appended in the order they were picked, so the first picked one is the cover', function () {
    $admin = $this->createSuperAdmin();
    $fishery = Fishery::factory()->create();

    Livewire::actingAs($admin)
        ->test(EditFishery::class, ['record' => $fishery->getRouteKey()])
        ->assertFormFieldExists(FisheryImages::GALLERY, fn (SpatieMediaLibraryFileUpload $field): bool => $field->shouldAppendFiles())
        ->fillForm([FisheryImages::GALLERY => [
            UploadedFile::fake()->image('a.jpg', 600, 400),
            UploadedFile::fake()->image('b.jpg', 600, 400),
            UploadedFile::fake()->image('c.jpg', 600, 400),
        ]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($fishery->fresh()->getMedia(FisheryImages::GALLERY)->pluck('name')->all())->toBe(['a', 'b', 'c']);
});

/**
 * ⚠️ Brakujący wariant, którego nie da się dogenerować (oryginał zniknął z bucketu), nie może wywrócić strony
 * łowiska, strony głównej ani 404 — błąd idzie do logu, a zdjęcie bez wariantu się nie pokazuje (038).
 */
test('a variant that can not be generated does not break the pages', function () {
    $fishery = galleryFishery();
    $media = addPhoto($fishery);

    foreach (FisheryImages::sizesFor($media) as $size) {
        Storage::disk('public')->delete($media->getPathRelativeToRoot(FisheryImages::conversionName($size)));
        $media->markAsConversionNotGenerated(FisheryImages::conversionName($size));
    }
    Storage::disk(FisheryImages::originalsDisk())->delete($media->getPathRelativeToRoot());

    $html = galleryPage();

    expect($html)->not->toContain('data-pswp-width');
    galleryPage('/pl');
});

test('a photo added from another disk is sanitised too', function () {
    Storage::fake('local');
    Storage::disk('local')->makeDirectory('incoming');
    // Zapis wprost na udawany dysk — bez plików tymczasowych w /tmp kontenera.
    $image = VipsImage::black(300, 100)->linear([1], [128])->copy();
    $image->set('orientation', 6);
    $image->writeToFile(Storage::disk('local')->path('incoming/photo.jpg'));

    $media = galleryFishery()->addMediaFromDisk('incoming/photo.jpg', 'local')->toMediaCollection(FisheryImages::GALLERY);
    $stored = Storage::disk(FisheryImages::originalsDisk())->path($media->getPathRelativeToRoot());

    expect([$media->getCustomProperty('width'), $media->getCustomProperty('height')])->toBe([100, 300])
        ->and(exif_read_data($stored)['SectionsFound'] ?? null)->toBe('');
});

/**
 * ⚠️ Pliki tymczasowe uploadu Livewire NIE idą na dysk domyślny — na Cloud Run to publiczny bucket, a plik
 * tymczasowy jest surowy (EXIF, GPS) i przyjmowany przed walidacją formularza (przegląd bezpieczeństwa, 038).
 */
test('livewire temporary uploads stay on the private local disk even when the default disk is the bucket', function () {
    // W testach Livewire podmienia dysk na `tmp-for-tests` (`FileUploadConfiguration::disk()`), więc sprawdzamy
    // to, co zobaczy produkcja: jawny dysk w konfiguracji ma pierwszeństwo przed `filesystems.default`.
    config(['filesystems.default' => 'gcs']);

    expect(config('livewire.temporary_file_upload.disk') ?: config('filesystems.default'))->toBe('local')
        ->and(config('filesystems.disks.local.driver'))->toBe('local')
        ->and(config('filesystems.disks.local.serve'))->toBeFalse();
});
