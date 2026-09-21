<?php

namespace Tests\Feature;

use App\Models\Fishery;
use App\Models\WholeTermPeriod;
use App\Rules\WholeTermPeriodsFitTheSeason;
use App\Services\FishingDayCalendar;
use Tests\Support\StayFixtures;

/**
 * Święta sprzedawane w całości: znaczenie granic, walidacja i cykl życia rekordu.
 *
 * ⚠️ Sedno tego pliku to test „ta sama para dat znaczy co innego w dwóch tabelach".
 * `sale_periods.ends_on` jest ostatnim dniem OKNA (ostatnia sprzedawalna doba zaczyna
 * się dzień wcześniej), a `whole_term_periods.last_day_on` — dniem rozpoczęcia ostatniej
 * OBJĘTEJ doby. Test pinuje obie liczby obok siebie, bo to jest pułapka, którą najłatwiej
 * skopiować w złą stronę (`dostepnosc.md` §4).
 */

/**
 * @return array<int, string>
 */
function termFailures(Fishery $fishery, array $terms): array
{
    $failures = [];

    (new WholeTermPeriodsFitTheSeason($fishery))->validate(
        'wholeTermPeriods',
        $terms,
        function (string $message) use (&$failures): void {
            $failures[] = $message;
        },
    );

    return $failures;
}

test('the same pair of dates means a different number of nights in the two tables', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();

    // Okres sprzedaży 30.04 – 02.05 sprzedaje DWIE doby: 30.04 i 01.05. Doba z 02.05
    // kończy się 03.05 o 15:00, czyli poza oknem.
    $fishery->salePeriods()->delete();
    $fishery->salePeriods()->create(['starts_on' => '2026-04-30', 'ends_on' => '2026-05-02']);

    $calendar = new FishingDayCalendar($fishery->fresh());
    $sellableNights = $calendar->daysBetween('2026-04-30', '2026-05-02');

    // Święto 30.04 – 02.05 obejmuje TRZY doby: 30.04, 01.05 i 02.05.
    $term = StayFixtures::wholeTerm($fishery, '2026-04-30', 3);

    expect($sellableNights)->toHaveCount(2)
        ->and($sellableNights[0]->startsOn->toDateString())->toBe('2026-04-30')
        ->and($sellableNights[1]->startsOn->toDateString())->toBe('2026-05-01')
        ->and($term->last_day_on->toDateString())->toBe('2026-05-02')
        ->and((int) $term->first_day_on->diffInDays($term->last_day_on) + 1)->toBe(3);
});

test('a term shorter than two nights is rejected', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();

    expect(termFailures($fishery, [['first_day_on' => '2026-05-01', 'last_day_on' => '2026-05-01']]))
        ->toHaveCount(1)
        ->and(termFailures($fishery, [['first_day_on' => '2026-05-02', 'last_day_on' => '2026-05-01']]))
        ->toHaveCount(1)
        // Kontrola pozytywna — dwie doby przechodzą.
        ->and(termFailures($fishery, [['first_day_on' => '2026-05-01', 'last_day_on' => '2026-05-02']]))
        ->toBe([]);
});

test('a term with any night outside a sale period is rejected', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    $fishery->salePeriods()->delete();
    $fishery->salePeriods()->create(['starts_on' => '2026-05-01', 'ends_on' => '2026-05-31']);

    // Święto w środku sezonu — przechodzi.
    expect(termFailures($fishery->fresh(), [['first_day_on' => '2026-05-10', 'last_day_on' => '2026-05-12']]))
        ->toBe([])
        // Święto wystające przed sezon.
        ->and(termFailures($fishery->fresh(), [['first_day_on' => '2026-04-29', 'last_day_on' => '2026-05-02']]))
        ->toHaveCount(1)
        // ⚠️ Ostatnia doba sezonu zaczyna się 30.05, nie 31.05 — reguła zawierania.
        ->and(termFailures($fishery->fresh(), [['first_day_on' => '2026-05-29', 'last_day_on' => '2026-05-31']]))
        ->toHaveCount(1);
});

/**
 * ⚠️ Doba na styku dwóch SĄSIADUJĄCYCH okresów też jest błędem: nie zawiera się
 * w żadnym z nich w całości, choć „mieści się w sumie". To ta sama reguła zawierania,
 * która sprawia, że ostatnie pozwolenie jednodobowe kupuje się na przedostatni dzień.
 */
test('a night crossing the boundary of two adjacent periods is rejected', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    $fishery->salePeriods()->delete();
    $fishery->salePeriods()->create(['starts_on' => '2026-05-01', 'ends_on' => '2026-05-15']);
    $fishery->salePeriods()->create(['starts_on' => '2026-05-16', 'ends_on' => '2026-05-31']);

    // Doba z 15.05 kończy się 16.05 o 15:00 — pierwszy okres jej nie mieści,
    // a drugi zaczyna się po jej rozpoczęciu.
    expect(termFailures($fishery->fresh(), [['first_day_on' => '2026-05-14', 'last_day_on' => '2026-05-16']]))
        ->toHaveCount(1);
});

/**
 * ⚠️ Na świeżym łowisku reguła musi mówić prawdę o PRZYCZYNIE. Bez rozróżnienia
 * operator zobaczyłby „święto poza sezonem" tam, gdzie problemem jest brak konfiguracji.
 */
test('a fresh fishery does not lie about why the term is rejected', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    $fishery->salePeriods()->delete();

    $withoutPeriods = termFailures(
        $fishery->fresh(),
        [['first_day_on' => '2026-05-10', 'last_day_on' => '2026-05-12']],
    );

    expect($withoutPeriods)->toHaveCount(1)
        ->and($withoutPeriods[0])->toBe(__('Add a sale period before adding terms sold whole.'))
        ->and($withoutPeriods[0])->not->toBe(__('A term sold whole has to fit inside a sale period, night by night.'));

    $fishery->update(['day_start_time' => null, 'day_end_time' => null]);

    $withoutHours = termFailures(
        $fishery->fresh(),
        [['first_day_on' => '2026-05-10', 'last_day_on' => '2026-05-12']],
    );

    expect($withoutHours)->toHaveCount(1)
        ->and($withoutHours[0])->toBe(__('Set the fishing day hours before adding terms sold whole.'));
});

/**
 * ⚠️ Nachodzenie jest stanem POPRAWNYM, nie błędem zapisu — nachodzące spoiwa zlewają
 * się w jeden pakiet (ADR-013). Nie ma tu ani indeksu unikalnego, ani reguły.
 */
test('two overlapping terms are accepted', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();

    expect(termFailures($fishery, [
        ['first_day_on' => '2026-05-01', 'last_day_on' => '2026-05-05'],
        ['first_day_on' => '2026-05-03', 'last_day_on' => '2026-05-08'],
    ]))->toBe([]);

    StayFixtures::wholeTerm($fishery, '2026-05-01', 5);
    StayFixtures::wholeTerm($fishery, '2026-05-03', 6);

    expect($fishery->wholeTermPeriods()->count())->toBe(2);
});

test('a soft deleted term stops affecting anything', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition(['min_nights' => 5]);
    $term = StayFixtures::wholeTerm($fishery, '2026-04-30', 3);

    // Dopóki istnieje, zwalnia z minimum.
    expect(StayFixtures::stay($position)->isSellable('2026-04-30', 3))->toBeTrue();

    $term->delete();

    expect(StayFixtures::stay($position)->isSellable('2026-04-30', 3))->toBeFalse()
        ->and(WholeTermPeriod::withTrashed()->whereKey($term->id)->count())->toBe(1);
});

test('deleting a fishery for good takes its terms with it', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    $term = StayFixtures::wholeTerm($fishery, '2026-05-10', 3);

    $fishery->forceDelete();

    expect(WholeTermPeriod::withTrashed()->whereKey($term->id)->count())->toBe(0);
});
