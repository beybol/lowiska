<?php

namespace Tests\Feature;

use App\Enums\ParticipantRole;
use App\Enums\PricingFailure;
use App\Models\PriceRule;
use App\Rules\PriceRulesDoNotTie;
use App\Services\FishingDayCalendar;
use App\Services\PriceRuleResolver;
use Carbon\CarbonImmutable;
use Tests\Support\StayFixtures;

/**
 * Rozstrzyganie cennika: priorytet → szczegółowość → **błąd konfiguracji** (ADR-014).
 *
 * ⚠️ Nachodzenie się stawek jest ZAMIERZONE — „70 zł zawsze" koliduje z „90 zł w piątki"
 * w każdy piątek i tak właśnie operator chce to zapisać. Błędem jest wyłącznie remis
 * **nierozstrzygalny**, i to on jest tutaj najpilniej pilnowany.
 *
 * Kalendarz odniesienia (2026): 29.04 śr · 30.04 czw · **01.05 pt** · 02.05 sob · 03.05 nd.
 */

/**
 * @param  array<int, PriceRule>  $rules
 */
function resolveNight(array $rules, string $date, ParticipantRole $role = ParticipantRole::Angler, int $anglers = 1, ?string $today = null)
{
    $fishery = $rules[0]->fishery;
    $night = (new FishingDayCalendar($fishery))->dayStartingOn($date);

    return (new PriceRuleResolver($rules, CarbonImmutable::parse($today ?? '2026-01-01')))
        ->resolve($night, $role, $anglers);
}

/**
 * @return array<int, string>
 */
function tieFailures(array $rows): array
{
    $failures = [];

    (new PriceRulesDoNotTie)->validate(
        'rateRules',
        $rows,
        function (string $message) use (&$failures): void {
            $failures[] = $message;
        },
    );

    return $failures;
}

test('a higher priority wins over a base rate', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    $base = StayFixtures::rate($fishery, 70.00);
    $friday = StayFixtures::rate($fishery, 90.00, ['weekdays' => [5], 'priority' => 10]);

    expect(resolveNight([$base, $friday], '2026-05-01')->rate->id)->toBe($friday->id)
        ->and(resolveNight([$base, $friday], '2026-04-30')->rate->id)->toBe($base->id);
});

/**
 * ⚠️ Szczegółowość liczy się OSIAMI, nie polami — zakres z jednym otwartym końcem to jedna oś.
 */
test('specificity breaks a priority tie and counts axes, not fields', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();

    $oneAxis = StayFixtures::rate($fishery, 70.00, ['first_day_on' => '2026-04-01', 'last_day_on' => '2026-06-30']);
    $twoAxes = StayFixtures::rate($fishery, 95.00, [
        'first_day_on' => '2026-04-01',
        'last_day_on' => '2026-06-30',
        'weekdays' => [5],
    ]);

    expect(resolveNight([$oneAxis, $twoAxes], '2026-05-01')->rate->id)->toBe($twoAxes->id)
        ->and($oneAxis->specificity())->toBe(1)
        ->and($twoAxes->specificity())->toBe(2);

    // Zakres z JEDNYM otwartym końcem to nadal jedna oś, nie dwie i nie pół.
    $openEnded = StayFixtures::rate($fishery, 80.00, ['first_day_on' => '2026-04-01']);

    expect($openEnded->specificity())->toBe(1);
});

test('an unresolvable tie is reported instead of a price', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    $a = StayFixtures::rate($fishery, 70.00, ['weekdays' => [5]]);
    $b = StayFixtures::rate($fishery, 90.00, ['first_day_on' => '2026-04-01', 'last_day_on' => '2026-06-30']);

    $resolution = resolveNight([$a, $b], '2026-05-01');

    expect($resolution->isResolved())->toBeFalse()
        ->and($resolution->failure)->toBe(PricingFailure::UnresolvableTie)
        // ⚠️ Nie wybiera żadnej z dwóch kwot — ani wyższej, ani nowszej.
        ->and($resolution->rate)->toBeNull();
});

test('a night with no matching rate reports a gap, not a zero price', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    $friday = StayFixtures::rate($fishery, 90.00, ['weekdays' => [5]]);

    $resolution = resolveNight([$friday], '2026-04-30');

    expect($resolution->isResolved())->toBeFalse()
        ->and($resolution->failure)->toBe(PricingFailure::NoMatchingRate)
        ->and($resolution->amountInCents())->toBe(0);
});

test('all matching surcharges add up', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    $rate = StayFixtures::rate($fishery, 90.00, ['weekdays' => [5]]);
    $first = StayFixtures::surcharge($fishery, 20.00, 'Wylacznosc');
    $second = StayFixtures::surcharge($fishery, 5.00, 'Prad');

    $resolution = resolveNight([$rate, $first, $second], '2026-05-01');

    expect($resolution->surcharges)->toHaveCount(2)
        ->and($resolution->amountInCents())->toBe(11500);
});

/**
 * ⚠️ Dwa wymiary czasu na jednej regule i **nie wolno ich zlać**: `effective_*` mierzy się
 * wobec dzisiejszej daty, a `first_day_on`/`last_day_on` — wobec wycenianej doby.
 */
test('the two time dimensions do not get confused', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    $base = StayFixtures::rate($fishery, 70.00);

    // Zapis obowiązujący dopiero od czerwca, choć jego warunek pasuje do majowej doby.
    $future = StayFixtures::rate($fishery, 120.00, [
        'priority' => 10,
        'effective_from' => '2026-06-01',
        'first_day_on' => '2026-05-01',
        'last_day_on' => '2026-05-31',
    ]);

    expect(resolveNight([$base, $future], '2026-05-01', today: '2026-05-20')->rate->id)->toBe($base->id)
        ->and(resolveNight([$base, $future], '2026-05-01', today: '2026-06-01')->rate->id)->toBe($future->id);

    // Odwrotnie: zapis obowiązuje dziś, ale jego warunek dat nie obejmuje tej doby.
    $july = StayFixtures::rate($fishery, 150.00, [
        'priority' => 10,
        'first_day_on' => '2026-07-01',
        'last_day_on' => '2026-07-31',
    ]);

    expect(resolveNight([$base, $july], '2026-05-01', today: '2026-05-20')->rate->id)->toBe($base->id);
});

/**
 * ⚠️ Obie granice są DOMKNIĘTE, jak horyzont i okno przedsprzedaży w 017.
 */
test('both date boundaries are closed', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    $base = StayFixtures::rate($fishery, 70.00);
    $window = StayFixtures::rate($fishery, 99.00, [
        'priority' => 10,
        'effective_to' => '2026-05-20',
        'first_day_on' => '2026-05-01',
        'last_day_on' => '2026-05-02',
    ]);

    // Ostatni dzień obowiązywania zapisu — jeszcze wchodzi.
    expect(resolveNight([$base, $window], '2026-05-02', today: '2026-05-20')->rate->id)->toBe($window->id)
        // Dzień po — już nie.
        ->and(resolveNight([$base, $window], '2026-05-02', today: '2026-05-21')->rate->id)->toBe($base->id)
        // Ostatnia objęta doba — jeszcze wchodzi; następna już nie.
        ->and(resolveNight([$base, $window], '2026-05-03', today: '2026-05-20')->rate->id)->toBe($base->id);
});

test('a suspended rule takes no part in pricing but stays in the list', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    $rate = StayFixtures::rate($fishery, 130.00);
    $suspended = StayFixtures::surcharge($fishery, 30.00, 'Wylacznosc', ['is_suspended' => true]);

    $resolution = resolveNight([$rate, $suspended], '2026-05-01');

    expect($resolution->surcharges)->toBe([])
        ->and($resolution->amountInCents())->toBe(13000)
        ->and(PriceRule::whereKey($suspended->id)->exists())->toBeTrue();
});

/**
 * ⚠️ Remis wykrywa się przy osi PUSTEJ — oś pusta przecina się ze wszystkim — ale rozłączne
 * `effective_*` kolizji nie tworzą.
 */
test('the tie check treats an empty axis as matching everything', function () {
    $row = fn (array $overrides = []): array => array_merge([
        'amount' => 70.00,
        'priority' => 0,
        'weekdays' => null,
        'first_day_on' => null,
        'last_day_on' => null,
        'anglers_count' => null,
        'participant_role' => null,
        'effective_from' => null,
        'effective_to' => null,
    ], $overrides);

    // Dwie stawki bazowe — obie bez warunków, ten sam priorytet.
    expect(tieFailures([$row(), $row()]))->toHaveCount(1);

    // Rozłączne okna obowiązywania zapisu: nie kolidują.
    expect(tieFailures([
        $row(['effective_from' => '2026-01-01', 'effective_to' => '2026-05-31']),
        $row(['effective_from' => '2026-06-01', 'effective_to' => '2026-12-31']),
    ]))->toBe([]);

    // Różne obsady na osi niezależnej od doby: nie kolidują.
    expect(tieFailures([
        $row(['anglers_count' => 1]),
        $row(['anglers_count' => 2]),
    ]))->toBe([]);

    // Różny priorytet: nie ma remisu, choć warunki się pokrywają.
    expect(tieFailures([$row(), $row(['priority' => 10])]))->toBe([]);
});

/**
 * ⚠️ **Sedno reguły**: dni tygodnia i zakres dat sprawdza się RAZEM. Sprawdzane oś po osi
 * ta para kolidowałaby ZAWSZE — a wtedy walidacja nie pozwoliłaby zapisać poprawnego cennika.
 */
test('weekdays and the date range are checked together, not axis by axis', function () {
    $row = fn (array $overrides): array => array_merge([
        'amount' => 70.00,
        'priority' => 0,
        'weekdays' => null,
        'first_day_on' => null,
        'last_day_on' => null,
        'anglers_count' => null,
        'participant_role' => null,
        'effective_from' => null,
        'effective_to' => null,
    ], $overrides);

    $fridays = $row(['weekdays' => [5]]);

    // 30.04–02.05.2026 to czw–sob, więc piątek W TEN zakres wypada → kolizja.
    expect(tieFailures([$fridays, $row(['first_day_on' => '2026-04-30', 'last_day_on' => '2026-05-02'])]))
        ->toHaveCount(1);

    // 02.05–04.05.2026 to sob–pon, więc piątku tam nie ma → zapis przechodzi.
    expect(tieFailures([$fridays, $row(['first_day_on' => '2026-05-02', 'last_day_on' => '2026-05-04'])]))
        ->toBe([]);

    // Zakres siedmiodniowy zawiera każdy dzień tygodnia → kolizja bez przeglądania dób.
    expect(tieFailures([$fridays, $row(['first_day_on' => '2026-05-02', 'last_day_on' => '2026-05-08'])]))
        ->toHaveCount(1);

    // ⚠️ Otwarty koniec JEDNEJ z reguł nie wystarcza: przecięcie „od 01.01.2026" z „30.04–02.05"
    // jest zamknięte i trzydniowe, więc trzeba je przejrzeć dobami.
    expect(tieFailures([
        $row(['weekdays' => [5], 'first_day_on' => '2026-01-01']),
        $row(['first_day_on' => '2026-05-02', 'last_day_on' => '2026-05-04']),
    ]))->toBe([]);
});
