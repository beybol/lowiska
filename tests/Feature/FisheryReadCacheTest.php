<?php

namespace Tests\Feature;

use App\Enums\BlockEffect;
use App\Enums\BookingProblem;
use App\Enums\CalendarWindow;
use App\Enums\SaleUnavailabilityReason;
use App\Models\Position;
use App\Models\SalePeriod;
use App\Services\AvailabilityBlockWriter;
use App\Services\FisheryReadCache;
use App\Services\SaleCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReservationFixtures;
use Tests\Support\StayFixtures;

/**
 * Magazyn odczytów dostępności na jedno żądanie (ADR-029, zadanie 050): leniwość, jeden odczyt na łowisko,
 * unieważnianie i świeży odczyt w zapisie rezerwacji.
 */
beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-05-13 12:00', 'Europe/Warsaw'));
});

afterEach(function () {
    Date::setTestNow();
});

/** Liczba zapytań wykonanych przez domknięcie. */
function queriesDuring(callable $callback): int
{
    $count = 0;
    DB::listen(function () use (&$count): void {
        $count++;
    });
    $callback();
    $result = $count;
    $count = PHP_INT_MIN; // kolejne nasłuchy z tego testu nie liczą się do wyniku

    return $result;
}

test('the store loads lazily: creating it runs no query and each collection is read once per fishery', function () {
    [$fishery, $position] = ReservationFixtures::bookableFishery();
    StayFixtures::wholeTerm($fishery, '2026-06-01', 2);
    app()->forgetScopedInstances();

    expect(queriesDuring(fn () => FisheryReadCache::instance()))->toBe(0)
        ->and(queriesDuring(fn () => FisheryReadCache::instance()->salePeriods($fishery)))->toBe(1)
        ->and(queriesDuring(fn () => FisheryReadCache::instance()->salePeriods($fishery)))->toBe(0)
        ->and(queriesDuring(fn () => FisheryReadCache::instance()->wholeTermPeriods($fishery)))->toBe(1)
        ->and(FisheryReadCache::instance()->wholeTermPeriods($fishery))->toHaveCount(1);
});

test('blocks of all positions come from one query, grouped by position and effect', function () {
    [$fishery, $position] = ReservationFixtures::bookableFishery();
    $other = Position::factory()->create(['fishery_id' => $fishery->id, 'status' => 'available']);
    $block = StayFixtures::blockSale($fishery, $position, '2026-05-20', '2026-05-21');
    app()->forgetScopedInstances();

    $queries = queriesDuring(function () use ($position, $other): void {
        FisheryReadCache::instance()->blocksFor($position, BlockEffect::SaleBlocked);
        FisheryReadCache::instance()->blocksFor($other, BlockEffect::SaleBlocked);
        FisheryReadCache::instance()->blocksFor($position, BlockEffect::AttributeSuspended);
    });

    expect($queries)->toBe(2) // blokady + stanowiska z tabeli łączącej, raz dla całego łowiska (blokada bez cechy)
        ->and(FisheryReadCache::instance()->blocksFor($position, BlockEffect::SaleBlocked)->modelKeys())->toBe([$block->id])
        ->and(FisheryReadCache::instance()->blocksFor($other, BlockEffect::SaleBlocked))->toBeEmpty()
        ->and(FisheryReadCache::instance()->blocksFor($position, BlockEffect::AttributeSuspended))->toBeEmpty();
});

test('saving a sale period, a block or the positions of a block invalidates the store', function () {
    [$fishery, $position] = ReservationFixtures::bookableFishery();
    $store = FisheryReadCache::instance();

    expect($store->salePeriods($fishery))->toHaveCount(1);
    SalePeriod::factory()->create(['fishery_id' => $fishery->id, 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31']);
    expect($store->salePeriods($fishery))->toHaveCount(2);

    // Skrót „Zablokuj…" kalendarza: model zapisany PRZED przypisaniem stanowiska (attach na tabeli łączącej).
    expect($store->blocksFor($position, BlockEffect::SaleBlocked))->toBeEmpty();
    AvailabilityBlockWriter::blockPosition($fishery, $position, '2026-05-20', '2026-05-20', 'Zawody', true);
    expect($store->blocksFor($position, BlockEffect::SaleBlocked))->toHaveCount(1);

    $block = $store->blocksFor($position, BlockEffect::SaleBlocked)->first();
    $block->positions()->detach($position->id);
    expect($store->blocksFor($position, BlockEffect::SaleBlocked))->toBeEmpty();
});

test('the offer grid query count does not grow with positions', function () {
    [$fishery] = ReservationFixtures::bookableFishery();
    $count = function () use ($fishery): int {
        app()->forgetScopedInstances(); // nowe żądanie

        return queriesDuring(fn () => (new SaleCalendar($fishery->fresh()))->grid(CarbonImmutable::parse('2026-05-11'), CalendarWindow::TwoWeeks));
    };

    Position::factory()->create(['fishery_id' => $fishery->id, 'status' => 'available']);
    $two = $count();
    Position::factory()->count(4)->create(['fishery_id' => $fishery->id, 'status' => 'available']);
    $six = $count();

    expect($two)->toBeGreaterThan(0)
        ->and($six)->toBe($two);
});

test('booking reads fresh: a block added after the grid was built is seen by the check in the transaction', function () {
    [$fishery, $position] = ReservationFixtures::bookableFishery();
    $block = StayFixtures::blockSale($fishery, $position, '2026-05-20', '2026-05-20');
    $block->positions()->detach($position->id);

    // Siatka w tym samym żądaniu wczytała blokady łowiska (bez blokady na tym stanowisku)…
    (new SaleCalendar($fishery->fresh()))->grid(CarbonImmutable::parse('2026-05-11'), CalendarWindow::TwoWeeks);
    expect(FisheryReadCache::instance()->blocksFor($position, BlockEffect::SaleBlocked))->toBeEmpty();

    // …a potem blokada objęła stanowisko z pominięciem zdarzeń modeli (magazyn o tym nie wie).
    DB::table('availability_block_position')->insert(['availability_block_id' => $block->id, 'position_id' => $position->id]);
    expect(FisheryReadCache::instance()->blocksFor($position, BlockEffect::SaleBlocked))->toBeEmpty();

    $outcome = ReservationFixtures::attempt($position, '2026-05-20');

    expect($outcome->succeeded)->toBeFalse()
        ->and($outcome->problem)->toBe(BookingProblem::ExceptionReasonRequired)
        ->and($outcome->reason)->toBe(SaleUnavailabilityReason::SaleBlocked);
});
