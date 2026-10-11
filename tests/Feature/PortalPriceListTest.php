<?php

namespace Tests\Feature;

use App\Enums\PositionStatus;
use App\Enums\ServiceScope;
use App\Enums\SurchargeAudience;
use App\Models\AdditionalService;
use App\Models\Fishery;
use App\Models\Position;
use App\Models\PositionAttribute;
use App\Models\SalePeriod;
use App\Services\AmountFormatter;
use App\Services\FisheryRulesSummary;
use App\Services\PortalPriceList;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Tests\Support\StayFixtures;

/**
 * Kolejność i granice danych zakładki „Cennik" wskazane przez testy mutacyjne (02.10.2026) — sprawdzane wprost
 * na `PortalPriceList`, bo HTML strony nie pokazuje kolejności ani tego, skąd przyszła przedsprzedaż.
 * Scenariusze widoku są w `FisheryPricingTabTest`.
 *
 * ⚠️ Czas zamrożony: pon 04.05.2026.
 */
beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-05-04 09:00', 'Europe/Warsaw'));
    app()->setLocale('pl');
});

afterEach(function () {
    Date::setTestNow();
});

/** Łowisko z jednym stanowiskiem „1" w sprzedaży. */
function priceListFishery(): Fishery
{
    [$fishery, $position] = StayFixtures::fisheryWithPosition();
    $position->update(['name' => '1']);

    return $fishery->fresh();
}

function priceListOf(Fishery $fishery): PortalPriceList
{
    return new PortalPriceList($fishery->fresh());
}

function priceListNight(int $cents, Fishery $fishery): string
{
    return AmountFormatter::forVisitor($cents, $fishery->currency?->name).' / '.__('night');
}

test('rates go from the earliest period, the open start first and the cheaper first within one start', function () {
    $fishery = priceListFishery();
    // Tworzone w innej kolejności niż oczekiwana — kolejność z bazy niczego nie przesądza.
    StayFixtures::rate($fishery, 110.00, ['first_day_on' => '2026-06-01', 'last_day_on' => '2026-12-31']);
    StayFixtures::rate($fishery, 90.00, ['first_day_on' => '2026-06-01', 'last_day_on' => '2026-06-30']);
    StayFixtures::rate($fishery, 120.00, ['first_day_on' => null, 'last_day_on' => '2026-05-31']);
    // Zaczyna się DZIŚ — to nie jest „kolejny okres".
    StayFixtures::rate($fishery, 80.00, ['first_day_on' => '2026-05-04', 'last_day_on' => '2026-05-04']);

    $rates = priceListOf($fishery)->rates();

    expect(array_column($rates, 'amount'))->toBe([
        priceListNight(12000, $fishery),
        priceListNight(8000, $fishery),
        priceListNight(9000, $fishery),
        priceListNight(11000, $fishery),
    ])->and(array_column($rates, 'upcoming'))->toBe([false, false, true, true]);
});

/**
 * ⚠️ Dwie stawki bez daty początku i usługi obowiązkowe o nazwach PÓŹNIEJSZYCH w alfabecie niż opcjonalne —
 * tylko tak porównanie w sortowaniu dostaje stawkę bez daty po obu stronach i nie da się „wygrać" samą nazwą.
 */
test('two rates without a start date go by price, ahead of the dated ones', function () {
    $fishery = priceListFishery();
    StayFixtures::rate($fishery, 95.00, ['first_day_on' => '2026-06-01', 'last_day_on' => '2026-12-31']);
    StayFixtures::rate($fishery, 120.00, ['first_day_on' => null, 'last_day_on' => '2026-05-31']);
    StayFixtures::rate($fishery, 100.00, ['first_day_on' => null, 'last_day_on' => '2026-05-10']);

    expect(array_column(priceListOf($fishery)->rates(), 'amount'))->toBe([
        priceListNight(10000, $fishery),
        priceListNight(12000, $fishery),
        priceListNight(9500, $fishery),
    ]);
});

test('a required service goes first even when its name comes later in the alphabet', function () {
    $fishery = priceListFishery();
    $position = $fishery->positions()->firstOrFail();
    $service = fn (string $name, bool $required) => tap(AdditionalService::factory()->create([
        'fishery_id' => $fishery->id, 'name' => $name, 'price' => 5.00, 'is_active' => true,
        'scope' => $required ? ServiceScope::SelectedPositions : ServiceScope::WholeFishery,
    ]), fn (AdditionalService $created) => $required ? $created->positions()->attach($position->id, ['is_required' => true]) : null);

    $service('Beta opcjonalna', false);
    $service('Zeta wymagana', true);
    $service('alfa opcjonalna', false);
    $service('yeti wymagana', true);

    expect(array_column(priceListOf($fishery)->services(), 'name'))
        ->toBe(['yeti wymagana', 'Zeta wymagana', 'alfa opcjonalna', 'Beta opcjonalna']);
});

test('surcharges go alphabetically regardless of letter case, one ending today is still shown', function () {
    $fishery = priceListFishery();
    StayFixtures::surcharge($fishery, 10.00, 'zeta', ['first_day_on' => '2026-01-01', 'last_day_on' => '2026-05-04']);
    StayFixtures::surcharge($fishery, 10.00, 'Beta');
    StayFixtures::surcharge($fishery, 10.00, 'alfa', ['applies_to' => SurchargeAudience::Companion]);

    $surcharges = priceListOf($fishery)->surcharges();

    expect(array_column($surcharges, 'name'))->toBe(['alfa', 'Beta', 'zeta'])
        ->and($surcharges[0]['audience'])->toBe(SurchargeAudience::Companion->label())
        ->and($surcharges[1]['audience'])->toBe(SurchargeAudience::Angler->label());
});

test('the presale comes from the rules summary, also when the page shares its own', function () {
    $withPresale = priceListFishery();
    SalePeriod::factory()->create([
        'fishery_id' => $withPresale->id,
        'starts_on' => '2027-01-01',
        'ends_on' => '2027-12-31',
        'presale_opens_on' => '2026-04-01',
        'presale_closes_on' => '2026-06-30',
        'presale_discount_percent' => 10,
    ]);
    $plain = priceListFishery();

    expect(priceListOf($withPresale)->presale())->toContain('−10%')
        ->and(priceListOf($plain)->presale())->toBeNull()
        // Wyciąg podany przez stronę jest używany zamiast liczenia własnego.
        ->and((new PortalPriceList($plain->fresh(), new FisheryRulesSummary($withPresale->fresh())))->presale())->toContain('−10%');
});

test('required services go first, each group alphabetically regardless of letter case', function () {
    $fishery = priceListFishery();
    $position = $fishery->positions()->firstOrFail();

    foreach (['beta wymagana', 'Alfa wymagana'] as $name) {
        AdditionalService::factory()->create([
            'fishery_id' => $fishery->id, 'name' => $name, 'price' => 5.00, 'is_active' => true,
            'scope' => ServiceScope::SelectedPositions,
        ])->positions()->attach($position->id, ['is_required' => true]);
    }
    foreach (['Delta opcjonalna', 'celina opcjonalna'] as $name) {
        AdditionalService::factory()->create([
            'fishery_id' => $fishery->id, 'name' => $name, 'price' => 5.00, 'is_active' => true,
            'scope' => ServiceScope::WholeFishery,
        ]);
    }

    expect(array_column(priceListOf($fishery)->services(), 'name'))
        ->toBe(['Alfa wymagana', 'beta wymagana', 'celina opcjonalna', 'Delta opcjonalna']);
});

test('required features and position labels are sorted naturally regardless of letter case', function () {
    $fishery = priceListFishery();
    $ten = Position::factory()->create(['fishery_id' => $fishery->id, 'name' => '10', 'status' => PositionStatus::Available]);
    $two = Position::factory()->create(['fishery_id' => $fishery->id, 'name' => '2', 'status' => PositionStatus::Available]);

    $service = AdditionalService::factory()->create([
        'fishery_id' => $fishery->id, 'name' => 'Prąd', 'price' => 5.00, 'is_active' => true,
        'scope' => ServiceScope::SelectedPositions,
    ]);
    $service->positions()->attach([$ten->id => ['is_required' => false], $two->id => ['is_required' => false]]);
    $service->requiredAttributes()->attach([
        PositionAttribute::factory()->create(['name' => 'Beta'])->id,
        PositionAttribute::factory()->create(['name' => 'alfa'])->id,
    ]);

    $item = priceListOf($fishery)->services()[0];

    expect($item['attributes'])->toBe(['alfa', 'Beta'])
        ->and($item['scope'])->toBe(__('positions :labels', ['labels' => '2, 10']));
});

test('the services add no queries per service', function () {
    $count = function (int $services): int {
        $fishery = priceListFishery();

        for ($i = 0; $i < $services; $i++) {
            AdditionalService::factory()->create([
                'fishery_id' => $fishery->id, 'name' => "Usługa {$i}", 'price' => 5.00, 'is_active' => true,
                'scope' => ServiceScope::WholeFishery,
            ])->requiredAttributes()->attach(PositionAttribute::factory()->create()->id);
        }

        $list = priceListOf($fishery);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $list->services();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    expect($count(3))->toBe($count(1));
});

test('the price list is read once for the rates and their dead-rate analysis', function () {
    $fishery = priceListFishery();
    StayFixtures::rate($fishery, 70.00);
    $list = priceListOf($fishery);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $list->rates();
    $list->surcharges();
    $queries = array_filter(DB::getQueryLog(), fn (array $query): bool => preg_match('/from [`"]price_rules[`"]/', $query['query']) === 1);
    DB::disableQueryLog();

    expect($queries)->toHaveCount(1);
});
