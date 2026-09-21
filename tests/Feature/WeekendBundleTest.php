<?php

namespace Tests\Feature;

use App\Enums\SaleUnavailabilityReason;
use App\Rules\WeekendDaysAreContiguous;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Tests\Support\StayFixtures;

/**
 * Spójność zbioru dób weekendu oraz przedsprzedaż okresu (zadanie 017).
 *
 * ⚠️ Weekend to zbiór DÓB, nie dni. `weekend_days` wskazuje dni ROZPOCZĘCIA dób, więc
 * weekend „od piątku 15:00 do niedzieli 15:00" to `{5, 6}`, a nie `{5, 6, 7}`.
 *
 * ⚠️ Testy przedsprzedaży zamrażają czas — okno zależy od „dzisiaj", więc bez tego
 * zielenieją albo czerwienieją zależnie od dnia uruchomienia.
 */

/**
 * @return array<int, string>
 */
function weekendFailures(mixed $value): array
{
    $failures = [];

    (new WeekendDaysAreContiguous)->validate(
        'weekend_days',
        $value,
        function (string $message) use (&$failures): void {
            $failures[] = $message;
        },
    );

    return $failures;
}

test('a weekend set has to be a contiguous run of at least two nights', function () {
    // Kontrole pozytywne — bez nich test przechodziłby też wtedy, gdy reguła odrzuca
    // wszystko.
    expect(weekendFailures([5, 6]))->toBe([])
        ->and(weekendFailures([4, 5, 6]))->toBe([])
        // ⚠️ Tydzień się zawija: `{7, 1}` to doby nd→pon i pon→wt, czyli poprawny ciąg.
        ->and(weekendFailures([7, 1]))->toBe([])
        ->and(weekendFailures([6, 7, 1]))->toBe([])
        // Puste znaczy „brak reguły weekendu", nie błąd.
        ->and(weekendFailures(null))->toBe([])
        ->and(weekendFailures([]))->toBe([]);

    expect(weekendFailures([5]))->toHaveCount(1)
        ->and(weekendFailures([1, 3]))->toHaveCount(1)
        ->and(weekendFailures([5, 7]))->toHaveCount(1)
        ->and(weekendFailures([1, 2, 3, 4, 5, 6, 7]))->toHaveCount(1)
        ->and(weekendFailures([0, 1]))->toHaveCount(1)
        ->and(weekendFailures([8, 1]))->toHaveCount(1);
});

/**
 * ⚠️ Minimum przedsprzedaży obowiązuje KAŻDY zakup dób okresu dokonany w oknie —
 * przedsprzedaż jest ofertą hurtową, nie furtką na pojedyncze doby.
 */
test('an open presale window opens the season past the horizon but demands its minimum', function () {
    Date::setTestNow(CarbonImmutable::parse('2026-01-10 09:00', 'Europe/Warsaw'));

    [$fishery, $position] = StayFixtures::fisheryWithPosition([
        'sale_horizon_days' => 30,
        'weekend_days' => [5, 6],
    ]);

    $fishery->salePeriods()->update([
        'presale_opens_on' => '2026-01-01',
        'presale_closes_on' => '2026-01-31',
        'presale_min_nights' => 5,
        'presale_whole_terms_bypass_min_nights' => true,
    ]);

    // Doby daleko poza horyzontem (30 dni od 10.01 to 09.02).
    expect(StayFixtures::stay($position)->verdict('2026-05-04', 1)->reason)
        ->toBe(SaleUnavailabilityReason::BelowPresaleMinimum)
        ->and(StayFixtures::stay($position)->verdict('2026-05-01', 2)->reason)
        ->toBe(SaleUnavailabilityReason::BelowPresaleMinimum)
        // Sześć dób spełnia minimum okna i przechodzi mimo horyzontu.
        ->and(StayFixtures::stay($position)->isSellable('2026-05-04', 6))->toBeTrue();

    Date::setTestNow();
});

test('a term sold whole bypasses the presale minimum only while the flag is on', function () {
    Date::setTestNow(CarbonImmutable::parse('2026-01-10 09:00', 'Europe/Warsaw'));

    [$fishery, $position] = StayFixtures::fisheryWithPosition(['sale_horizon_days' => 30]);
    StayFixtures::wholeTerm($fishery, '2026-04-30', 3);

    $fishery->salePeriods()->update([
        'presale_opens_on' => '2026-01-01',
        'presale_closes_on' => '2026-01-31',
        'presale_min_nights' => 5,
        'presale_whole_terms_bypass_min_nights' => true,
    ]);

    expect(StayFixtures::stay($position)->isSellable('2026-04-30', 3))->toBeTrue();

    $fishery->salePeriods()->update(['presale_whole_terms_bypass_min_nights' => false]);

    expect(StayFixtures::stay($position)->verdict('2026-04-30', 3)->reason)
        ->toBe(SaleUnavailabilityReason::BelowPresaleMinimum);

    Date::setTestNow();
});

test('a night inside the horizon still obeys the presale minimum while the window is open', function () {
    Date::setTestNow(CarbonImmutable::parse('2026-06-01 09:00', 'Europe/Warsaw'));

    [$fishery, $position] = StayFixtures::fisheryWithPosition(['sale_horizon_days' => 30]);

    $fishery->salePeriods()->update([
        'presale_opens_on' => '2026-05-20',
        'presale_closes_on' => '2026-06-30',
        'presale_min_nights' => 5,
    ]);

    // 10.06 leży w horyzoncie, a mimo to nie da się kupić jednej doby — okno jest otwarte.
    expect(StayFixtures::stay($position)->verdict('2026-06-10', 1)->reason)
        ->toBe(SaleUnavailabilityReason::BelowPresaleMinimum);

    Date::setTestNow();
});

test('after the window closes the horizon takes over again', function () {
    Date::setTestNow(CarbonImmutable::parse('2026-02-15 09:00', 'Europe/Warsaw'));

    [$fishery, $position] = StayFixtures::fisheryWithPosition(['sale_horizon_days' => 30]);

    $fishery->salePeriods()->update([
        'presale_opens_on' => '2026-01-01',
        'presale_closes_on' => '2026-01-31',
        'presale_min_nights' => 5,
    ]);

    // Okno zamknięte: doby poza horyzontem wracają do odmowy „poza horyzontem"…
    expect(StayFixtures::stay($position)->verdict('2026-05-04', 6)->reason)
        ->toBe(SaleUnavailabilityReason::BeyondSaleHorizon)
        // …a doby w horyzoncie sprzedają się pojedynczo.
        ->and(StayFixtures::stay($position)->isSellable('2026-03-01', 1))->toBeTrue();

    Date::setTestNow();
});

/**
 * ⚠️ Okno obejmuje CAŁE dni brzegowe, tak samo jak okno blokady (`dostepnosc.md` §3).
 * Porównywanym momentem jest chwila zakupu.
 */
test('the presale window covers whole boundary days', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition(['sale_horizon_days' => 30]);

    $fishery->salePeriods()->update([
        'presale_opens_on' => '2026-01-05',
        'presale_closes_on' => '2026-01-10',
        'presale_min_nights' => 2,
    ]);

    // Dzień otwarcia o 00:01 — okno już działa, więc doba poza horyzontem przechodzi.
    Date::setTestNow(CarbonImmutable::parse('2026-01-05 00:01', 'Europe/Warsaw'));
    expect(StayFixtures::stay($position)->isSellable('2026-05-04', 2))->toBeTrue();

    // Dzień zamknięcia o 23:00 — nadal działa.
    Date::setTestNow(CarbonImmutable::parse('2026-01-10 23:00', 'Europe/Warsaw'));
    expect(StayFixtures::stay($position)->isSellable('2026-05-04', 2))->toBeTrue();

    // Nazajutrz o 00:01 — okno zamknięte, zostaje horyzont.
    Date::setTestNow(CarbonImmutable::parse('2026-01-11 00:01', 'Europe/Warsaw'));
    expect(StayFixtures::stay($position)->verdict('2026-05-04', 2)->reason)
        ->toBe(SaleUnavailabilityReason::BeyondSaleHorizon);

    Date::setTestNow();
});

/**
 * ⚠️ Okno przedsprzedaży jest wyjątkiem WYŁĄCZNIE od horyzontu. Nie otwiera sprzedaży
 * w blokadzie, na stanowisku wycofanym ani na dobach INNEGO okresu.
 */
test('an open window does not open a night that position availability refuses', function () {
    Date::setTestNow(CarbonImmutable::parse('2026-01-10 09:00', 'Europe/Warsaw'));

    [$fishery, $position] = StayFixtures::fisheryWithPosition(['sale_horizon_days' => 30]);

    $fishery->salePeriods()->update([
        'presale_opens_on' => '2026-01-01',
        'presale_closes_on' => '2026-01-31',
        'presale_min_nights' => 1,
    ]);

    StayFixtures::blockSale($fishery, $position, '2026-05-04', '2026-05-10');

    expect(StayFixtures::stay($position)->verdict('2026-05-04', 2)->reason)
        ->toBe(SaleUnavailabilityReason::SaleBlocked);

    Date::setTestNow();
});

test('a night of another period is not opened by a window of the period next to it', function () {
    Date::setTestNow(CarbonImmutable::parse('2026-01-10 09:00', 'Europe/Warsaw'));

    [$fishery, $position] = StayFixtures::fisheryWithPosition(['sale_horizon_days' => 30]);

    // Okres roczny z fixtures dzielimy na dwa rozłączne, z oknem tylko na drugim.
    $fishery->salePeriods()->delete();
    $fishery->salePeriods()->create([
        'starts_on' => '2026-01-01',
        'ends_on' => '2026-06-30',
    ]);
    $fishery->salePeriods()->create([
        'starts_on' => '2026-08-01',
        'ends_on' => '2026-12-31',
        'presale_opens_on' => '2026-01-01',
        'presale_closes_on' => '2026-01-31',
        'presale_min_nights' => 1,
    ]);

    // Doba sierpniowa należy do okresu z otwartym oknem — przechodzi poza horyzontem.
    expect(StayFixtures::stay($position)->isSellable('2026-08-10', 1))->toBeTrue()
        // Doba czerwcowa należy do okresu BEZ okna — horyzont ją odrzuca.
        ->and(StayFixtures::stay($position)->verdict('2026-06-20', 1)->reason)
        ->toBe(SaleUnavailabilityReason::BeyondSaleHorizon);

    Date::setTestNow();
});
