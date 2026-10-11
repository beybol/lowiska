<?php

namespace Tests\Feature;

use App\Models\Fishery;
use App\Services\FisheryImages;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Support\StayFixtures;

/**
 * Granice wariantów zdjęć łowiska wskazane przez testy mutacyjne (02.10.2026) — rozmiary na styku, wybór wariantu
 * dla szerokości, pamięć wariantów w żądaniu i wymiary liczone z wymiarów oryginału (zadanie 036, ADR-023).
 */
beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');
});

function imagesFishery(): Fishery
{
    [$fishery] = StayFixtures::fisheryWithPosition();

    return $fishery;
}

function imagesPhoto(int $width = 1200, int $height = 800): Media
{
    return imagesFishery()->addMedia(UploadedFile::fake()->image('photo.jpg', $width, $height))
        ->toMediaCollection(FisheryImages::GALLERY);
}

test('a photo exactly as long as a size gets no larger variant', function () {
    $media = imagesPhoto(960, 640);

    expect(FisheryImages::sizesFor($media))->toBe([480, 960])
        ->and(array_keys(array_filter($media->generated_conversions)))->toBe(['w480', 'w960']);
});

test('a tall photo is capped at 2560 px by its height', function () {
    $media = imagesPhoto(2000, 3000);

    expect($media->getCustomProperty('height'))->toBe(2560)
        ->and($media->getCustomProperty('width'))->toBe(1707);
});

test('the url is the smallest variant that covers the display width', function () {
    $media = imagesPhoto();

    expect(FisheryImages::url($media, 400))->toBe($media->getUrl('w480'))
        ->and(FisheryImages::url($media, 480))->toBe($media->getUrl('w480'))
        ->and(FisheryImages::url($media, 481))->toBe($media->getUrl('w960'));
});

/**
 * ⚠️ Warianty liczą się raz na obiekt zdjęcia — drugi odczyt w tym samym renderze nie próbuje ponownie
 * dogenerować brakującego wariantu. Nowy obiekt (nowe żądanie) liczy od nowa, co pokazuje druga połowa testu.
 */
test('the variants of one photo object are worked out once per request', function () {
    $media = imagesPhoto();
    expect(FisheryImages::variants($media))->toHaveCount(3);

    // Wariant „znika", a oryginału brak — dogenerowanie musiałoby się nie udać.
    Storage::disk(FisheryImages::originalsDisk())->delete($media->getPathRelativeToRoot());
    $media->markAsConversionNotGenerated('w960');

    expect(FisheryImages::variants($media))->toHaveCount(3)
        ->and(FisheryImages::variants($media->fresh()))->toHaveCount(2);
});

test('a photo with all its variants takes no lock', function () {
    $media = imagesPhoto()->fresh();

    Cache::spy();
    FisheryImages::variants($media);

    Cache::shouldNotHaveReceived('lock');
});

test('the variant size follows the dimensions of the original', function (int $width, int $height, array $expected) {
    $media = imagesPhoto();
    $media->setCustomProperty('width', $width)->setCustomProperty('height', $height)->save();

    $first = FisheryImages::variants($media->fresh())[0];

    expect([$first['width'], $first['height']])->toBe($expected);
})->with([
    'a sliver is at least 1 px high' => [2000, 2, [480, 1]],
    'a sliver is at least 1 px wide' => [2, 2000, [1, 480]],
    'the height is rounded half up' => [1000, 670, [480, 322]],
    'the width is rounded half up' => [670, 1000, [322, 480]],
    'the width is rounded down below half' => [667, 1000, [320, 480]],
]);

test('a photo without known dimensions gets every size, each as a square', function () {
    $media = imagesPhoto();
    $media->forgetCustomProperty('width')->forgetCustomProperty('height')->save();
    $media = $media->fresh();

    $variants = FisheryImages::variants($media);

    expect(FisheryImages::sizesFor($media))->toBe(FisheryImages::SIZES)
        ->and([$variants[0]['width'], $variants[0]['height']])->toBe([480, 480]);
});

test('a 1 px photo still has its single size', function () {
    $media = imagesPhoto();
    $media->setCustomProperty('width', 1)->setCustomProperty('height', 1);

    expect(FisheryImages::sizesFor($media))->toBe([480]);
});
