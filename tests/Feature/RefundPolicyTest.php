<?php

namespace Tests\Feature;

use App\Enums\RefundOutcome;
use App\Models\Fishery;
use App\Rules\RefundTiersAreValid;
use App\Services\RefundPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Tests\Support\StayFixtures;

/**
 * Polityka zwrotu — „jaki procent przy odwołaniu w chwili D pobytu od doby S" (zadanie 021).
 *
 * ⚠️ Kalendarz odniesienia: pobyt od 10.07.2026 (piątek), doba łowiska 15:00 → 15:00, Warszawa.
 */
function refundFishery(?array $tiers, array $attributes = []): Fishery
{
    [$fishery] = StayFixtures::fisheryWithPosition(array_merge(['refund_policy' => $tiers], $attributes));

    return $fishery->fresh();
}

function cancelledAt(string $moment, string $timezone = 'Europe/Warsaw'): CarbonImmutable
{
    return CarbonImmutable::parse($moment, $timezone);
}

test('the tier boundary is closed — exactly N days before still gets that tier', function () {
    $policy = new RefundPolicy(refundFishery([['days' => 7, 'percent' => 100], ['days' => 3, 'percent' => 50]]));

    $exactly = $policy->forCancellation('2026-07-10', cancelledAt('2026-07-03 23:59'));
    $dayLater = $policy->forCancellation('2026-07-10', cancelledAt('2026-07-04 00:01'));

    expect($exactly->outcome)->toBe(RefundOutcome::Refund)
        ->and($exactly->percent)->toBe(100)
        ->and($exactly->daysBefore)->toBe(7)
        ->and($dayLater->percent)->toBe(50);
});

test('cancelling earlier than the farthest tier gets the farthest tier', function () {
    $policy = new RefundPolicy(refundFishery([['days' => 7, 'percent' => 100], ['days' => 3, 'percent' => 50]]));

    expect($policy->forCancellation('2026-07-10', cancelledAt('2026-05-01 12:00'))->percent)->toBe(100);
});

test('cancelling closer than the nearest tier refunds nothing', function () {
    $policy = new RefundPolicy(refundFishery([['days' => 7, 'percent' => 100], ['days' => 3, 'percent' => 50]]));

    $decision = $policy->forCancellation('2026-07-10', cancelledAt('2026-07-08 10:00'));

    expect($decision->outcome)->toBe(RefundOutcome::Refund)
        ->and($decision->percent)->toBe(0)
        ->and($decision->daysBefore)->toBe(2);
});

test('a zero-day tier refunds until the first night starts, and not a minute later', function () {
    $policy = new RefundPolicy(refundFishery([['days' => 0, 'percent' => 30]]));

    $sameDayBefore = $policy->forCancellation('2026-07-10', cancelledAt('2026-07-10 14:59'));
    $afterStart = $policy->forCancellation('2026-07-10', cancelledAt('2026-07-10 15:00'));

    expect($sameDayBefore->percent)->toBe(30)
        ->and($afterStart->outcome)->toBe(RefundOutcome::StayStarted)
        ->and($afterStart->percent)->toBeNull();
});

test('a fishery without tiers has its policy not set, which is neither 0% nor 100%', function () {
    $decision = (new RefundPolicy(refundFishery(null)))->forCancellation('2026-07-10', cancelledAt('2026-07-01 10:00'));

    expect($decision->outcome)->toBe(RefundOutcome::PolicyNotSet)
        ->and($decision->percent)->toBeNull()
        ->and((new RefundPolicy(refundFishery([])))->isSet())->toBeFalse();
});

/**
 * ⚠️ „N dni przed" to daty w strefie ŁOWISKA: 03.07 23:30 w Warszawie to jeszcze 7 dni przed
 * pobytem, choć w UTC jest już 03.07 21:30 — a 04.07 00:30 w Warszawie to już 6 dni, choć w UTC
 * jest wciąż 03.07.
 */
test('the days are counted as dates in the fishery time zone', function () {
    $policy = new RefundPolicy(refundFishery([['days' => 7, 'percent' => 100]]));

    expect($policy->forCancellation('2026-07-10', cancelledAt('2026-07-03 21:30', 'UTC'))->percent)->toBe(100)
        ->and($policy->forCancellation('2026-07-10', cancelledAt('2026-07-03 22:30', 'UTC'))->percent)->toBe(0);
});

test('a day across the clock change still counts as one day', function () {
    // 25.10.2026 — zmiana czasu w Polsce; doba ma 25 godzin.
    $policy = new RefundPolicy(refundFishery([['days' => 1, 'percent' => 100]]));

    expect($policy->forCancellation('2026-10-26', cancelledAt('2026-10-25 00:10'))->daysBefore)->toBe(1)
        ->and($policy->forCancellation('2026-03-30', cancelledAt('2026-03-29 00:10'))->daysBefore)->toBe(1);
});

test('the tiers are read farthest first, whatever order they were saved in', function () {
    $policy = new RefundPolicy(refundFishery([['days' => 3, 'percent' => 50], ['days' => 7, 'percent' => 100]]));

    expect($policy->tiers())->toBe([['days' => 7, 'percent' => 100], ['days' => 3, 'percent' => 50]]);
});

test('a policy that never refunds anything is recognised', function () {
    expect((new RefundPolicy(refundFishery([['days' => 7, 'percent' => 0]])))->refundsNothing())->toBeTrue()
        ->and((new RefundPolicy(refundFishery([['days' => 7, 'percent' => 10], ['days' => 0, 'percent' => 0]])))->refundsNothing())->toBeFalse()
        ->and((new RefundPolicy(refundFishery(null)))->refundsNothing())->toBeFalse();
});

test('the tier rule refuses duplicates, fractions, out-of-range values and a refund growing towards the stay', function () {
    $fails = fn (array $tiers): bool => Validator::make(['tiers' => $tiers], ['tiers' => [new RefundTiersAreValid]])->fails();

    expect($fails([['days' => 7, 'percent' => 100], ['days' => 3, 'percent' => 50]]))->toBeFalse()
        ->and($fails([['days' => 7, 'percent' => 0]]))->toBeFalse()
        ->and($fails([['days' => 7, 'percent' => 100], ['days' => 7, 'percent' => 50]]))->toBeTrue()
        ->and($fails([['days' => '7.5', 'percent' => 100]]))->toBeTrue()
        ->and($fails([['days' => 366, 'percent' => 100]]))->toBeTrue()
        ->and($fails([['days' => 7, 'percent' => 101]]))->toBeTrue()
        ->and($fails([['days' => 7, 'percent' => 50], ['days' => 3, 'percent' => 100]]))->toBeTrue();
});
