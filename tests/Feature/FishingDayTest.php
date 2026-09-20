<?php

namespace Tests\Feature;

use App\Enums\SaleMode;
use App\Enums\SaleUnavailabilityReason;
use App\Models\Fishery;
use App\Models\SalePeriod;
use App\Services\FishingDayCalendar;
use Carbon\CarbonImmutable;

/**
 * Rdzeń zadania 015 i ADR-010: doba jako przedział dwóch momentów oraz dwie
 * celowo asymetryczne reguły granic.
 *
 * ⚠️ Przypadki graniczne sezonu (31.01 i 30.09) sprawdzane są **z powodem odmowy**,
 * nie samym „nie". Test zbiorczy przeszedłby także przy implementacji odrzucającej
 * obie doby z tego samego powodu — a wtedy komunikat dla wędkarza kłamie.
 */
function fisheryWithFishingDay(array $attributes = []): Fishery
{
    return Fishery::factory()->create(array_merge([
        'sale_mode' => SaleMode::DailyPeriod,
        'day_start_time' => '15:00:00',
        'day_end_time' => '15:00:00',
        'timezone' => 'Europe/Warsaw',
    ], $attributes));
}

test('a fishing day is an interval from the start hour to the end hour on the next day', function () {
    $calendar = new FishingDayCalendar(fisheryWithFishingDay());

    $day = $calendar->dayStartingOn('2026-06-10');

    expect($day->startsAt->toDateTimeString())->toBe('2026-06-10 15:00:00')
        ->and($day->endsAt->toDateTimeString())->toBe('2026-06-11 15:00:00')
        ->and($day->lengthInHours())->toBe(24.0);
});

test('a fishing day is computed in the fishery time zone, not the application one', function () {
    config(['app.timezone' => 'UTC']);
    date_default_timezone_set('UTC');

    $calendar = new FishingDayCalendar(fisheryWithFishingDay(['timezone' => 'Pacific/Auckland']));

    $day = $calendar->dayStartingOn('2026-06-10');

    expect($day->startsAt->timezoneName)->toBe('Pacific/Auckland')
        // 15:00 w Auckland to 03:00 UTC — gdyby liczenie szło strefą aplikacji,
        // ten moment wypadłby dwanaście godzin dalej.
        ->and($day->startsAt->utc()->toDateTimeString())->toBe('2026-06-10 03:00:00');
});

test('the day covering the spring clock change is one day of 23 hours', function () {
    // Zmiana czasu na letni: 29.03.2026, 02:00 -> 03:00 w strefie Europe/Warsaw.
    $calendar = new FishingDayCalendar(fisheryWithFishingDay());

    $day = $calendar->dayStartingOn('2026-03-28');

    expect($day->startsAt->toDateTimeString())->toBe('2026-03-28 15:00:00')
        ->and($day->endsAt->toDateTimeString())->toBe('2026-03-29 15:00:00')
        ->and($day->lengthInHours())->toBe(23.0);
});

test('the day covering the autumn clock change is one day of 25 hours', function () {
    // Zmiana czasu na zimowy: 25.10.2026, 03:00 -> 02:00.
    $calendar = new FishingDayCalendar(fisheryWithFishingDay());

    $day = $calendar->dayStartingOn('2026-10-24');

    expect($day->lengthInHours())->toBe(25.0);
});

test('a day is sellable only when it fits inside a sale period entirely', function () {
    $fishery = fisheryWithFishingDay();
    SalePeriod::factory()->create([
        'fishery_id' => $fishery->id,
        'starts_on' => '2026-02-01',
        'ends_on' => '2026-09-30',
    ]);

    $calendar = new FishingDayCalendar($fishery);

    expect($calendar->isSellable('2026-02-01'))->toBeTrue()
        ->and($calendar->isSellable('2026-09-29'))->toBeTrue()
        ->and($calendar->isSellable('2026-06-15'))->toBeTrue();
});

test('the boundary days are refused, each for a different reason', function () {
    $fishery = fisheryWithFishingDay();
    SalePeriod::factory()->create([
        'fishery_id' => $fishery->id,
        'starts_on' => '2026-02-01',
        'ends_on' => '2026-09-30',
    ]);

    $calendar = new FishingDayCalendar($fishery);

    $beforeSeason = $calendar->availability('2026-01-31');
    $lastDayOfSeason = $calendar->availability('2026-09-30');

    expect($beforeSeason->sellable)->toBeFalse()
        ->and($beforeSeason->reason)->toBe(SaleUnavailabilityReason::StartsBeforeSalePeriod)
        ->and($lastDayOfSeason->sellable)->toBeFalse()
        // Doba zaczyna się jeszcze w sezonie, ale kończy 01.10 o 15:00 — dlatego
        // ostatnie pozwolenie jednodobowe kupuje się na PRZEDOSTATNI dzień okresu.
        ->and($lastDayOfSeason->reason)->toBe(SaleUnavailabilityReason::EndsAfterSalePeriod);
});

test('a fishery without any sale period has no sellable day at all', function () {
    $calendar = new FishingDayCalendar(fisheryWithFishingDay());

    $availability = $calendar->availability('2026-06-15');

    expect($availability->sellable)->toBeFalse()
        ->and($availability->reason)->toBe(SaleUnavailabilityReason::NoSalePeriodDefined);
});

test('a fishery without a fishing day defined refuses with its own reason', function () {
    $fishery = Fishery::factory()->create([
        'day_start_time' => null,
        'day_end_time' => null,
    ]);

    $calendar = new FishingDayCalendar($fishery);

    expect($calendar->dayStartingOn('2026-06-15'))->toBeNull()
        ->and($calendar->availability('2026-06-15')->reason)
        ->toBe(SaleUnavailabilityReason::FishingDayNotConfigured);
});

test('a day falling between two sale periods is refused as outside every period', function () {
    $fishery = fisheryWithFishingDay();
    SalePeriod::factory()->create([
        'fishery_id' => $fishery->id,
        'starts_on' => '2026-02-01',
        'ends_on' => '2026-03-31',
    ]);
    SalePeriod::factory()->create([
        'fishery_id' => $fishery->id,
        'starts_on' => '2026-06-01',
        'ends_on' => '2026-09-30',
    ]);

    $calendar = new FishingDayCalendar($fishery);

    expect($calendar->availability('2026-05-10')->reason)
        ->toBe(SaleUnavailabilityReason::OutsideSalePeriod);
});

test('the restriction rule is intersection, not containment', function () {
    $calendar = new FishingDayCalendar(fisheryWithFishingDay());

    $day = $calendar->dayStartingOn('2026-09-30');

    $restrictionStart = CarbonImmutable::parse('2026-10-01 00:00:00', 'Europe/Warsaw');
    $restrictionEnd = CarbonImmutable::parse('2026-10-01 23:59:59', 'Europe/Warsaw');

    // Doba 30.09 15:00 -> 01.10 15:00 wchodzi w ograniczenie z 01.10 tylko częścią,
    // a mimo to jest nim objęta. Na tym polega asymetria wobec reguły sprzedaży:
    // gdyby tu obowiązywało zawieranie, dałoby się sprzedać dobę wchodzącą w zakaz.
    expect($day->overlaps($restrictionStart, $restrictionEnd))->toBeTrue()
        ->and($day->isContainedIn($restrictionStart, $restrictionEnd))->toBeFalse();
});

test('days listed for a range are the ones fully contained in it', function () {
    $calendar = new FishingDayCalendar(fisheryWithFishingDay());

    $days = $calendar->daysBetween('2026-06-01', '2026-06-05');

    // Doba rozpoczynająca się 05.06 kończy się 06.06 o 15:00, więc w zakresie
    // się nie mieści — ostatnią zawartą jest ta z 04.06.
    expect($days)->toHaveCount(4)
        ->and($days[0]->startsOn->toDateString())->toBe('2026-06-01')
        ->and(end($days)->startsOn->toDateString())->toBe('2026-06-04');
});
