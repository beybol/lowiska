<?php

namespace Tests\Feature;

use App\Enums\PriceRuleKind;
use App\Enums\SurchargeAudience;
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

test('stawka nie ma zadnych warunkow poza datami', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();

    $rate = StayFixtures::rate($fishery, 70.00, ['first_day_on' => '2026-05-01', 'last_day_on' => '2026-05-31']);
    $calendar = new FishingDayCalendar($fishery->fresh());

    $inside = $calendar->dayStartingOn(CarbonImmutable::parse('2026-05-10', 'Europe/Warsaw'));
    $outside = $calendar->dayStartingOn(CarbonImmutable::parse('2026-06-10', 'Europe/Warsaw'));

    // Kolumny dopłaty na stawce nie istnieją i nie wolno ich czytać przy dopasowaniu.
    expect($rate->coversNight($inside))->toBeTrue()
        ->and($rate->coversNight($outside))->toBeFalse()
        ->and($rate->weekdays)->toBeNull()
        ->and($rate->anglers_count)->toBeNull()
        ->and($rate->applies_to)->toBeNull();
});

test('warunek obsady dopłaty to rownosc z faktyczna liczba lowiacych', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();

    $surcharge = StayFixtures::surcharge($fishery, 20.00, 'Solo', ['anglers_count' => 1]);
    $night = (new FishingDayCalendar($fishery->fresh()))
        ->dayStartingOn(CarbonImmutable::parse('2026-05-10', 'Europe/Warsaw'));

    expect($surcharge->appliesToNight($night, 1))->toBeTrue()
        ->and($surcharge->appliesToNight($night, 2))->toBeFalse();
});

test('warunek dni tygodnia dopłaty patrzy na dzien ROZPOCZECIA doby', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();

    // ISO 5 = piatek; 2026-05-01 to piatek, 2026-05-02 to sobota.
    $surcharge = StayFixtures::surcharge($fishery, 20.00, 'Piatki', ['weekdays' => [5]]);
    $calendar = new FishingDayCalendar($fishery->fresh());

    $friday = $calendar->dayStartingOn(CarbonImmutable::parse('2026-05-01', 'Europe/Warsaw'));
    $saturday = $calendar->dayStartingOn(CarbonImmutable::parse('2026-05-02', 'Europe/Warsaw'));

    expect($surcharge->appliesToNight($friday, 1))->toBeTrue()
        ->and($surcharge->appliesToNight($saturday, 1))->toBeFalse();
});

test('reguła zawieszona nie obowiazuje w zadnej dobie', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();

    $rate = PriceRule::factory()->suspended()->create(['fishery_id' => $fishery->id]);
    $surcharge = StayFixtures::surcharge($fishery, 20.00, 'Zawieszona', ['is_suspended' => true]);

    $night = (new FishingDayCalendar($fishery->fresh()))
        ->dayStartingOn(CarbonImmutable::parse('2026-05-10', 'Europe/Warsaw'));

    expect($rate->coversNight($night))->toBeFalse()
        ->and($surcharge->appliesToNight($night, 1))->toBeFalse();
});

test('kwota za osobe towarzyszaca: zero to cena, null to BRAK ceny', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();

    $free = PriceRule::factory()->companionAmount(0.00)->create(['fishery_id' => $fishery->id]);
    $undefined = PriceRule::factory()->companionAmount(null)->create(['fishery_id' => $fishery->id]);

    expect($free->companionAmountInCents())->toBe(0)
        ->and($undefined->companionAmountInCents())->toBeNull();
});

test('dopłata wie, przez ilu osob sie mnozy', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();

    $everyone = StayFixtures::surcharge($fishery, 20.00, 'Kazdy', ['applies_to' => SurchargeAudience::Everyone->value]);
    $angler = StayFixtures::surcharge($fishery, 20.00, 'Lowiacy', ['applies_to' => SurchargeAudience::Angler->value]);
    $companion = StayFixtures::surcharge($fishery, 20.00, 'Towarzyszacy', ['applies_to' => SurchargeAudience::Companion->value]);
    $unset = StayFixtures::surcharge($fishery, 20.00, 'Bez wartosci', ['applies_to' => null]);

    expect($everyone->chargeableHeadcount(2, 1))->toBe(3)
        ->and($angler->chargeableHeadcount(2, 1))->toBe(2)
        ->and($companion->chargeableHeadcount(2, 1))->toBe(1)
        // Pusta kolumna zachowuje sie jak „dla lowiacego", nie jak „dla kazdego".
        ->and($unset->chargeableHeadcount(2, 1))->toBe(2);
});

test('stawka bezterminowa rozpoznaje sie po braku daty konca', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();

    expect(StayFixtures::rate($fishery, 70.00, ['first_day_on' => '2026-01-01'])->isOpenEnded())->toBeTrue()
        ->and(StayFixtures::rate($fishery, 70.00, ['last_day_on' => '2026-12-31'])->isOpenEnded())->toBeFalse();
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

test('okres reguly nie moze konczyc sie przed swoim poczatkiem', function () {
    $rule = new PriceRuleDatesAreOrdered;

    expect(priceRuleFailures($rule, [['first_day_on' => '2026-06-01', 'last_day_on' => '2026-05-01']]))
        ->toHaveCount(1)
        // Otwarty koniec jest poprawny — znaczy „bez granicy".
        ->and(priceRuleFailures($rule, [['first_day_on' => '2026-06-01', 'last_day_on' => null]]))
        ->toBe([])
        ->and(priceRuleFailures($rule, [['first_day_on' => null, 'last_day_on' => '2026-05-01']]))
        ->toBe([])
        // Kontrola pozytywna.
        ->and(priceRuleFailures($rule, [['first_day_on' => '2026-05-01', 'last_day_on' => '2026-05-31']]))
        ->toBe([]);
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
