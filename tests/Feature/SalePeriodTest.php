<?php

namespace Tests\Feature;

use App\Models\Fishery;
use App\Models\SalePeriod;
use App\Rules\SalePeriodsDoNotOverlap;
use Spatie\Activitylog\Models\Activity;

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
