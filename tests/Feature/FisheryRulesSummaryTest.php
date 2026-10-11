<?php

namespace Tests\Feature;

use App\Models\Fishery;
use App\Models\SalePeriod;
use App\Services\FisheryRulesSummary;
use App\Services\WeekdayNights;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Tests\Support\StayFixtures;

/**
 * Dokładne teksty wyciągu zasad sprzedaży (zadanie 033) wskazane przez testy mutacyjne (02.10.2026) — widok
 * sprawdzają `PortalCalendarTest` i `FisheryPricingTabTest`, tu chodzi o treść, kolejność i granice każdej pozycji.
 *
 * ⚠️ Czas zamrożony: pon 04.05.2026, język polski, sezon na cały 2026 (z `StayFixtures`).
 */
beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-05-04 09:00', 'Europe/Warsaw'));
    app()->setLocale('pl');
});

afterEach(function () {
    Date::setTestNow();
});

/**
 * Łowisko z kompletem zasad i czterema terminami sprzedawanymi w całości: minionym, kończącym się DZIŚ
 * (ostatnia doba zaczyna się 04.05), czerwcowym i sierpniowym — wyciąg pokazuje dwa najbliższe.
 */
function rulesSummaryFishery(): Fishery
{
    [$fishery] = StayFixtures::fisheryWithPosition([
        'weekend_days' => [5, 6],
        'min_nights' => 2,
        'max_nights' => 7,
        'fishing_license_required' => false,
        'no_kill' => true,
    ]);
    StayFixtures::wholeTerm($fishery, '2026-04-01', 2);
    StayFixtures::wholeTerm($fishery, '2026-05-01', 4);
    StayFixtures::wholeTerm($fishery, '2026-06-04', 3);
    StayFixtures::wholeTerm($fishery, '2026-08-14', 3);

    return $fishery->fresh();
}

function rulesSummaryWeekend(Fishery $fishery): string
{
    return 'weekend '.mb_strtolower(WeekdayNights::forFishery($fishery)->runsText([5, 6])).' tylko w całości';
}

test('every item has its exact text, in the mockup order', function () {
    $fishery = rulesSummaryFishery();

    expect((new FisheryRulesSummary($fishery))->items())->toBe([
        'day' => 'Doba 15:00–15:00',
        'weekend' => rulesSummaryWeekend($fishery),
        'whole_terms' => '01.05–05.05 i 04.06–07.06 tylko w całości',
        'season' => 'sezon 01.01–31.12',
        'min' => 'min. 2 doby',
        'max' => 'maks. 7 dób',
        'licence' => 'karta wędkarska niewymagana',
        'no_kill' => 'ryby wypuszczamy (no-kill)',
    ]);
});

test('items without a value are left out, the weekend also without the fishing day hours', function () {
    [$fishery] = StayFixtures::fisheryWithPosition([
        'day_start_time' => null,
        'weekend_days' => [5, 6],
        'min_nights' => 1,
        'max_nights' => null,
        'fishing_license_required' => true,
        'no_kill' => false,
    ]);

    expect((new FisheryRulesSummary($fishery->fresh()))->items())->toBe([
        'season' => 'sezon 01.01–31.12',
        'licence' => 'karta wędkarska wymagana',
        'no_kill' => 'rybę można zabrać',
    ]);
});

test('no weekend days, no weekend item', function () {
    [$fishery] = StayFixtures::fisheryWithPosition(['weekend_days' => []]);

    expect((new FisheryRulesSummary($fishery->fresh()))->items())->not->toHaveKey('weekend');
});

test('the line starts with a capital letter and the card line takes its own keys', function () {
    $fishery = rulesSummaryFishery();
    $summary = new FisheryRulesSummary($fishery);

    expect($summary->line(['season', 'min']))->toBe('Sezon 01.01–31.12 · min. 2 doby')
        ->and($summary->line([]))->toBe('')
        ->and($summary->cardLine())->toBe(implode(' · ', [
            'Doba 15:00–15:00',
            rulesSummaryWeekend($fishery),
            '01.05–05.05 i 04.06–07.06 tylko w całości',
            'karta wędkarska niewymagana',
            'ryby wypuszczamy (no-kill)',
        ]));
});

test('entries split off the label only where the item starts with it, keeping the text as it is', function () {
    $fishery = rulesSummaryFishery();
    $weekend = rulesSummaryWeekend($fishery);

    $entries = (new FisheryRulesSummary($fishery))->entries();

    expect(array_slice($entries, 0, 4))->toBe([
        ['label' => 'Doba', 'rest' => ' 15:00–15:00'],
        // Etykieta w brzmieniu z tekstu (mała litera), nie z tłumaczenia.
        ['label' => 'weekend', 'rest' => mb_substr($weekend, mb_strlen('weekend'))],
        ['label' => null, 'rest' => '01.05–05.05 i 04.06–07.06 tylko w całości'],
        ['label' => 'sezon', 'rest' => ' 01.01–31.12'],
    ]);
});

test('the first entry gets a capital letter also when it is not the fishing day', function () {
    [$fishery] = StayFixtures::fisheryWithPosition(['day_start_time' => null]);

    expect((new FisheryRulesSummary($fishery->fresh()))->entries()[0])
        ->toBe(['label' => 'Sezon', 'rest' => ' 01.01–31.12']);
});

test('a whole-term relation loaded by the caller is used as it is, with the term ending today', function () {
    $fishery = rulesSummaryFishery()->load('wholeTermPeriods');
    // Wczytana kolekcja ma pierwszeństwo — baza nie jest już pytana.
    $fishery->wholeTermPeriods()->delete();

    expect((new FisheryRulesSummary($fishery))->items(['whole_terms']))
        ->toBe(['whole_terms' => '01.05–05.05 i 04.06–07.06 tylko w całości']);
});

test('no upcoming whole term, no whole-term item', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    StayFixtures::wholeTerm($fishery, '2026-04-01', 2);

    expect((new FisheryRulesSummary($fishery->fresh()))->items())->not->toHaveKey('whole_terms');
});

test('the presale lists its window, minimum stay and discount', function (array $presale, string $expected) {
    [$fishery] = StayFixtures::fisheryWithPosition();
    SalePeriod::factory()->create(array_merge([
        'fishery_id' => $fishery->id,
        'starts_on' => '2027-01-01',
        'ends_on' => '2027-12-31',
        'presale_opens_on' => '2026-04-01',
        'presale_closes_on' => '2026-06-30',
        'presale_min_nights' => null,
        'presale_discount_percent' => null,
    ], $presale));

    expect((new FisheryRulesSummary($fishery->fresh()))->items(['presale']))->toBe(['presale' => $expected]);
})->with([
    'everything' => [['presale_min_nights' => 5, 'presale_discount_percent' => 10], 'Przedsprzedaż 2027: 01.04–30.06.2026 · min. 5 dób · −10%'],
    'the window alone' => [[], 'Przedsprzedaż 2027: 01.04–30.06.2026'],
    'a zero discount is not shown' => [['presale_discount_percent' => 0], 'Przedsprzedaż 2027: 01.04–30.06.2026'],
    'a 1% discount is shown' => [['presale_discount_percent' => 1], 'Przedsprzedaż 2027: 01.04–30.06.2026 · −1%'],
    'two decimal places' => [['presale_discount_percent' => 12.25], 'Przedsprzedaż 2027: 01.04–30.06.2026 · −12,25%'],
]);
