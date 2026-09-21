<?php

namespace Tests\Feature;

use App\Enums\SaleMode;
use App\Filament\Resources\FisheryResource;
use App\Filament\Resources\FisheryResource\Pages\CreateFishery;
use App\Filament\Resources\FisheryResource\Pages\ManageSaleSettings;
use App\Models\Fishery;
use App\Models\SalePeriod;
use App\Models\User;
use App\Services\OwnerRoleProvisioner;
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
    OwnerRoleProvisioner::addOwnerRole($owner);

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

/**
 * ⚠️ Przedsprzedaż jest WŁAŚCIWOŚCIĄ OKRESU, nie osobną tabelą — cztery kolumny na
 * `sale_periods` i przełącznik w wierszu repeatera (zadanie 017, rozstrzygnięcie 2).
 * Przełącznik NIE jest kolumną: stan wynika z wypełnienia obu dat okna.
 */
test('a presale can be opened on a sale period', function () {
    [$owner, $fishery] = ownerWithFishery();
    $this->actingAs($owner);

    Livewire::test(ManageSaleSettings::class, ['record' => $fishery->id])
        ->fillForm([
            'sale_mode' => SaleMode::DailyPeriod->value,
            'day_start_time' => '15:00',
            'day_end_time' => '15:00',
            'timezone' => 'Europe/Warsaw',
            'sale_horizon_days' => 365,
            'salePeriods' => [
                [
                    'starts_on' => '2027-03-01',
                    'ends_on' => '2027-10-31',
                    'name' => 'Sezon 2027',
                    'presale_enabled' => true,
                    'presale_opens_on' => '2026-11-04',
                    'presale_closes_on' => '2026-11-14',
                    'presale_min_nights' => 5,
                    'presale_whole_terms_bypass_min_nights' => true,
                ],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $period = SalePeriod::where('fishery_id', $fishery->id)->firstOrFail();

    expect($fishery->fresh()->sale_horizon_days)->toBe(365)
        ->and($period->presale_opens_on->toDateString())->toBe('2026-11-04')
        ->and($period->presale_closes_on->toDateString())->toBe('2026-11-14')
        ->and($period->presale_min_nights)->toBe(5)
        ->and($period->presale_whole_terms_bypass_min_nights)->toBeTrue()
        ->and($period->hasPresale())->toBeTrue();
});

/**
 * ⚠️ Stan przełącznika wraca Z DANYCH, nie z kolumny — nie ma kolumny
 * `presale_enabled` i mieć jej nie ma (`panel-wlasciciela.md` §6).
 */
test('the presale toggle state is rebuilt from the saved window', function () {
    [$owner, $fishery] = ownerWithFishery();
    $fishery->update(['day_start_time' => '15:00:00', 'day_end_time' => '15:00:00']);

    SalePeriod::factory()->create([
        'fishery_id' => $fishery->id,
        'starts_on' => '2027-03-01',
        'ends_on' => '2027-10-31',
        'presale_opens_on' => '2026-11-04',
        'presale_closes_on' => '2026-11-14',
    ]);

    $this->actingAs($owner);

    $state = Livewire::test(ManageSaleSettings::class, ['record' => $fishery->id])
        ->get('data')['salePeriods'];
    $row = $state[array_key_first($state)];

    expect($row['presale_enabled'])->toBeTrue();
});

/**
 * ⚠️ Wyłączenie przełącznika CZYŚCI cztery kolumny. Bez tego okno zostawało w bazie
 * i dalej ograniczało sprzedaż przez `presale_min_nights`, a formularz pokazywał
 * przedsprzedaż jako wyłączoną.
 */
test('switching the presale off clears its four columns', function () {
    [$owner, $fishery] = ownerWithFishery();
    $fishery->update(['day_start_time' => '15:00:00', 'day_end_time' => '15:00:00']);

    $period = SalePeriod::factory()->create([
        'fishery_id' => $fishery->id,
        'starts_on' => '2027-03-01',
        'ends_on' => '2027-10-31',
        'presale_opens_on' => '2026-11-04',
        'presale_closes_on' => '2026-11-14',
        'presale_min_nights' => 5,
    ]);

    $this->actingAs($owner);

    $component = Livewire::test(ManageSaleSettings::class, ['record' => $fishery->id]);

    // ⚠️ Przestawiamy przelacznik na ISTNIEJACYM wierszu, po jego wlasnym kluczu
    // (`record-<id>`). `fillForm()` z lista pod kluczem `0` nie trafia w ten wiersz:
    // Filament dopasowuje wiersze repeatera po kluczach stanu, wiec podmiana calej listy
    // zostawialaby stary rekord nietknietym i test zielenilby sie na zepsutym kodzie.
    $rowKey = array_key_first($component->get('data')['salePeriods']);

    $component->set("data.salePeriods.{$rowKey}.presale_enabled", false)
        ->call('save')
        ->assertHasNoFormErrors();

    $period = $period->fresh();

    expect($period->presale_opens_on)->toBeNull()
        ->and($period->presale_closes_on)->toBeNull()
        ->and($period->presale_min_nights)->toBeNull()
        ->and($period->hasPresale())->toBeFalse();
});

test('a presale window closing before it opens is rejected', function () {
    [$owner, $fishery] = ownerWithFishery();
    $this->actingAs($owner);

    Livewire::test(ManageSaleSettings::class, ['record' => $fishery->id])
        ->fillForm([
            'sale_mode' => SaleMode::DailyPeriod->value,
            'day_start_time' => '15:00',
            'day_end_time' => '15:00',
            'timezone' => 'Europe/Warsaw',
            'salePeriods' => [
                [
                    'starts_on' => '2027-03-01',
                    'ends_on' => '2027-10-31',
                    'name' => null,
                    'presale_enabled' => true,
                    'presale_opens_on' => '2026-11-14',
                    'presale_closes_on' => '2026-11-04',
                ],
            ],
        ])
        ->call('save')
        ->assertHasFormErrors(['salePeriods']);
});

/**
 * ⚠️ Przedsprzedaż bez horyzontu ZAPISUJE się — to ostrzeżenie, nie błąd. Okno nic
 * wtedy nie otwiera (doby okresu i tak są kupowalne), a jedynie ogranicza sprzedaż
 * przez `presale_min_nights` na czas swojego trwania.
 */
test('a presale without a sale horizon still saves', function () {
    [$owner, $fishery] = ownerWithFishery();
    $this->actingAs($owner);

    Livewire::test(ManageSaleSettings::class, ['record' => $fishery->id])
        ->fillForm([
            'sale_mode' => SaleMode::DailyPeriod->value,
            'day_start_time' => '15:00',
            'day_end_time' => '15:00',
            'timezone' => 'Europe/Warsaw',
            'sale_horizon_days' => null,
            'salePeriods' => [
                [
                    'starts_on' => '2027-03-01',
                    'ends_on' => '2027-10-31',
                    'name' => null,
                    'presale_enabled' => true,
                    'presale_opens_on' => '2026-11-04',
                    'presale_closes_on' => '2026-11-14',
                    'presale_min_nights' => 5,
                ],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($fishery->fresh()->sale_horizon_days)->toBeNull()
        ->and(SalePeriod::where('fishery_id', $fishery->id)->firstOrFail()->hasPresale())->toBeTrue();
});

/**
 * Istniejące okresy dostają PUSTE kolumny przedsprzedaży i nic się nie zmienia w ich
 * zachowaniu — migracja nie dopisuje reguł istniejącym łowiskom.
 */
test('an existing sale period keeps working with empty presale columns', function () {
    [$owner, $fishery] = ownerWithFishery();
    $fishery->update(['day_start_time' => '15:00:00', 'day_end_time' => '15:00:00']);

    $period = SalePeriod::factory()->create([
        'fishery_id' => $fishery->id,
        'starts_on' => '2026-03-01',
        'ends_on' => '2026-10-31',
    ]);

    expect($period->presale_opens_on)->toBeNull()
        ->and($period->presale_closes_on)->toBeNull()
        ->and($period->presale_min_nights)->toBeNull()
        ->and($period->hasPresale())->toBeFalse()
        // Domyślna wartość flagi jest lustrzona w modelu, więc świeżo utworzony rekord
        // niesie ją także PRZED odświeżeniem z bazy.
        ->and($period->presale_whole_terms_bypass_min_nights)->toBeTrue();

    $this->actingAs($owner);

    Livewire::test(ManageSaleSettings::class, ['record' => $fishery->id])
        ->assertStatus(200)
        ->assertFormFieldExists('sale_horizon_days');
});
