<?php

namespace Tests\Feature;

use App\Enums\ParticipantRole;
use App\Enums\PriceRuleKind;
use App\Models\PriceRule;
use App\Rules\PresaleDiscountIsPercentage;
use App\Rules\PriceRuleDatesAreOrdered;
use App\Services\FishingDayCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\StayFixtures;

/**
 * Model reguły cenowej i reguły porządku dat — rzeczy, które muszą być prawdziwe niezależnie
 * od tego, kto pyta o cenę.
 *
 * Kalendarz odniesienia (2026): 29.04 śr · 30.04 czw · **01.05 pt** · 02.05 sob · 03.05 nd.
 */

/**
 * @return array<int, string>
 */
function priceRuleFailures(object $rule, array $rows): array
{
    $failures = [];

    $rule->validate('rules', $rows, function (string $message) use (&$failures): void {
        $failures[] = $message;
    });

    return $failures;
}

test('an empty condition means no condition on that axis, not a false one', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    $base = StayFixtures::rate($fishery, 70.00);
    $night = (new FishingDayCalendar($fishery))->dayStartingOn('2026-05-01');

    // Reguła bez ani jednego warunku jest stawką BAZOWĄ łowiska — pasuje wszędzie.
    expect($base->specificity())->toBe(0)
        ->and($base->matches($night, ParticipantRole::Angler, 1))->toBeTrue()
        ->and($base->matches($night, ParticipantRole::Companion, 5))->toBeTrue();
});

/**
 * ⚠️ `anglers_count` porównuje się przez RÓWNOŚĆ z obsadą z zapytania, nie z pojemnością
 * stanowiska (zadanie 018, rozstrzygnięcie 24).
 */
test('the anglers condition is an equality against the party size', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    $rule = StayFixtures::rate($fishery, 70.00, ['anglers_count' => 1]);
    $night = (new FishingDayCalendar($fishery))->dayStartingOn('2026-05-01');

    expect($rule->matches($night, ParticipantRole::Angler, 1))->toBeTrue()
        ->and($rule->matches($night, ParticipantRole::Angler, 2))->toBeFalse()
        ->and($rule->specificity())->toBe(1);
});

test('the weekday condition matches the day the night starts on', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    $friday = StayFixtures::rate($fishery, 90.00, ['weekdays' => [5]]);
    $calendar = new FishingDayCalendar($fishery);

    expect($friday->matches($calendar->dayStartingOn('2026-05-01'), ParticipantRole::Angler, 1))->toBeTrue()
        // Doba sobotnia kończy się w niedzielę, ale ZACZYNA w sobotę — nie jest piątkowa.
        ->and($friday->matches($calendar->dayStartingOn('2026-05-02'), ParticipantRole::Angler, 1))->toBeFalse();
});

test('a suspended rule is not effective on any day', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    $rule = StayFixtures::rate($fishery, 70.00, ['is_suspended' => true]);

    expect($rule->isEffectiveOn(CarbonImmutable::parse('2026-05-01')))->toBeFalse();
});

test('the amount is read in whole cents', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();

    expect(StayFixtures::rate($fishery, 70.05)->amountInCents())->toBe(7005)
        ->and(StayFixtures::rate($fishery, 0.00)->amountInCents())->toBe(0)
        ->and(StayFixtures::rate($fishery, 130.00)->amountInCents())->toBe(13000);
});

/**
 * ⚠️ Asercja o zapisanej kwocie idzie przez `DB::table(...)`, nie przez akcesor —
 * akcesory formatujące potrafią skłamać o utracie danych (`panel-wlasciciela.md` §4).
 */
test('the amount lands in the database with both decimal separators', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();

    $withDot = PriceRule::factory()->create(['fishery_id' => $fishery->id, 'amount' => 49.50]);
    $withComma = PriceRule::factory()->create([
        'fishery_id' => $fishery->id,
        'amount' => (float) str_replace(',', '.', '49,50'),
    ]);

    expect((float) DB::table('price_rules')->where('id', $withDot->id)->value('amount'))->toBe(49.5)
        ->and((float) DB::table('price_rules')->where('id', $withComma->id)->value('amount'))->toBe(49.5);
});

test('both pairs of dates are validated independently', function () {
    $rule = new PriceRuleDatesAreOrdered;

    expect(priceRuleFailures($rule, [['effective_from' => '2026-06-01', 'effective_to' => '2026-05-01']]))
        ->toHaveCount(1)
        ->and(priceRuleFailures($rule, [['first_day_on' => '2026-06-01', 'last_day_on' => '2026-05-01']]))
        ->toHaveCount(1)
        // Otwarty koniec jest poprawny — znaczy „bez granicy".
        ->and(priceRuleFailures($rule, [['effective_from' => '2026-06-01', 'effective_to' => null]]))
        ->toBe([])
        ->and(priceRuleFailures($rule, [['first_day_on' => null, 'last_day_on' => '2026-05-01']]))
        ->toBe([])
        // Kontrola pozytywna.
        ->and(priceRuleFailures($rule, [[
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
            'first_day_on' => '2026-05-01',
            'last_day_on' => '2026-05-31',
        ]]))->toBe([]);
});

test('the presale discount has to be a percentage', function () {
    $rule = new PresaleDiscountIsPercentage;

    expect(priceRuleFailures($rule, [['presale_discount_percent' => 101]]))->toHaveCount(1)
        ->and(priceRuleFailures($rule, [['presale_discount_percent' => -1]]))->toHaveCount(1)
        ->and(priceRuleFailures($rule, [['presale_discount_percent' => 'dziesiec']]))->toHaveCount(1)
        ->and(priceRuleFailures($rule, [['presale_discount_percent' => 10.5]]))->toBe([])
        ->and(priceRuleFailures($rule, [['presale_discount_percent' => 0]]))->toBe([])
        ->and(priceRuleFailures($rule, [['presale_discount_percent' => 100]]))->toBe([])
        // Puste znaczy „bez obniżki".
        ->and(priceRuleFailures($rule, [['presale_discount_percent' => null]]))->toBe([]);
});

test('the two kinds of rule differ only by their flag', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();

    expect(StayFixtures::rate($fishery)->kind)->toBe(PriceRuleKind::Rate)
        ->and(StayFixtures::surcharge($fishery)->kind)->toBe(PriceRuleKind::Surcharge);
});

test('a soft deleted rule stops taking part in pricing but stays in the table', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00);
    $surcharge = StayFixtures::surcharge($fishery, 20.00);

    expect(StayFixtures::pricing($position)->breakdown('2026-05-01', 1)->totalInCents())->toBe(9000);

    $surcharge->delete();

    expect(StayFixtures::pricing($position)->breakdown('2026-05-01', 1)->totalInCents())->toBe(7000)
        ->and(PriceRule::withTrashed()->whereKey($surcharge->id)->count())->toBe(1);
});

test('deleting a fishery for good takes its price rules with it', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    $rule = StayFixtures::rate($fishery, 70.00);

    $fishery->forceDelete();

    expect(PriceRule::withTrashed()->whereKey($rule->id)->count())->toBe(0);
});
