<?php

namespace Tests\Feature;

use App\Enums\PriceRuleKind;
use App\Enums\SurchargeAudience;
use App\Models\PriceRule;
use App\Rules\PresaleDiscountIsPercentage;
use App\Rules\PresaleWindowsAreOrdered;
use App\Rules\PriceRuleDatesAreOrdered;
use App\Rules\StayLengthRangeIsOrdered;
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
    expect($rate->coversDay($inside->startsOn))->toBeTrue()
        ->and($rate->coversDay($outside->startsOn))->toBeFalse()
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

    expect($rate->coversDay($night->startsOn))->toBeFalse()
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

/*
 * Testy dopisane po mutacjach zadania 023.
 */

/**
 * ⚠️ Kwoty z bazy są łańcuchami dziesiętnymi, a ich iloczyn przez 100 w zmiennym przecinku
 * wypada TUŻ OBOK liczby całkowitej (0,29 → 28,999…, 1,10 → 110,000…1). Grosze muszą
 * wychodzić przez zaokrąglenie do najbliższej, nie w dół ani w górę.
 */
test('kwoty zamieniają się na grosze bez błędu zmiennego przecinka', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();

    $low = StayFixtures::rate($fishery, 0.29, ['amount_companion' => 0.29])->fresh();
    $high = StayFixtures::rate($fishery, 1.10, ['amount_companion' => 1.10])->fresh();

    expect($low->amountInCents())->toBe(29)
        ->and($low->companionAmountInCents())->toBe(29)
        ->and($high->amountInCents())->toBe(110)
        ->and($high->companionAmountInCents())->toBe(110);
});

test('dni tygodnia dopłaty zapisane jako łańcuchy wracają jako liczby, w kolejnej liście', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    $surcharge = StayFixtures::surcharge($fishery, 20.00, 'Weekend', ['weekdays' => [3 => '5', 7 => '6']]);

    expect($surcharge->fresh()->weekdayNumbers())->toBe([5, 6]);
});

test('zakres dat reguły jako tekst', function () {
    app()->setLocale('pl');

    expect(PriceRule::periodText('2026-07-01', '2026-08-31'))->toBe('2026-07-01–2026-08-31')
        ->and(PriceRule::periodText('2026-01-01', null))->toBe('od 2026-01-01')
        ->and(PriceRule::periodText(null, '2026-12-31'))->toBe('do 2026-12-31')
        ->and(PriceRule::periodText(null, null))->toBeNull();
});

/**
 * ⚠️ Reguły kolejności dat porównują SAME DATY (pierwsze 10 znaków), więc data z godziną,
 * ten sam dzień i daty różniące się ostatnią cyfrą muszą dawać właściwy wynik.
 */
test('reguły kolejności dat porównują pełne daty, bez godzin', function (object $rule, string $from, string $to) {
    $row = fn (?string $a, ?string $b): array => [[$from => $a, $to => $b]];

    // Ten sam dzień jest poprawny — również gdy jedna z dat niesie godzinę.
    expect(priceRuleFailures($rule, $row('2026-01-10', '2026-01-10')))->toBe([])
        ->and(priceRuleFailures($rule, $row('2026-01-10 00:00:00', '2026-01-10')))->toBe([])
        // Różnica wyłącznie na ostatniej cyfrze dnia.
        ->and(priceRuleFailures($rule, $row('2026-01-15', '2026-01-19')))->toBe([])
        ->and(priceRuleFailures($rule, $row('2026-01-19', '2026-01-10')))->toHaveCount(1)
        // Niepełna para to „bez granicy", nie błąd.
        ->and(priceRuleFailures($rule, $row('2026-01-19', null)))->toBe([])
        ->and(priceRuleFailures($rule, $row(null, '2026-01-10')))->toBe([]);
})->with([
    'okno przedsprzedaży' => [new PresaleWindowsAreOrdered, 'presale_opens_on', 'presale_closes_on'],
    'daty reguły cenowej' => [new PriceRuleDatesAreOrdered, 'first_day_on', 'last_day_on'],
]);

test('obniżka nienumeryczna kończy sprawdzanie jednym komunikatem', function () {
    expect(priceRuleFailures(new PresaleDiscountIsPercentage, [
        ['presale_discount_percent' => 'abc'],
        ['presale_discount_percent' => 150],
    ]))->toHaveCount(1);
});

test('najdłuższy pobyt równy najkrótszemu jest poprawny, a pusty nie jest porównywany', function () {
    $failures = function (mixed $max, mixed $min): array {
        $failures = [];
        (new StayLengthRangeIsOrdered($min))->validate('max_nights', $max, function (string $message) use (&$failures): void {
            $failures[] = $message;
        });

        return $failures;
    };

    expect($failures(3, 3))->toBe([])
        ->and($failures(null, 3))->toBe([])
        ->and($failures(5, null))->toBe([])
        ->and($failures(2, 3))->toHaveCount(1);
});
