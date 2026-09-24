<?php

namespace Tests\Feature;

use App\Enums\ServiceScope;
use App\Enums\ServiceUnavailabilityReason;
use App\Models\AdditionalService;
use App\Models\AvailabilityBlock;
use App\Models\Fishery;
use App\Models\Position;
use App\Models\PositionAttribute;
use App\Models\PositionAttributeValue;
use App\Services\PositionAvailability;
use App\Services\PositionServices;
use App\Services\PositionServiceStatus;
use Illuminate\Support\Facades\DB;
use Tests\Support\StayFixtures;

/**
 * Dom „usług stanowiska" — jakie usługi ma stanowisko w dobie i dlaczego któraś jest
 * niedostępna (zadanie 020).
 *
 * ⚠️ Testy pilnują TRZECIEGO STANU cechy: brak wartości to „nikt się nie wypowiedział" i liczy
 * się jako brak. Wersja „brak wiersza = w porządku" przepuszczałaby przyczepę tam, gdzie nikt
 * nie potwierdził wjazdu — a test sprawdzający tylko „nie" by tego nie zobaczył.
 */
function serviceFor(Fishery $fishery, array $state = []): AdditionalService
{
    return AdditionalService::factory()->create(array_merge([
        'fishery_id' => $fishery->id,
        'is_active' => true,
        'scope' => ServiceScope::SelectedPositions->value,
        'price' => '20.00',
    ], $state));
}

function pinService(AdditionalService $service, Position $position, bool $required = false): void
{
    $position->additionalServices()->attach($service->id, ['is_required' => $required]);
}

function setFlag(Position $position, PositionAttribute $attribute, bool $value): void
{
    PositionAttributeValue::query()->create([
        'position_id' => $position->id,
        'position_attribute_id' => $attribute->id,
        'value_flag' => $value,
    ]);
}

/**
 * @param  array<int, PositionServiceStatus>  $statuses
 * @return array<int, string>
 */
function serviceNames(array $statuses): array
{
    return array_map(fn (PositionServiceStatus $status): string => $status->service->name, $statuses);
}

test('a whole-fishery service is offered on every position, also one added later, and never required', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();
    serviceFor($fishery, ['name' => 'Drewno', 'scope' => ServiceScope::WholeFishery->value]);
    $later = Position::factory()->create(['fishery_id' => $fishery->id]);

    $services = new PositionServices($fishery->fresh());

    foreach ([$position, $later] as $each) {
        $statuses = $services->forNight($each, '2026-06-10');

        expect(serviceNames($statuses))->toBe(['Drewno'])
            ->and($statuses[0]->isRequired)->toBeFalse()
            ->and($statuses[0]->isAvailable())->toBeTrue();
    }
});

test('a selected-positions service without pins is offered nowhere', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();
    serviceFor($fishery, ['name' => 'Lodka']);

    expect((new PositionServices($fishery->fresh()))->forNight($position, '2026-06-10'))->toBe([]);
});

test('pinned services list required ones first, then alphabetically, and skip inactive ones', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();
    pinService(serviceFor($fishery, ['name' => 'Zestaw grill']), $position);
    pinService(serviceFor($fishery, ['name' => 'Hamak']), $position);
    pinService(serviceFor($fishery, ['name' => 'Wywozka pontonem']), $position, required: true);
    pinService(serviceFor($fishery, ['name' => 'Ukryta', 'is_active' => false]), $position);

    $statuses = (new PositionServices($fishery->fresh()))->forNight($position, '2026-06-10');

    expect(serviceNames($statuses))->toBe(['Wywozka pontonem', 'Hamak', 'Zestaw grill'])
        ->and($statuses[0]->isRequired)->toBeTrue()
        ->and($statuses[1]->isRequired)->toBeFalse();
});

test('a required attribute set to no, or not specified at all, makes the service unavailable', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();
    $noValue = Position::factory()->create(['fishery_id' => $fishery->id]);
    $withYes = Position::factory()->create(['fishery_id' => $fishery->id]);
    $vehicle = PositionAttribute::factory()->create(['name' => 'Wjazd pojazdem']);
    $trailer = serviceFor($fishery, ['name' => 'Przyczepa']);
    $trailer->requiredAttributes()->attach($vehicle->id);

    foreach ([$position, $noValue, $withYes] as $each) {
        pinService($trailer, $each);
    }

    setFlag($position, $vehicle, false);
    setFlag($withYes, $vehicle, true);

    $services = new PositionServices($fishery->fresh());
    $no = $services->forNight($position, '2026-06-10')[0];
    $missing = $services->forNight($noValue, '2026-06-10')[0];
    $yes = $services->forNight($withYes, '2026-06-10')[0];

    expect($no->isAvailable())->toBeFalse()
        ->and($no->problems[0]->reason)->toBe(ServiceUnavailabilityReason::AttributeAbsent)
        ->and($missing->isAvailable())->toBeFalse()
        ->and($missing->problems[0]->reason)->toBe(ServiceUnavailabilityReason::AttributeNotSpecified)
        ->and($yes->isAvailable())->toBeTrue();
});

/**
 * ⚠️ Przypadek Klasztornego: wjazd pojazdem zawieszony od 01.06 wyłącza przyczepę w tych dobach —
 * i tylko w nich. Przyczyna niesie wpis ograniczenia, czyli jego powód i daty.
 */
test('a suspension of the required attribute makes the service unavailable only in its nights, with its dates', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();
    $vehicle = PositionAttribute::factory()->create(['name' => 'Wjazd pojazdem']);
    $trailer = serviceFor($fishery, ['name' => 'Przyczepa']);
    $trailer->requiredAttributes()->attach($vehicle->id);
    pinService($trailer, $position);
    setFlag($position, $vehicle, true);

    $block = AvailabilityBlock::factory()->suspending($vehicle)->create([
        'fishery_id' => $fishery->id,
        'starts_on' => '2026-06-01',
        'ends_on' => '2026-06-30',
        'reason' => 'Remont drogi',
    ]);
    $block->positions()->attach($position->id);

    $services = new PositionServices($fishery->fresh());
    $before = $services->forNights($position, '2026-05-20', '2026-05-30')[0];
    $window = $services->forNights($position, '2026-05-25', '2026-06-05')[0];

    expect($before->isAvailable())->toBeTrue()
        ->and($window->isAvailable())->toBeFalse()
        ->and($window->problems[0]->reason)->toBe(ServiceUnavailabilityReason::AttributeSuspended)
        ->and($window->problems[0]->suspension?->id)->toBe($block->id)
        ->and($window->problems[0]->description())->toContain('Wjazd pojazdem')
        ->and($window->problems[0]->description())->toContain('01.06')
        ->and($window->problems[0]->description())->toContain('30.06')
        ->and($window->problems[0]->description())->toContain('Remont drogi');
});

/**
 * ⚠️ Usunięcie cechy ze słownika nie może wyłączyć usługi na całym łowisku — a `restore()` cechy
 * ma przywrócić też wymóg, bo wiersz pośredni zostaje.
 */
test('a requirement of a soft-deleted attribute is ignored and comes back with restore', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();
    $vehicle = PositionAttribute::factory()->create(['name' => 'Wjazd pojazdem']);
    $trailer = serviceFor($fishery, ['name' => 'Przyczepa']);
    $trailer->requiredAttributes()->attach($vehicle->id);
    pinService($trailer, $position);

    $vehicle->delete();

    expect((new PositionServices($fishery->fresh()))->forNight($position, '2026-06-10')[0]->isAvailable())->toBeTrue();

    $vehicle->restore();

    expect((new PositionServices($fishery->fresh()))->forNight($position, '2026-06-10')[0]->isAvailable())->toBeFalse();
});

test('a position of another fishery is a calling error, not an empty list', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    [, $foreign] = StayFixtures::fisheryWithPosition();

    expect(fn () => (new PositionServices($fishery->fresh()))->forNight($foreign, '2026-06-10'))
        ->toThrow(\InvalidArgumentException::class);
});

/**
 * ⚠️ Usługi, przypięcia i wartości cech wczytuje się RAZ na instancję — kalendarz pyta o każde
 * stanowisko łowiska w jednym renderze.
 */
test('services, pins and attribute values are read once for all positions', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();
    $others = Position::factory()->count(3)->create(['fishery_id' => $fishery->id]);
    $vehicle = PositionAttribute::factory()->create();

    foreach ([$position, ...$others] as $each) {
        setFlag($each, $vehicle, true);
    }

    foreach (range(1, 3) as $i) {
        $service = serviceFor($fishery, ['name' => 'Usluga '.$i]);
        $service->requiredAttributes()->attach($vehicle->id);

        foreach ([$position, ...$others] as $each) {
            pinService($service, $each);
        }
    }

    $services = new PositionServices($fishery->fresh());
    $configQueries = 0;

    DB::listen(function ($query) use (&$configQueries): void {
        foreach (['from `additional_services`', 'from `additional_service_position`', 'from `position_attribute_values`', 'additional_service_required_attribute'] as $table) {
            if (str_contains($query->sql, $table)) {
                $configQueries++;
            }
        }
    });

    foreach ([$position, ...$others] as $each) {
        $each->setRelation('fishery', $fishery);
        expect($services->forNights($each, '2026-06-01', '2026-06-30'))->toHaveCount(3);
    }

    // Usługi + ich wymagane cechy (eager) + przypięcia + wartości cech — niezależnie od liczby
    // stanowisk, usług i dób.
    expect($configQueries)->toBe(4);
});

test('the availability lists the entries suspending attributes in a night or a range of nights', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();
    $jetty = PositionAttribute::factory()->create(['name' => 'Pomost']);

    $june = AvailabilityBlock::factory()->suspending($jetty)->create([
        'fishery_id' => $fishery->id, 'starts_on' => '2026-06-10', 'ends_on' => '2026-06-12',
    ]);
    $july = AvailabilityBlock::factory()->suspending($jetty)->create([
        'fishery_id' => $fishery->id, 'starts_on' => '2026-07-01', 'ends_on' => null,
    ]);
    $june->positions()->attach($position->id);
    $july->positions()->attach($position->id);

    $availability = new PositionAvailability($position->fresh());

    expect($availability->attributeSuspensions('2026-06-09')->pluck('id')->all())->toBe([$june->id])
        ->and($availability->attributeSuspensions('2026-06-13')->all())->toBe([])
        ->and($availability->attributeSuspensions('2026-06-01', '2026-07-05')->pluck('id')->sort()->values()->all())
        ->toBe(collect([$june->id, $july->id])->sort()->values()->all())
        // Stare pytanie o same cechy zostaje bez zmian.
        ->and($availability->suspendedAttributes('2026-06-10')->pluck('id')->all())->toBe([$jetty->id]);
});
