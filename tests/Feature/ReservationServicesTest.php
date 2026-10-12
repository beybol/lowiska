<?php

namespace Tests\Feature;

use App\Enums\BookingProblem;
use App\Enums\CancellationReason;
use App\Enums\ServiceBillingUnit;
use App\Models\AdditionalService;
use App\Models\Position;
use App\Services\AnglerDetails;
use App\Services\CancellationRequest;
use App\Services\ReservationBooking;
use App\Services\StayOffer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Tests\Support\ReservationFixtures;

/**
 * Wycena usług dodatkowych w warstwie oferty i limit egzemplarzy na ścieżce zapisu
 * (zadanie 039; ADR-025 pkt 7, ADR-026 pkt 9, aktualizacja ADR-015).
 */
beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-04-01 12:00', 'Europe/Warsaw'));
});

afterEach(function () {
    Date::setTestNow();
});

test('the offer layer prices services by their billing unit', function () {
    [$fishery, $position] = ReservationFixtures::bookableFishery();
    $boat = ReservationFixtures::service($fishery, $position, ['name' => 'Łódka', 'price' => '30.00', 'billing_unit' => ServiceBillingUnit::PerNight->value]);
    $pellet = ReservationFixtures::service($fishery, $position, ['name' => 'Pellet', 'price' => '15.00', 'billing_unit' => ServiceBillingUnit::PerStay->value]);

    $quote = (new StayOffer($position->fresh()))->services('2026-05-13', 3, [$boat->id => 2, $pellet->id => 1]);

    $amounts = collect($quote->chosen())->mapWithKeys(fn ($line) => [$line->service->name => $line->amountInCents()]);

    // Łódka: 30 zł × 3 doby × 2 szt.; pellet: 15 zł × 1 szt. za pobyt.
    expect($amounts->all())->toEqual(['Łódka' => 18000, 'Pellet' => 1500])
        ->and($quote->totalInCents())->toBe(19500);
});

test('a required service is quoted at least once without being asked for', function () {
    [$fishery, $position] = ReservationFixtures::bookableFishery();
    $fee = ReservationFixtures::service($fishery, $position, ['name' => 'Opłata klimatyczna', 'price' => '5.00', 'billing_unit' => ServiceBillingUnit::PerStay->value], required: true);

    $quote = (new StayOffer($position->fresh()))->services('2026-05-13', 2);

    expect($quote->chosen())->toHaveCount(1)
        ->and($quote->chosen()[0]->service->id)->toBe($fee->id)
        ->and($quote->chosen()[0]->quantity)->toBe(1);
});

test('services become part of the frozen terms and of the total', function () {
    [$fishery, $position] = ReservationFixtures::bookableFishery();
    $boat = ReservationFixtures::service($fishery, $position, ['name' => 'Łódka', 'price' => '30.00']);

    $reservation = ReservationFixtures::book($position, '2026-05-13', 2, ['services' => [$boat->id => 1]]);
    $boat->update(['price' => '99.00']);

    $terms = $reservation->terms->fresh();

    expect($terms->services_total_cents)->toBe(6000)
        ->and($terms->stay_total_cents)->toBe(14000)
        ->and($terms->total_cents)->toBe(20000)
        ->and($terms->services[0]['name'])->toBe('Łódka')
        ->and($terms->services[0]['unit_price_cents'])->toBe(3000);
});

test('the unit limit is checked per night across overlapping reservations', function () {
    [$fishery, $position] = ReservationFixtures::bookableFishery();
    $second = Position::factory()->create(['fishery_id' => $fishery->id, 'status' => 'available']);
    $boat = ReservationFixtures::service($fishery, $position, ['name' => 'Łódka', 'available_count' => 2]);
    $second->additionalServices()->attach($boat->id, ['is_required' => false]);

    ReservationFixtures::book($position, '2026-05-13', 2, ['services' => [$boat->id => 2]]);

    $overlapping = ReservationFixtures::attempt($second, '2026-05-14', 2, [
        'services' => [$boat->id => 1],
        'angler' => new AnglerDetails('Anna', 'anna@example.com'),
    ]);
    $after = ReservationFixtures::attempt($second, '2026-05-15', 1, [
        'services' => [$boat->id => 2],
        'angler' => new AnglerDetails('Anna', 'anna@example.com'),
    ]);

    expect($overlapping->problem)->toBe(BookingProblem::ServiceLimitExceeded)
        ->and($overlapping->serviceId)->toBe($boat->id)
        ->and($after->succeeded)->toBeTrue()
        ->and((new StayOffer($second->fresh()))->services('2026-05-13', 1)->lines[0]->remaining)->toBe(0);
});

test('cancellation gives the service units back', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    $boat = ReservationFixtures::service($fishery, $position, ['name' => 'Łódka', 'available_count' => 1]);
    $reservation = ReservationFixtures::book($position, '2026-05-13', 1, ['services' => [$boat->id => 1]]);

    expect((new StayOffer($position->fresh()))->services('2026-05-13', 1)->lines[0]->remaining)->toBe(0);

    (new ReservationBooking)->cancel($reservation, new CancellationRequest(CancellationReason::FisheryDecision, $owner));

    expect((new StayOffer($position->fresh()))->services('2026-05-13', 1)->lines[0]->remaining)->toBe(1);
});

// Usługa tego łowiska nieoferowana na stanowisku jest wyjątkiem z powodem (047, B6) — ReservationReviewFixesTest.
// Usługi spoza łowiska nie dodaje nikt, nawet obsługa z powodem.
test('a service from outside the fishery is refused even with a reason', function () {
    [, $position] = ReservationFixtures::bookableFishery();
    $elsewhere = AdditionalService::factory()->create(['is_active' => true]);

    $outcome = ReservationFixtures::attempt($position, '2026-05-13', 1, [
        'services' => [$elsewhere->id => 1],
        'serviceExceptionReason' => 'Stały klient',
    ]);

    expect($outcome->problem)->toBe(BookingProblem::ServiceUnavailable)
        ->and($outcome->serviceId)->toBe($elsewhere->id);
});
