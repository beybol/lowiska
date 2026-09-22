<?php

namespace Tests\Feature;

use App\Enums\ParticipantRole;
use App\Enums\PriceRuleKind;
use App\Enums\PricingFailure;
use App\Models\Position;
use App\Services\StayPricing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Tests\Support\StayFixtures;

/**
 * Wycena pobytu: **rozbicie**, nie jedna liczba (ADR-014).
 *
 * ⚠️ Kwoty w groszach, w liczbach całkowitych — obniżka przedsprzedażowa zaokrągla się raz
 * na dobę i musi odróżnić 14,01 zł od 14,02 zł.
 *
 * Kalendarz odniesienia (2026): 29.04 śr · **30.04 czw** · 01.05 pt · 02.05 sob · 03.05 nd ·
 * 04.05 pon.
 */

/**
 * ⚠️ Odwzorowanie ŁOPIENNA — najważniejszy test tego pliku, bo pinuje cały łańcuch:
 * stawkę bazową, dopłatę warunkową liczoną per doba i warunek roli.
 */
test('lopienno is reproduced by one base rate and one conditional surcharge', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();

    StayFixtures::rate($fishery, 70.00);
    StayFixtures::surcharge($fishery, 20.00, 'Stanowisko tylko dla Ciebie', [
        'anglers_count' => 1,
        'weekdays' => [4, 5, 6, 7],
        'participant_role' => ParticipantRole::Angler->value,
    ]);

    // Pobyt jednej osoby od czwartku na 5 dób: czw, pt, sob, nd dostają dopłatę, pon nie.
    $breakdown = StayFixtures::pricing($position)->breakdown('2026-04-30', 5, anglers: 1);

    expect($breakdown->isPriced())->toBeTrue()
        ->and($breakdown->totalInCents())->toBe(43000)
        ->and($breakdown->nights)->toHaveCount(5);

    $nightTotals = array_map(
        fn ($night): int => $night->totalInCents(),
        $breakdown->nights,
    );

    // Rozbicie pokazuje, KTÓRA doba niesie dopłatę — bez tego operator nie wie, skąd 430 zł.
    expect($nightTotals)->toBe([9000, 9000, 9000, 9000, 7000]);
});

test('klasztorne is reproduced with a suspended surcharge left in the list', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();

    StayFixtures::rate($fishery, 130.00);
    $suspended = StayFixtures::surcharge($fishery, 30.00, 'Wylacznosc', ['is_suspended' => true]);

    expect(StayFixtures::pricing($position)->breakdown('2026-05-01', 2)->totalInCents())->toBe(26000);

    // Reguła zostaje w cenniku i daje się włączyć bez wpisywania od nowa.
    $suspended->update(['is_suspended' => false]);

    expect(StayFixtures::pricing($position)->breakdown('2026-05-01', 2)->totalInCents())->toBe(32000);
});

/**
 * ⚠️ Warunek liczy się osobno dla KAŻDEJ doby (K3/P2), nie „całe albo wcale".
 */
test('a condition is evaluated per night, not per stay', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();

    StayFixtures::rate($fishery, 70.00);
    StayFixtures::surcharge($fishery, 20.00, 'Weekend', ['weekdays' => [4, 5, 6, 7]]);

    // Pobyt śr–pt: dopłata za czwartek i piątek, nie za środę.
    $breakdown = StayFixtures::pricing($position)->breakdown('2026-04-29', 3);

    expect(array_map(fn ($night): int => $night->totalInCents(), $breakdown->nights))
        ->toBe([7000, 9000, 9000]);
});

/**
 * ⚠️ Osoba towarzysząca NIE jest gałęzią w kodzie — to reguła `rate` z warunkiem roli
 * i kwotą 0,00 (O15).
 */
test('a companion is priced by a rule, not by a branch in the code', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();

    StayFixtures::rate($fishery, 70.00);
    StayFixtures::rate($fishery, 0.00, [
        'participant_role' => ParticipantRole::Companion->value,
        'priority' => 100,
        'label' => 'Osoba towarzyszaca',
    ]);

    $breakdown = StayFixtures::pricing($position)->breakdown('2026-05-01', 1, anglers: 1, companions: 1);

    expect($breakdown->totalInCents())->toBe(7000)
        ->and($breakdown->items())->toHaveCount(2);
});

/**
 * ⚠️ Dopłata BEZ warunku roli obciąża także osobę towarzyszącą — i to jest zachowanie
 * POPRAWNE, nie defekt. Różnica bierze się z konfiguracji, a nie z kodu, więc oba przypadki
 * mają test.
 */
test('a surcharge without a role condition also charges the companion', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();

    StayFixtures::rate($fishery, 70.00);
    StayFixtures::rate($fishery, 0.00, [
        'participant_role' => ParticipantRole::Companion->value,
        'priority' => 100,
    ]);
    $surcharge = StayFixtures::surcharge($fishery, 20.00, 'Wylacznosc', [
        'anglers_count' => 1,
        'weekdays' => [4, 5, 6, 7],
    ]);

    // 70 + 20 (łowiący) + 0 + 20 (towarzysząca) = 110,00 zł.
    expect(StayFixtures::pricing($position)->breakdown('2026-04-30', 1, 1, 1)->totalInCents())
        ->toBe(11000);

    $surcharge->update(['participant_role' => ParticipantRole::Angler->value]);

    // Po dołożeniu warunku roli: tyle, co pobyt samego łowiącego.
    expect(StayFixtures::pricing($position)->breakdown('2026-04-30', 1, 1, 1)->totalInCents())
        ->toBe(9000);
});

test('the breakdown carries items per night and per role with the rule behind them', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();

    $rate = StayFixtures::rate($fishery, 90.00);
    $surcharge = StayFixtures::surcharge($fishery, 20.00, 'Wylacznosc');

    $items = StayFixtures::pricing($position)->breakdown('2026-05-01', 1)->items();

    expect($items)->toHaveCount(2)
        ->and($items[0]->kind)->toBe(PriceRuleKind::Rate)
        ->and($items[0]->priceRuleId)->toBe($rate->id)
        ->and($items[0]->role)->toBe(ParticipantRole::Angler)
        ->and($items[0]->night->toDateString())->toBe('2026-05-01')
        ->and($items[1]->kind)->toBe(PriceRuleKind::Surcharge)
        ->and($items[1]->label)->toBe('Wylacznosc')
        ->and($items[1]->priceRuleId)->toBe($surcharge->id);
});

test('a gap in the price list is reported with the night, role and party size', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 90.00, ['weekdays' => [5]]);

    $breakdown = StayFixtures::pricing($position)->breakdown('2026-04-30', 1, anglers: 2);

    expect($breakdown->isPriced())->toBeFalse()
        ->and($breakdown->failure)->toBe(PricingFailure::NoMatchingRate)
        ->and($breakdown->failedNight?->toDateString())->toBe('2026-04-30')
        ->and($breakdown->failedRole)->toBe(ParticipantRole::Angler)
        ->and($breakdown->failedAnglersCount)->toBe(2);
});

test('an unresolvable tie comes back as a result, never as an exception', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00, ['weekdays' => [5]]);
    StayFixtures::rate($fishery, 90.00, ['first_day_on' => '2026-04-01', 'last_day_on' => '2026-06-30']);

    $breakdown = StayFixtures::pricing($position)->breakdown('2026-05-01', 1);

    expect($breakdown->isPriced())->toBeFalse()
        ->and($breakdown->failure)->toBe(PricingFailure::UnresolvableTie);
});

/**
 * ⚠️ Wycena NIE woła `StaySellability` i nie powtarza warunków sprzedawalności — zmiana reguł
 * pobytu z 017 nie ma prawa zmienić wyniku wyceny. To jest granica, nie przypadek.
 */
test('pricing does not depend on the stay rules from task 017', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00);

    $before = StayFixtures::pricing($position)->breakdown('2026-05-01', 1)->totalInCents();

    // Reguły, które w 017 czynią ten pobyt NIESPRZEDAWALNYM.
    $fishery->update(['min_nights' => 5, 'weekend_days' => [5, 6], 'sale_horizon_days' => 1]);
    StayFixtures::wholeTerm($fishery, '2026-04-30', 3);

    expect(StayFixtures::pricing($position)->breakdown('2026-05-01', 1)->totalInCents())
        ->toBe($before);
});

test('the presale discount is taken off each night separately', function () {
    Date::setTestNow(CarbonImmutable::parse('2026-01-10 09:00', 'Europe/Warsaw'));

    [$fishery, $position] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00);
    StayFixtures::surcharge($fishery, 20.00, 'Wylacznosc');

    $fishery->salePeriods()->update([
        'presale_opens_on' => '2026-01-01',
        'presale_closes_on' => '2026-01-31',
        'presale_discount_percent' => 10.00,
    ]);

    $breakdown = StayFixtures::pricing($position)->breakdown('2026-05-01', 2);

    // Podstawą jest stawka WRAZ z dopłatą: 90,00 zł → 81,00 zł.
    expect($breakdown->nights[0]->subtotalInCents())->toBe(9000)
        ->and($breakdown->nights[0]->discountInCents)->toBe(900)
        ->and($breakdown->nights[0]->totalInCents())->toBe(8100)
        // Obniżka jest widoczna PRZY KAŻDEJ dobie, nie tylko w sumie.
        ->and($breakdown->nights[1]->discountInCents)->toBe(900)
        ->and($breakdown->totalInCents())->toBe(16200);

    Date::setTestNow();
});

/**
 * ⚠️ **Przypadek rozstrzygający zaokrąglenie.** Kwota bez połówki grosza niczego by tu
 * nie sprawdziła: liczenie osobno na każdą osobę dałoby 7,005 → 7,01 i razem 14,02 zł,
 * a jedno zaokrąglenie na dobę daje 14,01 zł.
 */
test('the discount rounds once per night, from the sum of that nights items', function () {
    Date::setTestNow(CarbonImmutable::parse('2026-01-10 09:00', 'Europe/Warsaw'));

    [$fishery, $position] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.05);

    $fishery->salePeriods()->update([
        'presale_opens_on' => '2026-01-01',
        'presale_closes_on' => '2026-01-31',
        'presale_discount_percent' => 10.00,
    ]);

    $night = StayFixtures::pricing($position)->breakdown('2026-05-01', 1, anglers: 2)->nights[0];

    expect($night->subtotalInCents())->toBe(14010)
        ->and($night->discountInCents)->toBe(1401)
        ->and($night->discountInCents)->not->toBe(1402);

    // Ta sama zasada wprost na metodzie liczącej — połówki w górę.
    expect(StayPricing::discountInCents(7005, '10.00'))->toBe(701);

    Date::setTestNow();
});

test('the discount applies only inside an open window and only to nights of that period', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00);

    $fishery->salePeriods()->update([
        'presale_opens_on' => '2026-01-01',
        'presale_closes_on' => '2026-01-31',
        'presale_discount_percent' => 10.00,
    ]);

    // Okno otwarte — obniżka działa.
    Date::setTestNow(CarbonImmutable::parse('2026-01-10 09:00', 'Europe/Warsaw'));
    expect(StayFixtures::pricing($position)->breakdown('2026-05-01', 1)->totalInCents())->toBe(6300);

    // Okno zamknięte — pełna cena.
    Date::setTestNow(CarbonImmutable::parse('2026-02-10 09:00', 'Europe/Warsaw'));
    expect(StayFixtures::pricing($position)->breakdown('2026-05-01', 1)->totalInCents())->toBe(7000);

    Date::setTestNow();
});

test('a position without a fishery has no price', function () {
    $position = Position::factory()->create(['fishery_id' => null]);

    expect(fn () => new StayPricing($position))->toThrow(\InvalidArgumentException::class);
});
