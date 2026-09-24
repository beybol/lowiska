<?php

namespace Tests\Feature;

use App\Filament\Resources\AdditionalServiceResource\Pages\CreateAdditionalService;
use App\Models\AdditionalService;
use App\Models\Company;
use App\Models\Fishery;
use App\Models\User;
use App\Services\OwnerRoleProvisioner;
use App\Services\SharedFormComponents;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\PriceInputForm;

/**
 * Regresja dla zadania 012: zapis usługi dodatkowej kończył się błędem 500
 * (`BindingResolutionException: [$attribute] was unresolvable`).
 *
 * ⚠️ Przyczyna: reguła walidacyjna w `SharedFormComponents::getPriceInput()` była przekazana do
 * `rules()` jako domknięcie Laravela `fn (string $attribute, $value, Closure $fail)`.
 * Filament woła `evaluate()` na KAŻDYM elemencie `rules()` i wstrzykuje argumenty
 * po nazwie, więc próbował rozwiązać `$attribute` z kontenera. Reguła-domknięcie
 * musi być opakowana w domknięcie, które ją zwraca.
 *
 * Błąd wychodził dopiero przy wysłaniu formularza (żądanie Livewire), nie przy
 * jego otwarciu — dlatego test MUSI wołać `create()`, samo wyrenderowanie strony
 * niczego by nie wykryło.
 */
/**
 * Cena prosto z bazy, z pominięciem akcesora modelu (ten formatuje ją po polsku).
 */
function rawPrice(string $serviceName): ?string
{
    return DB::table('additional_services')
        ->where('name', $serviceName)
        ->value('price');
}

function priceTestFishery(): Fishery
{
    $owner = User::factory()->create();
    OwnerRoleProvisioner::addOwnerRole($owner);
    test()->actingAs($owner);
    Filament::setCurrentPanel('owner');

    $company = Company::factory()->forUser($owner)->create();

    return Fishery::factory()->create([
        'company_id' => $company->id,
        'user_id' => $owner->id,
    ]);
}

test('an additional service with a price can be saved', function () {
    $fishery = priceTestFishery();

    Livewire::withQueryParams(['fishery' => $fishery->id])
        ->test(CreateAdditionalService::class)
        ->fillForm([
            'fishery_id' => $fishery->id,
            'name' => 'Grill + węgiel',
            'is_active' => true,
            'price' => '99',
            'available_count' => 3,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $service = AdditionalService::query()->where('name', 'Grill + węgiel')->first();

    expect($service)->not->toBeNull();
    expect(rawPrice('Grill + węgiel'))->toBe('99.00');
});

test('a price with a decimal comma is accepted and stored with its decimal part', function () {
    $fishery = priceTestFishery();

    Livewire::withQueryParams(['fishery' => $fishery->id])
        ->test(CreateAdditionalService::class)
        ->fillForm([
            'fishery_id' => $fishery->id,
            'name' => 'Wypożyczenie łódki',
            'is_active' => true,
            'price' => '49,50',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    // ⚠️ Czytamy SUROWĄ wartość z bazy, nie przez model: akcesor
    // `AdditionalService::getPriceAttribute()` zamienia kropkę na przecinek przy
    // locale `pl`, więc `(float) $model->price` ucięłoby część dziesiętną
    // („49,50" → 49.0) i test kłamałby o utracie danych, której nie ma.
    expect(rawPrice('Wypożyczenie łódki'))->toBe('49.50');
});

/**
 * ⚠️ Od zadania 020 usługa może być darmowa (O21, „postawienie przyczepy") — pole ceny usługi ma
 * minimum 0,00. Reguła-domknięcie nadal BIEGNIE przy zapisie (to był błąd z zadania 012), tylko
 * zero jej nie łamie. Samo minimum jako kontrakt komponentu pilnuje `PriceInputForm` niżej.
 */
test('a free additional service at 0,00 passes the closure rule', function () {
    $fishery = priceTestFishery();

    Livewire::withQueryParams(['fishery' => $fishery->id])
        ->test(CreateAdditionalService::class)
        ->fillForm([
            'fishery_id' => $fishery->id,
            'name' => 'Darmowa usługa',
            'is_active' => true,
            'price' => '0,00',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(rawPrice('Darmowa usługa'))->toBe('0.00');
});

test('a price below the minimum of the component is rejected by the closure rule', function () {
    Livewire::test(PriceInputForm::class)
        ->fillForm(['price' => '0,00'])
        ->call('save')
        ->assertHasFormErrors(['price']);
});

/*
 * Testy dopisane po mutacjach zadania 023.
 */

function createServiceWithPrice(Fishery $fishery, string $name, ?string $price)
{
    return Livewire::withQueryParams(['fishery' => $fishery->id])
        ->test(CreateAdditionalService::class)
        ->fillForm([
            'fishery_id' => $fishery->id,
            'name' => $name,
            'is_active' => true,
            'price' => $price,
        ])
        ->call('create');
}

test('the minimum in the price error is shown with two decimals', function () {
    $errors = Livewire::test(PriceInputForm::class)
        ->fillForm(['price' => '0,00'])
        ->call('save')
        ->assertHasFormErrors(['price'])
        ->errors()
        ->get('data.price');

    expect(implode(' ', $errors))->toMatch('/(?<![\d.])0\.01(?!\d)/');
});

/**
 * ⚠️ Przecinek zamienia się na kropkę PRZED porównaniem z minimum. Bez tego „0,50” czytałoby
 * się jako 0 i usługa za pięćdziesiąt groszy odpadałaby jako za tania.
 */
test('a price below one with a decimal comma passes the minimum', function () {
    Livewire::test(PriceInputForm::class)
        ->fillForm(['price' => '0,50'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertSet('saved.price', 0.5);
});

test('a service can be saved without a price', function () {
    $fishery = priceTestFishery();

    createServiceWithPrice($fishery, 'Bez ceny', null)->assertHasNoFormErrors();

    expect(DB::table('additional_services')->where('name', 'Bez ceny')->exists())->toBeTrue()
        ->and(DB::table('additional_services')->where('name', 'Bez ceny')->value('price'))->toBeNull();
});

test('a price with more than two decimals is rejected', function () {
    $fishery = priceTestFishery();

    createServiceWithPrice($fishery, 'Za dokładna', '12.345')->assertHasFormErrors(['price']);
});

test('the price field takes the label it is given', function () {
    expect(SharedFormComponents::getPriceInput('amount', 'Kwota za dobę')->getLabel())->toBe('Kwota za dobę')
        ->and(SharedFormComponents::getPriceInput()->getLabel())->toBe(__('Price'));
});

test('the fishery name is filled in from the fishery the form was opened for', function () {
    $fishery = priceTestFishery();

    Livewire::withQueryParams(['fishery' => $fishery->id])
        ->test(CreateAdditionalService::class)
        ->assertFormSet(['fishery_name' => $fishery->name]);
});
