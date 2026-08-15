<?php

namespace Tests\Feature;

use App\Filament\Resources\FisheryResource\Pages\EditFishery;
use App\Models\Fishery;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Regresja dla zadania 005: oba pola FileUpload w FisheryResource miały twarde
 * ->disk('public'), więc zmienna środowiskowa (FILAMENT_FILESYSTEM_DISK) nie mogła
 * niczego realnie przełączyć. Testy dowodzą, że pola podążają za konfiguracją,
 * przestawiając ją na `gcs` w trakcie testu — nie tylko sprawdzają zachowanie
 * przy domyślnym dysku deweloperskim.
 */
beforeEach(function () {
    config([
        'filesystems.default' => 'gcs',
        'filament.default_filesystem_disk' => 'gcs',
    ]);
    Storage::fake('gcs');
});

test('map image upload follows the configured disk, not a hardcoded one', function () {
    $admin = $this->createSuperAdmin();
    $fishery = Fishery::factory()->create(['map_image_path' => null]);

    $file = UploadedFile::fake()->image('map.jpg');

    Livewire::actingAs($admin)
        ->test(EditFishery::class, ['record' => $fishery->getRouteKey()])
        ->fillForm(['map_image_path' => $file])
        ->call('save')
        ->assertHasNoFormErrors();

    $fishery->refresh();

    expect($fishery->map_image_path)->not->toBeNull();
    expect($fishery->map_image_path)->toStartWith('maps/');
    Storage::disk('gcs')->assertExists($fishery->map_image_path);
    expect(Storage::disk('gcs')->url($fishery->map_image_path))->not->toBeEmpty();
});

test('gallery images upload follows the configured disk, not a hardcoded one', function () {
    $admin = $this->createSuperAdmin();
    $fishery = Fishery::factory()->create(['gallery_images' => null]);

    $files = [
        UploadedFile::fake()->image('gallery-1.jpg'),
        UploadedFile::fake()->image('gallery-2.jpg'),
    ];

    Livewire::actingAs($admin)
        ->test(EditFishery::class, ['record' => $fishery->getRouteKey()])
        ->fillForm(['gallery_images' => $files])
        ->call('save')
        ->assertHasNoFormErrors();

    $fishery->refresh();

    expect($fishery->gallery_images)->toHaveCount(2);

    foreach ($fishery->gallery_images as $path) {
        expect($path)->toStartWith('galleries/');
        Storage::disk('gcs')->assertExists($path);
        expect(Storage::disk('gcs')->url($path))->not->toBeEmpty();
    }
});
