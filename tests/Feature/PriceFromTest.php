<?php

namespace Tests\Feature;

use App\Models\Fishery;
use App\Models\PriceRule;
use App\Models\SalePeriod;
use App\Services\PriceFrom;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Tests\Support\StayFixtures;

/**
 * „Cena od" — najniższa stawka za łowiącego za dobę od dziś do końca trwającego albo najbliższego
 * okresu sprzedaży (zadanie 032, portal-v3 §4, Rozstrzygnięcia 1–2).
 *
 * ⚠️ Czas zamrożony: 04.05.2026. `StayFixtures::fisheryWithPosition()` daje dobę 15:00 → 15:00
 * i okres sprzedaży na cały 2026 rok.
 */
beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-05-04 09:00', 'Europe/Warsaw'));
});

afterEach(function () {
    Date::setTestNow();
});

function priceFromOf(Fishery $fishery): ?int
{
    return (new PriceFrom($fishery->fresh()))->amountInCents();
}

test('the lowest rate wins among several rates in the season', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 90.00);
    StayFixtures::rate($fishery, 130.00, ['first_day_on' => '2026-06-01', 'last_day_on' => '2026-06-30']);
    StayFixtures::rate($fishery, 70.00, ['first_day_on' => '2026-08-01', 'last_day_on' => '2026-08-31']);

    expect(priceFromOf($fishery))->toBe(7000);
});

test('a suspended or deleted rate does not count', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 90.00);
    StayFixtures::rate($fishery, 40.00, ['is_suspended' => true]);
    StayFixtures::rate($fishery, 30.00)->delete();

    expect(priceFromOf($fishery))->toBe(9000);
});

test('a rate valid only in the past part of the season does not count', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 50.00, ['first_day_on' => '2026-03-01', 'last_day_on' => '2026-04-30']);
    StayFixtures::rate($fishery, 90.00, ['first_day_on' => '2026-05-01']);

    expect(priceFromOf($fishery))->toBe(9000);
});

test('outside a season the upcoming season is used', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    $fishery->salePeriods()->delete();
    SalePeriod::factory()->create(['fishery_id' => $fishery->id, 'starts_on' => '2026-01-01', 'ends_on' => '2026-04-30']);
    SalePeriod::factory()->create(['fishery_id' => $fishery->id, 'starts_on' => '2026-06-01', 'ends_on' => '2026-09-30']);
    StayFixtures::rate($fishery, 40.00, ['first_day_on' => '2026-01-01', 'last_day_on' => '2026-04-30']);
    StayFixtures::rate($fishery, 80.00, ['first_day_on' => '2026-05-01']);

    expect(priceFromOf($fishery))->toBe(8000);
});

test('a later season does not lower the price of the current one', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    $fishery->salePeriods()->delete();
    SalePeriod::factory()->create(['fishery_id' => $fishery->id, 'starts_on' => '2026-05-01', 'ends_on' => '2026-06-30']);
    SalePeriod::factory()->create(['fishery_id' => $fishery->id, 'starts_on' => '2026-08-01', 'ends_on' => '2026-08-31']);
    StayFixtures::rate($fishery, 90.00, ['first_day_on' => '2026-05-01', 'last_day_on' => '2026-06-30']);
    StayFixtures::rate($fishery, 50.00, ['first_day_on' => '2026-08-01', 'last_day_on' => '2026-08-31']);

    expect(priceFromOf($fishery))->toBe(9000);
});

test('surcharges and the companion amount do not change the price from', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00, ['amount_companion' => 35.00]);
    StayFixtures::surcharge($fishery, 20.00);

    expect(priceFromOf($fishery))->toBe(7000);
});

test('without a rate, a sale period or fishing day hours the price list is in preparation', function () {
    [$noRate] = StayFixtures::fisheryWithPosition();
    expect(priceFromOf($noRate))->toBeNull();

    [$ended] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($ended, 70.00);
    $ended->salePeriods()->update(['ends_on' => '2026-04-30']);
    expect(priceFromOf($ended))->toBeNull();

    [$noDay] = StayFixtures::fisheryWithPosition(['day_start_time' => null]);
    StayFixtures::rate($noDay, 70.00);
    expect(priceFromOf($noDay))->toBeNull();
});

test('a rate starting after the season ends does not count', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 90.00, ['last_day_on' => '2026-12-31']);
    PriceRule::factory()->amount(10.00)->create(['fishery_id' => $fishery->id, 'first_day_on' => '2027-01-01']);

    expect(priceFromOf($fishery))->toBe(9000);
});
