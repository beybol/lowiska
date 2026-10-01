<?php

namespace Tests\Feature;

use App\Enums\FisherySection;
use App\Filament\Resources\FisheryResource;
use App\Filament\Resources\FisheryResource\Pages\EditFishery;
use App\Filament\Resources\FisheryResource\Pages\ManageFishery;
use App\Models\Fishery;
use Filament\Schemas\Components\Section;
use InvalidArgumentException;
use Livewire\Livewire;

/**
 * Formularz łowiska i podgląd „Dane łowiska" układa JEDNA lista sekcji — `FisherySection` (zadanie 038, R5/R6).
 *
 * ⚠️ Pilnuje, że oba widoki mają sekcje w kolejności `cases()` i że żadna sekcja nie jest pominięta:
 * `FisheryResource::layout()` rzuca wyjątek przy braku komponentów, więc render bez błędu dowodzi kompletu.
 */

/** Nagłówki sekcji z ramką, w kolejności enumu — „Podstawowe" stoi bez ramki i bez nagłówka. */
function framedSectionLabels(): array
{
    return array_values(array_map(
        fn (FisherySection $section): string => $section->label(),
        array_filter(FisherySection::cases(), fn (FisherySection $section): bool => $section->hasFrame()),
    ));
}

test('the edit form shows every section in the enum order', function () {
    $admin = $this->createSuperAdmin();
    $fishery = Fishery::factory()->create();

    Livewire::actingAs($admin)
        ->test(EditFishery::class, ['record' => $fishery->getRouteKey()])
        ->assertOk()
        ->assertSeeInOrder(framedSectionLabels());
});

test('the fishery data page shows the same sections in the same order', function () {
    $admin = $this->createSuperAdmin();
    $fishery = Fishery::factory()->create();

    Livewire::actingAs($admin)
        ->test(ManageFishery::class, ['record' => $fishery->getRouteKey()])
        ->assertOk()
        ->assertSeeInOrder(framedSectionLabels());
});

test('the layout builds one container per section, in the enum order', function () {
    $components = [];

    foreach (FisherySection::cases() as $section) {
        $components[$section->value] = [];
    }

    $layout = FisheryResource::layout($components);

    expect($layout)->toHaveCount(count(FisherySection::cases()));

    $framed = array_values(array_filter($layout, fn ($container): bool => $container instanceof Section));
    expect(array_map(fn (Section $section): string => (string) $section->getHeading(), $framed))->toBe(framedSectionLabels());
});

test('a section without components is a programming error, not an empty section', function () {
    FisheryResource::layout([FisherySection::Basic->value => []]);
})->throws(InvalidArgumentException::class);
