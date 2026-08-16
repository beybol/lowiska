<?php

namespace Tests\Feature;

use App\Filament\Resources\AdditionalServiceResource\Pages\CreateAdditionalService;
use App\Helpers\Helper;
use App\Models\AdditionalService;
use App\Models\Company;
use App\Models\Fishery;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Regresja dla zadania 012: zapis usługi dodatkowej kończył się błędem 500
 * (`BindingResolutionException: [$attribute] was unresolvable`).
 *
 * ⚠️ Przyczyna: reguła walidacyjna w `Helper::getPriceInput()` była przekazana do
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
    Helper::addOwnerRole($owner);
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

test('a price below the minimum is rejected by the closure rule', function () {
    $fishery = priceTestFishery();

    // Ta asercja dowodzi, że reguła-domknięcie naprawdę DZIAŁA, a nie tylko
    // przestała wywalać wyjątek.
    Livewire::withQueryParams(['fishery' => $fishery->id])
        ->test(CreateAdditionalService::class)
        ->fillForm([
            'fishery_id' => $fishery->id,
            'name' => 'Za tania usługa',
            'is_active' => true,
            'price' => '0,00',
        ])
        ->call('create')
        ->assertHasFormErrors(['price']);
});
