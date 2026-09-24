<?php

namespace Tests\Feature;

use App\Enums\ServiceBillingUnit;
use App\Enums\ServiceScope;
use App\Filament\Resources\AdditionalServiceResource\Pages\CreateAdditionalService;
use App\Filament\Resources\AdditionalServiceResource\Pages\EditAdditionalService;
use App\Models\AdditionalService;
use App\Models\Company;
use App\Models\Fishery;
use App\Models\LongTermPermit;
use App\Models\Position;
use App\Models\PositionAttribute;
use App\Models\User;
use App\Services\AdditionalServiceSync;
use App\Services\OwnerRoleProvisioner;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * Konfiguracja usługi dodatkowej — jednostka, cena 0,00, zasięg i wymagane cechy (zadanie 020).
 */
function serviceOwnerFishery(): Fishery
{
    $owner = User::factory()->create(['name' => 'Wlasciciel Uslug']);
    OwnerRoleProvisioner::addOwnerRole($owner);
    test()->actingAs($owner);
    Filament::setCurrentPanel('owner');

    $company = Company::factory()->forUser($owner)->create();

    return Fishery::factory()->create(['company_id' => $company->id, 'user_id' => $owner->id]);
}

function pinnedService(Fishery $fishery, int $positions): AdditionalService
{
    $service = AdditionalService::factory()->create([
        'fishery_id' => $fishery->id,
        'scope' => ServiceScope::SelectedPositions->value,
        'is_active' => true,
    ]);

    foreach (Position::factory()->count($positions)->create(['fishery_id' => $fishery->id]) as $position) {
        $position->additionalServices()->attach($service->id, ['is_required' => true]);
    }

    return $service;
}

test('a free per-stay service available on the whole fishery can be created', function () {
    $fishery = serviceOwnerFishery();

    Livewire::withQueryParams(['fishery' => $fishery->id])
        ->test(CreateAdditionalService::class)
        ->fillForm([
            'fishery_id' => $fishery->id,
            'name' => 'Postawienie przyczepy',
            'is_active' => true,
            'price' => '0,00',
            'billing_unit' => ServiceBillingUnit::PerStay->value,
            'scope' => ServiceScope::WholeFishery->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $service = AdditionalService::query()->where('name', 'Postawienie przyczepy')->firstOrFail();

    expect(DB::table('additional_services')->where('id', $service->id)->value('price'))->toBe('0.00')
        ->and($service->billing_unit)->toBe(ServiceBillingUnit::PerStay)
        ->and($service->scope)->toBe(ServiceScope::WholeFishery)
        ->and($service->isFree())->toBeTrue()
        ->and($service->priceLabel('PLN'))->toBe(__('free'));
});

test('the price label carries the billing unit', function () {
    $night = AdditionalService::factory()->make(['price' => '20.00', 'billing_unit' => ServiceBillingUnit::PerNight->value]);
    $stay = AdditionalService::factory()->make(['price' => '15.00', 'billing_unit' => ServiceBillingUnit::PerStay->value]);

    expect($night->priceLabel('PLN'))->toBe('20,00 PLN / '.__('night'))
        ->and($stay->priceLabel('PLN'))->toBe('15,00 PLN / '.__('stay'));
});

test('new services default to per night on selected positions', function () {
    $service = AdditionalService::factory()->create();

    expect($service->fresh()->billing_unit)->toBe(ServiceBillingUnit::PerNight)
        ->and($service->fresh()->scope)->toBe(ServiceScope::SelectedPositions);
});

test('an empty available count means no limit and zero is not a limit', function () {
    $fishery = serviceOwnerFishery();

    Livewire::withQueryParams(['fishery' => $fishery->id])
        ->test(CreateAdditionalService::class)
        ->fillForm([
            'fishery_id' => $fishery->id,
            'name' => 'Zero lodek',
            'price' => '20',
            'available_count' => 0,
        ])
        ->call('create')
        ->assertHasFormErrors(['available_count']);
});

/**
 * ⚠️ Zmiana zasięgu na „całe łowisko" USUWA przypięcia. Bez zgody operatora — z podaną liczbą —
 * zapis nie przechodzi; po zgodzie przypięcia znikają, a ślad zostaje w dzienniku zmian.
 */
test('switching to the whole fishery asks for confirmation and then drops every pin', function () {
    $fishery = serviceOwnerFishery();
    $service = pinnedService($fishery, 3);

    $page = Livewire::test(EditAdditionalService::class, ['record' => $service->getRouteKey()])
        ->fillForm(['scope' => ServiceScope::WholeFishery->value])
        ->assertSee(trans_choice(
            'I understand that the service will be unpinned from :count position|I understand that the service will be unpinned from :count positions',
            3,
            ['count' => 3],
        ))
        ->call('save')
        ->assertHasFormErrors(['confirm_unpin']);

    expect($service->positions()->count())->toBe(3);

    $page->fillForm(['scope' => ServiceScope::WholeFishery->value, 'confirm_unpin' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    $trail = Activity::query()
        ->where('subject_type', $service->getMorphClass())
        ->where('subject_id', $service->id)
        ->get()
        ->first(fn (Activity $activity): bool => isset($activity->attribute_changes['old']['pinned_position_ids']));

    expect($service->fresh()->scope)->toBe(ServiceScope::WholeFishery)
        ->and($service->positions()->count())->toBe(0)
        ->and($trail?->attribute_changes['old']['pinned_position_ids'])->toHaveCount(3);
});

test('the pins go away with the scope change even when saved around the form', function () {
    $fishery = serviceOwnerFishery();
    $service = pinnedService($fishery, 2);

    $service->update(['scope' => ServiceScope::WholeFishery->value]);

    expect($service->positions()->count())->toBe(0);

    // Powrót na „wybrane stanowiska" zaczyna od pustej listy przypięć.
    $service->update(['scope' => ServiceScope::SelectedPositions->value]);

    expect($service->positions()->count())->toBe(0);
});

test('a whole-fishery service can not be pinned through the position form sync', function () {
    $fishery = serviceOwnerFishery();
    $position = Position::factory()->create(['fishery_id' => $fishery->id]);
    $everywhere = AdditionalService::factory()->create([
        'fishery_id' => $fishery->id,
        'scope' => ServiceScope::WholeFishery->value,
    ]);
    $selected = AdditionalService::factory()->create(['fishery_id' => $fishery->id]);

    AdditionalServiceSync::syncAdditionalServices($position, [
        ['additional_service_id' => $everywhere->id, 'is_required' => true],
        ['additional_service_id' => $selected->id, 'is_required' => false],
    ]);

    expect($position->additionalServices()->pluck('additional_services.id')->all())->toBe([$selected->id]);
});

test('required attributes are saved from the form and logged', function () {
    $fishery = serviceOwnerFishery();
    $service = pinnedService($fishery, 1);
    $vehicle = PositionAttribute::factory()->create(['name' => 'Wjazd pojazdem']);

    Livewire::test(EditAdditionalService::class, ['record' => $service->getRouteKey()])
        ->fillForm(['required_attribute_ids' => [(string) $vehicle->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    $trail = Activity::query()
        ->where('subject_id', $service->id)
        ->where('subject_type', $service->getMorphClass())
        ->get()
        ->first(fn (Activity $activity): bool => isset($activity->attribute_changes['attributes']['required_attribute_ids']));

    expect($service->requiredAttributes()->pluck('position_attributes.id')->all())->toBe([$vehicle->id])
        ->and($trail?->attribute_changes['attributes']['required_attribute_ids'])->toBe([$vehicle->id])
        ->and(Livewire::test(EditAdditionalService::class, ['record' => $service->getRouteKey()])->get('data.required_attribute_ids'))
        ->toBe([(string) $vehicle->id]);
});

test('only yes/no attributes can be required', function () {
    $fishery = serviceOwnerFishery();
    $service = pinnedService($fishery, 1);
    $depth = PositionAttribute::factory()->number()->create(['name' => 'Glebokosc']);

    Livewire::test(EditAdditionalService::class, ['record' => $service->getRouteKey()])
        ->fillForm(['required_attribute_ids' => [(string) $depth->id]])
        ->call('save')
        ->assertHasFormErrors(['required_attribute_ids']);

    // Bramka zapisu odrzuca to samo z pominięciem formularza.
    AdditionalServiceSync::syncRequiredAttributes($service, [$depth->id]);

    expect($service->requiredAttributes()->count())->toBe(0);
});

test('saving the service keeps the requirement of a soft-deleted attribute', function () {
    $fishery = serviceOwnerFishery();
    $service = pinnedService($fishery, 1);
    $vehicle = PositionAttribute::factory()->create();
    $service->requiredAttributes()->attach($vehicle->id);
    $vehicle->delete();

    Livewire::test(EditAdditionalService::class, ['record' => $service->getRouteKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    $vehicle->restore();

    expect($service->requiredAttributes()->pluck('position_attributes.id')->all())->toBe([$vehicle->id]);
});

/**
 * K18/G13 po stronie usług: powiązania z pozwoleniami długookresowymi zostają nietknięte —
 * migracje zadania 020 nie ruszają tej tabeli, a miękkie usunięcie usługi jej nie kasuje.
 */
test('links to long-term permits survive and are not removed by a soft delete', function () {
    $fishery = serviceOwnerFishery();
    $service = pinnedService($fishery, 1);
    $permit = LongTermPermit::factory()->create(['fishery_id' => $fishery->id]);

    DB::table('additional_service_long_term_permit')->insert([
        'additional_service_id' => $service->id,
        'long_term_permit_id' => $permit->id,
    ]);

    $service->delete();

    expect(DB::table('additional_service_long_term_permit')->where('additional_service_id', $service->id)->count())->toBe(1);
});
