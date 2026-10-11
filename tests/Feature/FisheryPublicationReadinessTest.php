<?php

namespace Tests\Feature;

use App\Models\Fishery;
use App\Services\FisheryPublicationReadiness;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Tests\Support\StayFixtures;

/**
 * Przypadki brzegowe `FisheryPublicationReadiness` wskazane przez testy mutacyjne (02.10.2026). Pełne scenariusze
 * publikacji są w `FisheryPublicationTest`.
 *
 * ⚠️ Czas zamrożony: pon 04.05.2026, sezon na cały 2026, stawka do 31.08.2026 — luka w cenniku od 01.09.2026.
 */
beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-05-04 09:00', 'Europe/Warsaw'));
});

afterEach(function () {
    Date::setTestNow();
});

function readinessFisheryWithGap(): Fishery
{
    [$fishery] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00, ['last_day_on' => '2026-08-31']);

    return $fishery->fresh();
}

test('the pricing gap is known without asking for the issues first', function () {
    $readiness = new FisheryPublicationReadiness(readinessFisheryWithGap());

    expect($readiness->pricingGap()?->toDateString())->toBe('2026-09-01');
});

test('the issues are detected once per instance', function () {
    $readiness = new FisheryPublicationReadiness(readinessFisheryWithGap());
    $readiness->issues();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $readiness->issues();
    $readiness->blocksPublication();
    $readiness->pricingGap();

    expect(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
});
