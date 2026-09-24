<?php

namespace Tests\Feature;

use App\Models\Fishery;
use App\Models\SalePeriod;
use App\Rules\SalePeriodsDoNotOverlap;
use App\Services\FishingDayCalendar;
use App\Services\SalePeriodFinder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\StayFixtures;

/**
 * Reguła nienachodzenia okresów sprzedaży i kaskada klucza obcego.
 *
 * ⚠️ Reguła testowana jest BEZPOŚREDNIO, a nie tylko przez formularz — mieszka
 * w `app/Rules/` właśnie po to, żeby obowiązywała także import, seed i przyszłe API
 * (`CLAUDE.md`, „logika walidacyjna ma jeden dom").
 */
function validateSalePeriods(array $periods): array
{
    $failures = [];

    (new SalePeriodsDoNotOverlap)->validate(
        'salePeriods',
        $periods,
        function (string $message) use (&$failures): void {
            $failures[] = $message;
        },
    );

    return $failures;
}

test('non overlapping periods pass', function () {
    $failures = validateSalePeriods([
        ['starts_on' => '2026-02-01', 'ends_on' => '2026-03-31'],
        ['starts_on' => '2026-06-01', 'ends_on' => '2026-09-30'],
    ]);

    expect($failures)->toBe([]);
});

test('overlapping periods are rejected', function () {
    $failures = validateSalePeriods([
        ['starts_on' => '2026-02-01', 'ends_on' => '2026-06-30'],
        ['starts_on' => '2026-06-01', 'ends_on' => '2026-09-30'],
    ]);

    expect($failures)->toHaveCount(1);
});

test('periods touching on the same day are rejected as overlapping', function () {
    $failures = validateSalePeriods([
        ['starts_on' => '2026-02-01', 'ends_on' => '2026-03-31'],
        ['starts_on' => '2026-03-31', 'ends_on' => '2026-09-30'],
    ]);

    expect($failures)->toHaveCount(1);
});

test('a period ending before it starts is rejected', function () {
    $failures = validateSalePeriods([
        ['starts_on' => '2026-09-30', 'ends_on' => '2026-02-01'],
    ]);

    expect($failures)->toHaveCount(1);
});

test('incomplete rows are ignored instead of failing the whole set', function () {
    $failures = validateSalePeriods([
        ['starts_on' => '2026-02-01', 'ends_on' => null],
        ['starts_on' => '2026-06-01', 'ends_on' => '2026-09-30'],
    ]);

    expect($failures)->toBe([]);
});

test('a soft deleted period does not block a new one on the same dates', function () {
    $fishery = Fishery::factory()->create();

    $withdrawn = SalePeriod::factory()->create([
        'fishery_id' => $fishery->id,
        'starts_on' => '2026-02-01',
        'ends_on' => '2026-09-30',
    ]);
    $withdrawn->delete();

    // Zbiór przekazany do reguły pochodzi z relacji, a ta nie widzi wierszy
    // usuniętych miękko — wycofany sezon nie ma prawa blokować nowego.
    $current = $fishery->salePeriods()->get()
        ->map(fn (SalePeriod $period): array => [
            'starts_on' => $period->starts_on->toDateString(),
            'ends_on' => $period->ends_on->toDateString(),
        ])->all();

    $current[] = ['starts_on' => '2026-02-01', 'ends_on' => '2026-09-30'];

    expect($fishery->salePeriods()->count())->toBe(0)
        ->and(validateSalePeriods($current))->toBe([]);
});

test('deleting a fishery for good takes its sale periods with it', function () {
    $fishery = Fishery::factory()->create();
    SalePeriod::factory()->create(['fishery_id' => $fishery->id]);

    $fishery->forceDelete();

    expect(SalePeriod::withTrashed()->where('fishery_id', $fishery->id)->count())->toBe(0);
});

test('a sale period logs attribute changes', function () {
    $period = SalePeriod::factory()->create(['name' => 'Sezon główny']);

    $period->update(['name' => 'Sezon poprawiony']);

    $activity = Activity::latest('id')->first();

    expect($activity->attribute_changes['old']['name'] ?? null)->toBe('Sezon główny')
        ->and($activity->attribute_changes['attributes']['name'] ?? null)->toBe('Sezon poprawiony');
});

/*
 * Testy dopisane po mutacjach zadania 023.
 */

test('przedsprzedaż jest włączona tylko przy OBU datach okna', function () {
    expect((new SalePeriod(['presale_opens_on' => '2026-01-01']))->hasPresale())->toBeFalse()
        ->and((new SalePeriod(['presale_closes_on' => '2026-01-31']))->hasPresale())->toBeFalse()
        ->and((new SalePeriod(['presale_opens_on' => '2026-01-01', 'presale_closes_on' => '2026-01-31']))->hasPresale())->toBeTrue();
});

/**
 * ⚠️ Okno obejmuje CAŁE dni brzegowe w strefie łowiska: od północy dnia otwarcia do ostatniej
 * mikrosekundy dnia zamknięcia.
 */
test('okno przedsprzedaży jest domknięte na obu brzegach i liczone w strefie łowiska', function () {
    [$fishery] = StayFixtures::fisheryWithPosition(['timezone' => 'America/New_York']);
    $fishery->salePeriods()->update(['presale_opens_on' => '2026-01-01', 'presale_closes_on' => '2026-01-31']);
    $period = $fishery->salePeriods()->firstOrFail();
    $finder = fn () => new SalePeriodFinder($fishery->fresh());

    Date::setTestNow(CarbonImmutable::parse('2026-01-01 00:00:00', 'America/New_York'));
    $atOpening = $finder()->hasOpenPresale($period);

    Date::setTestNow(CarbonImmutable::parse('2026-01-31 23:59:59.999999', 'America/New_York'));
    $atClosing = $finder()->hasOpenPresale($period);

    // 01.02 03:00 w Warszawie to jeszcze 31.01 w Nowym Jorku — okno wciąż otwarte.
    Date::setTestNow(CarbonImmutable::parse('2026-02-01 03:00', 'Europe/Warsaw'));
    $inFisheryZone = $finder()->hasOpenPresale($period);

    Date::setTestNow();

    expect($atOpening)->toBeTrue()
        ->and($atClosing)->toBeTrue()
        ->and($inFisheryZone)->toBeTrue();
});

/**
 * ⚠️ Okresy wczytuje się RAZ na instancję — pytanie o zakres dób zadawałoby je inaczej raz
 * na dobę.
 */
test('okresy sprzedaży wczytuje się raz na instancję, nie raz na dobę', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    $fishery = $fishery->fresh();

    $calendar = new FishingDayCalendar($fishery);
    $finder = new SalePeriodFinder($fishery);
    $nights = [
        $calendar->dayStartingOn('2026-06-10'),
        $calendar->dayStartingOn('2026-06-11'),
        $calendar->dayStartingOn('2026-06-12'),
    ];

    $periodQueries = 0;

    DB::listen(function ($query) use (&$periodQueries): void {
        if (str_contains($query->sql, 'from `sale_periods`')) {
            $periodQueries++;
        }
    });

    foreach ($nights as $night) {
        expect($finder->forNight($night))->not->toBeNull();
    }

    expect($periodQueries)->toBe(1);
});
