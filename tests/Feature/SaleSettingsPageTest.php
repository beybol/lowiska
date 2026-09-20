<?php

namespace Tests\Feature;

use App\Enums\SaleMode;
use App\Filament\Resources\FisheryResource;
use App\Filament\Resources\FisheryResource\Pages\CreateFishery;
use App\Filament\Resources\FisheryResource\Pages\ManageSaleSettings;
use App\Helpers\Helper;
use App\Models\Fishery;
use App\Models\SalePeriod;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Ekran „Sprzedaż i sezony" — zakładka konfiguracyjna huba jako strona ustawień
 * zasobu `FisheryResource` (ADR-006, aktualizacja z zadania 015).
 */
beforeEach(function () {
    Filament::setCurrentPanel('owner');
});

function ownerWithFishery(): array
{
    $owner = User::factory()->create(['name' => 'Wlasciciel Testowy']);
    Helper::addOwnerRole($owner);

    return [$owner, Fishery::factory()->forUser($owner)->create()];
}

test('owner can open the sale settings of their own fishery', function () {
    [$owner, $fishery] = ownerWithFishery();

    $this->actingAs($owner);

    Livewire::test(ManageSaleSettings::class, ['record' => $fishery->id])
        ->assertStatus(200)
        ->assertFormFieldExists('sale_mode')
        ->assertFormFieldExists('day_start_time')
        ->assertFormFieldExists('salePeriods');
});

test('owner can not open the sale settings of somebody elses fishery', function () {
    [$owner] = ownerWithFishery();
    $foreign = Fishery::factory()->forUser(User::factory()->create())->create();

    $this->actingAs($owner);

    // Zawężenie z `FisheryResource::getEloquentQuery()` działa na wiązaniu rekordu,
    // więc cudze łowisko nie istnieje dla tej strony — nie „istnieje, ale zabronione".
    // ⚠️ Asercja idzie przez PRAWDZIWE ŻĄDANIE, nie przez `Livewire::test()`: montowanie
    // komponentu wprost omija konwersję wyjątku na 404, więc test livewire'owy sprawdzałby
    // klasę wyjątku zamiast tego, co zobaczy przeglądarka.
    $this->get(FisheryResource::getUrl('sale-settings', ['record' => $foreign]))
        ->assertNotFound();
});

test('saving the fishing day and a sale period makes days sellable', function () {
    [$owner, $fishery] = ownerWithFishery();

    $this->actingAs($owner);

    Livewire::test(ManageSaleSettings::class, ['record' => $fishery->id])
        ->fillForm([
            'sale_mode' => SaleMode::DailyPeriod->value,
            'day_start_time' => '15:00',
            'day_end_time' => '15:00',
            'timezone' => 'Europe/Warsaw',
            'salePeriods' => [
                ['starts_on' => '2026-02-01', 'ends_on' => '2026-09-30', 'name' => 'Sezon główny'],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $fishery->refresh();

    expect($fishery->sale_mode)->toBe(SaleMode::DailyPeriod)
        ->and($fishery->salePeriods()->count())->toBe(1)
        ->and($fishery->timezone)->toBe('Europe/Warsaw');
});

test('saving two overlapping periods is rejected', function () {
    [$owner, $fishery] = ownerWithFishery();

    $this->actingAs($owner);

    Livewire::test(ManageSaleSettings::class, ['record' => $fishery->id])
        ->fillForm([
            'sale_mode' => SaleMode::DailyPeriod->value,
            'day_start_time' => '15:00',
            'day_end_time' => '15:00',
            'timezone' => 'Europe/Warsaw',
            'salePeriods' => [
                ['starts_on' => '2026-02-01', 'ends_on' => '2026-06-30', 'name' => 'Pierwszy'],
                ['starts_on' => '2026-06-01', 'ends_on' => '2026-09-30', 'name' => 'Drugi'],
            ],
        ])
        ->call('save')
        ->assertHasFormErrors(['salePeriods']);

    expect($fishery->salePeriods()->count())->toBe(0);
});

test('saving a period ending before it starts is rejected', function () {
    [$owner, $fishery] = ownerWithFishery();

    $this->actingAs($owner);

    Livewire::test(ManageSaleSettings::class, ['record' => $fishery->id])
        ->fillForm([
            'sale_mode' => SaleMode::DailyPeriod->value,
            'day_start_time' => '15:00',
            'day_end_time' => '15:00',
            'timezone' => 'Europe/Warsaw',
            'salePeriods' => [
                ['starts_on' => '2026-09-30', 'ends_on' => '2026-02-01', 'name' => 'Odwrócony'],
            ],
        ])
        ->call('save')
        ->assertHasFormErrors(['salePeriods']);
});

test('saving redirects back to the fishery hub', function () {
    [$owner, $fishery] = ownerWithFishery();

    $this->actingAs($owner);

    $page = Livewire::test(ManageSaleSettings::class, ['record' => $fishery->id])->instance();

    expect($page->getRedirectUrl())
        ->toBe(FisheryResource::getUrl('manage', ['record' => $fishery]));
});

test('existing sale periods are loaded into the form', function () {
    [$owner, $fishery] = ownerWithFishery();
    SalePeriod::factory()->create([
        'fishery_id' => $fishery->id,
        'starts_on' => '2026-02-01',
        'ends_on' => '2026-09-30',
        'name' => 'Sezon glowny',
    ]);

    $this->actingAs($owner);

    $state = Livewire::test(ManageSaleSettings::class, ['record' => $fishery->id])
        ->assertStatus(200)
        ->get('data.salePeriods');

    // ⚠️ Asercja na STANIE formularza, nie na HTML-u: klucze pozycji repeatera są
    // losowe, a wartości siedzą w atrybutach `value`, więc `assertSee` sprawdzałoby
    // obecność napisu, a nie to, że okres faktycznie wczytał się do formularza.
    expect($state)->toHaveCount(1)
        ->and(reset($state)['starts_on'])->toStartWith('2026-02-01')
        ->and(reset($state)['name'])->toBe('Sezon glowny');
});

/**
 * ⚠️ Niezmiennik z „Rozstrzygnięć": pola doby żyją POZA
 * `FisheryResource::fisheryDetailComponents()`, więc kreator zakładania łowiska
 * o nie nie pyta. Bez tego testu regres byłby niewidoczny — kreator dalej by
 * działał, tylko wydłużony o decyzję, której nowy właściciel nie umie podjąć.
 */
test('the fishery wizard does not ask about the fishing day', function () {
    [$owner] = ownerWithFishery();

    $this->actingAs($owner);

    Livewire::test(CreateFishery::class)
        ->assertStatus(200)
        ->assertFormFieldDoesNotExist('day_start_time')
        ->assertFormFieldDoesNotExist('day_end_time')
        ->assertFormFieldDoesNotExist('sale_mode');
});
