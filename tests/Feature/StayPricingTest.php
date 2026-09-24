<?php

use App\Enums\ParticipantRole;
use App\Enums\PriceRuleKind;
use App\Enums\PricingFailure;
use App\Enums\SaleUnavailabilityReason;
use App\Enums\SurchargeAudience;
use App\Models\PriceRule;
use App\Services\StayNightPrice;
use App\Services\StayPriceItem;
use App\Services\StayPricing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Tests\Support\StayFixtures;

/**
 * Wycena pobytu po przedefiniowaniu zadania 018 (ADR-014, sekcja „Aktualizacja").
 *
 * Kalendarz odniesienia 2026: 04.05 pon · 06.05 śr · 07.05 czw · 08.05 pt · 09.05 sob ·
 * 10.05 nd · 11.05 pon.
 */

/**
 * Pozycje rozbicia danej roli i rodzaju — asercje idą po POZYCJACH, nie po samej sumie.
 *
 * @return array<int, StayPriceItem>
 */
function itemsOf(StayNightPrice $night, ParticipantRole $role, PriceRuleKind $kind): array
{
    return array_values(array_filter(
        $night->items,
        static fn (StayPriceItem $item): bool => $item->role === $role && $item->kind === $kind,
    ));
}

test('Łopienno: jedna stawka i jedna dopłata dają 430,00 zł za pięć dób od czwartku', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();

    StayFixtures::rate($fishery, 70.00);
    StayFixtures::surcharge($fishery, 20.00, 'Stanowisko tylko dla Ciebie', [
        'weekdays' => [4, 5, 6, 7],
        'anglers_count' => 1,
        'applies_to' => SurchargeAudience::Angler->value,
    ]);

    // 07.05 czw, 08.05 pt, 09.05 sob, 10.05 nd, 11.05 pon — dopłata w czterech pierwszych.
    $breakdown = StayFixtures::pricing($position)->breakdown('2026-05-07', 5);

    expect($breakdown->totalInCents())->toBe(43000);
});

test('dopłata za wyłączność NIE nalicza się przy dwóch łowiących', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();

    StayFixtures::rate($fishery, 70.00);
    StayFixtures::surcharge($fishery, 20.00, 'Wylacznosc', [
        'weekdays' => [4, 5, 6, 7],
        'anglers_count' => 1,
    ]);

    $breakdown = StayFixtures::pricing($position)->breakdown('2026-05-07', 1, anglers: 2);

    expect($breakdown->totalInCents())->toBe(14000)
        ->and($breakdown->totalInCents())->not->toBe(18000);
});

test('dopłata „dla łowiącego" nie dotyka osoby towarzyszącej', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();

    StayFixtures::rate($fishery, 70.00);
    StayFixtures::surcharge($fishery, 20.00, 'Wylacznosc', [
        'anglers_count' => 1,
        'applies_to' => SurchargeAudience::Angler->value,
    ]);

    $breakdown = StayFixtures::pricing($position)->breakdown('2026-05-07', 1, anglers: 1, companions: 1);

    expect($breakdown->totalInCents())->toBe(9000)
        ->and($breakdown->totalInCents())->not->toBe(11000);
});

test('trzy warianty „dla kogo" dają trzy różne ROZBICIA, choć dwa z nich tę samą sumę', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();

    StayFixtures::rate($fishery, 70.00);
    $surcharge = StayFixtures::surcharge($fishery, 20.00, 'Doplata');

    $nightFor = function (SurchargeAudience $audience) use ($surcharge, $position) {
        $surcharge->update(['applies_to' => $audience->value]);

        return StayFixtures::pricing($position)->breakdown('2026-05-07', 1, anglers: 1, companions: 1)->nights[0];
    };

    $everyone = $nightFor(SurchargeAudience::Everyone);
    expect(itemsOf($everyone, ParticipantRole::Angler, PriceRuleKind::Surcharge))->toHaveCount(1)
        ->and(itemsOf($everyone, ParticipantRole::Companion, PriceRuleKind::Surcharge))->toHaveCount(1)
        ->and($everyone->subtotalInCents())->toBe(11000);

    $angler = $nightFor(SurchargeAudience::Angler);
    expect(itemsOf($angler, ParticipantRole::Angler, PriceRuleKind::Surcharge))->toHaveCount(1)
        ->and(itemsOf($angler, ParticipantRole::Companion, PriceRuleKind::Surcharge))->toHaveCount(0)
        ->and($angler->subtotalInCents())->toBe(9000);

    $companion = $nightFor(SurchargeAudience::Companion);
    expect(itemsOf($companion, ParticipantRole::Angler, PriceRuleKind::Surcharge))->toHaveCount(0)
        ->and(itemsOf($companion, ParticipantRole::Companion, PriceRuleKind::Surcharge))->toHaveCount(1)
        // ⚠️ Ta sama SUMA co przy „dla łowiącego" — różni je wyłącznie pozycja w rozbiciu.
        ->and($companion->subtotalInCents())->toBe(9000);
});

test('domyślną wartością „dla kogo" jest łowiący, więc dopłata nie obciąża towarzyszącej', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();

    StayFixtures::rate($fishery, 70.00);
    // Fabryka dopłaty nie dotyka `applies_to` poza wartością domyślną.
    $surcharge = StayFixtures::surcharge($fishery, 20.00, 'Doplata');

    expect($surcharge->applies_to)->toBe(SurchargeAudience::Angler);

    $breakdown = StayFixtures::pricing($position)->breakdown('2026-05-07', 1, anglers: 1, companions: 1);

    expect($breakdown->totalInCents())->toBe(9000);
});

test('pusta kolumna „dla kogo" też znaczy łowiący — nie każdego', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();

    StayFixtures::rate($fishery, 70.00);
    StayFixtures::surcharge($fishery, 20.00, 'Doplata', ['applies_to' => null]);

    $breakdown = StayFixtures::pricing($position)->breakdown('2026-05-07', 1, anglers: 1, companions: 1);

    expect($breakdown->totalInCents())->toBe(9000);
});

test('wycena odrzuca skład bez ani jednego łowiącego', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00);

    StayFixtures::pricing($position)->breakdown('2026-05-07', 1, anglers: 0, companions: 1);
})->throws(InvalidArgumentException::class);

test('Klasztorne: zawieszona dopłata nie wchodzi do wyceny, ale zostaje w cenniku', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();

    StayFixtures::rate($fishery, 130.00);
    $suspended = StayFixtures::surcharge($fishery, 40.00, 'Wylacznosc', [
        'first_day_on' => '2026-04-26',
        'last_day_on' => '2026-11-30',
        'anglers_count' => 1,
        'is_suspended' => true,
    ]);

    $breakdown = StayFixtures::pricing($position)->breakdown('2026-05-07', 1);

    expect($breakdown->totalInCents())->toBe(13000)
        ->and($suspended->fresh())->not->toBeNull();

    // Włącza się bez wpisywania od nowa.
    $suspended->update(['is_suspended' => false]);

    expect(StayFixtures::pricing($position)->breakdown('2026-05-07', 1)->totalInCents())->toBe(17000);
});

test('osoba towarzysząca jest KOLUMNĄ na stawce, nie osobną regułą', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();

    StayFixtures::rate($fishery, 70.00);

    $breakdown = StayFixtures::pricing($position)->breakdown('2026-05-07', 1, anglers: 1, companions: 1);
    $night = $breakdown->nights[0];

    expect($breakdown->totalInCents())->toBe(7000)
        ->and($fishery->priceRules()->count())->toBe(1)
        ->and(itemsOf($night, ParticipantRole::Companion, PriceRuleKind::Rate))->toHaveCount(1)
        ->and(itemsOf($night, ParticipantRole::Companion, PriceRuleKind::Rate)[0]->amountInCents())->toBe(0);
});

test('płatna osoba towarzysząca to po prostu inna kwota w tej samej kolumnie', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();

    PriceRule::factory()->amount(70.00)->companionAmount(30.00)->create(['fishery_id' => $fishery->id]);

    expect(StayFixtures::pricing($position)->breakdown('2026-05-07', 1, anglers: 1, companions: 1)->totalInCents())
        ->toBe(10000);
});

test('brak kwoty za osobę towarzyszącą to ODMOWA, i to inna niż dziura w cenniku', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();

    PriceRule::factory()->amount(70.00)->companionAmount(null)->create(['fishery_id' => $fishery->id]);

    $withCompanion = StayFixtures::pricing($position)->breakdown('2026-05-07', 1, anglers: 1, companions: 1);

    expect($withCompanion->isPriced())->toBeFalse()
        ->and($withCompanion->failure)->toBe(PricingFailure::NoCompanionPrice)
        ->and($withCompanion->failure)->not->toBe(PricingFailure::NoMatchingRate);

    // To samo zapytanie BEZ osoby towarzyszącej wycenia się normalnie.
    expect(StayFixtures::pricing($position)->breakdown('2026-05-07', 1)->totalInCents())->toBe(7000);
});

test('warstwa oferty rozróżnia oba powody odmowy', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();

    PriceRule::factory()->amount(70.00)->companionAmount(null)->create(['fishery_id' => $fishery->id]);

    $verdict = StayFixtures::offer($position)->offer('2026-05-07', 1, anglers: 1, companions: 1);

    expect($verdict->available)->toBeFalse()
        ->and($verdict->reason)->toBe(SaleUnavailabilityReason::NoCompanionPrice)
        ->and($verdict->reason)->not->toBe(SaleUnavailabilityReason::NoPriceDefined);
});

test('stawka nie zna dni tygodnia — różnicowanie ceny dniami robi się dopłatą', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();

    StayFixtures::rate($fishery, 70.00);
    StayFixtures::surcharge($fishery, 20.00, 'Weekend', ['weekdays' => [5, 6]]);

    // 08.05 piątek, 11.05 poniedziałek.
    expect(StayFixtures::pricing($position)->breakdown('2026-05-08', 1)->totalInCents())->toBe(9000)
        ->and(StayFixtures::pricing($position)->breakdown('2026-05-11', 1)->totalInCents())->toBe(7000);
});

test('warunek dopłaty liczy się osobno dla każdej doby, nie dla całego pobytu', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();

    StayFixtures::rate($fishery, 70.00);
    StayFixtures::surcharge($fishery, 20.00, 'Weekend', ['weekdays' => [4, 5, 6, 7]]);

    // Śr 06.05 → czw 07.05 → pt 08.05: dopłata w dwóch ostatnich dobach.
    $breakdown = StayFixtures::pricing($position)->breakdown('2026-05-06', 3);

    expect($breakdown->totalInCents())->toBe(25000)
        ->and($breakdown->nights[0]->subtotalInCents())->toBe(7000)
        ->and($breakdown->nights[1]->subtotalInCents())->toBe(9000)
        ->and($breakdown->nights[2]->subtotalInCents())->toBe(9000);
});

test('nachodzące stawki rozstrzygają się na korzyść wędkarza', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();

    StayFixtures::rate($fishery, 70.00, ['first_day_on' => '2026-01-01']);
    StayFixtures::rate($fishery, 50.00, ['first_day_on' => '2026-05-01', 'last_day_on' => '2026-05-31']);

    expect(StayFixtures::pricing($position)->breakdown('2026-05-10', 1)->totalInCents())->toBe(5000)
        ->and(StayFixtures::pricing($position)->breakdown('2026-06-10', 1)->totalInCents())->toBe(7000);
});

test('rozbicie wskazuje regułę, która wygrała', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();

    StayFixtures::rate($fishery, 90.00);
    $cheap = StayFixtures::rate($fishery, 70.00);

    $night = StayFixtures::pricing($position)->breakdown('2026-05-10', 1)->nights[0];

    expect(itemsOf($night, ParticipantRole::Angler, PriceRuleKind::Rate)[0]->priceRuleId)->toBe($cheap->id);
});

test('dopłaty sumują się i widać je w rozbiciu osobno', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();

    StayFixtures::rate($fishery, 70.00);
    StayFixtures::surcharge($fishery, 20.00, 'Pierwsza');
    StayFixtures::surcharge($fishery, 20.00, 'Druga');

    $night = StayFixtures::pricing($position)->breakdown('2026-05-10', 1)->nights[0];

    expect($night->subtotalInCents())->toBe(11000)
        ->and(itemsOf($night, ParticipantRole::Angler, PriceRuleKind::Surcharge))->toHaveCount(2);
});

test('doba bez pasującej stawki jest odmową, a nie ceną zerową', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();

    StayFixtures::rate($fishery, 70.00, ['first_day_on' => '2026-06-01']);

    $breakdown = StayFixtures::pricing($position)->breakdown('2026-05-10', 1);

    expect($breakdown->isPriced())->toBeFalse()
        ->and($breakdown->failure)->toBe(PricingFailure::NoMatchingRate)
        ->and($breakdown->totalInCents())->toBe(0)
        ->and($breakdown->failedNight->toDateString())->toBe('2026-05-10');
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
        ->and($breakdown->nights[1]->discountInCents)->toBe(900)
        ->and($breakdown->totalInCents())->toBe(16200);

    Date::setTestNow();
});

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

test('wycena nie woła sprzedawalności — zmiana reguł pobytu nie zmienia ceny', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00);

    $before = StayFixtures::pricing($position)->breakdown('2026-05-10', 1)->totalInCents();

    $fishery->update(['min_nights' => 5, 'max_nights' => 7]);

    expect(StayFixtures::pricing($position)->breakdown('2026-05-10', 1)->totalInCents())->toBe($before);
});

/*
 * Testy dopisane po mutacjach zadania 023 — granice wejścia, pozycja osoby towarzyszącej
 * i warunki przedsprzedaży. Każdy celuje w mutanta, który przeżył pełny pakiet.
 */

test('wycena odrzuca zero dób i ujemną liczbę osób towarzyszących, ale przyjmuje jedną dobę', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00);
    $pricing = StayFixtures::pricing($position);

    expect(fn () => $pricing->breakdown('2026-05-07', 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $pricing->breakdown('2026-05-07', 1, companions: -1))->toThrow(InvalidArgumentException::class)
        ->and($pricing->breakdown('2026-05-07', 1)->totalInCents())->toBe(7000);
});

test('bez godzin doby wycena rzuca czytelny wyjątek, a nie błąd typu', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition(['day_start_time' => null, 'day_end_time' => null]);
    StayFixtures::rate($fishery, 70.00);

    expect(fn () => StayFixtures::pricing($position)->breakdown('2026-05-07', 1))
        ->toThrow(InvalidArgumentException::class, 'has no fishing day configured');
});

test('bez osób towarzyszących rozbicie nie ma pozycji osoby towarzyszącej', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00, ['amount_companion' => 25.00]);

    $night = StayFixtures::pricing($position)->breakdown('2026-05-07', 1)->nights[0];

    expect($night->items)->toHaveCount(1)
        ->and(itemsOf($night, ParticipantRole::Companion, PriceRuleKind::Rate))->toBe([]);
});

test('doba poza okresem sprzedaży wycenia się bez obniżki i bez błędu', function () {
    Date::setTestNow(CarbonImmutable::parse('2026-01-10 09:00', 'Europe/Warsaw'));
    [$fishery, $position] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00);
    $fishery->salePeriods()->update([
        'presale_opens_on' => '2026-01-01',
        'presale_closes_on' => '2026-01-31',
        'presale_discount_percent' => 10.00,
    ]);

    // Wycena nie pyta o sprzedawalność, więc doba spoza okresu nadal ma cenę.
    $night = StayFixtures::pricing($position)->breakdown('2027-02-01', 1)->nights[0];

    expect($night->discountInCents)->toBe(0)
        ->and($night->discountPercent)->toBeNull()
        ->and($night->totalInCents())->toBe(7000);

    Date::setTestNow();
});

test('obniżka wymaga OBU: procentu i otwartego okna przedsprzedaży', function () {
    Date::setTestNow(CarbonImmutable::parse('2026-01-10 09:00', 'Europe/Warsaw'));
    [$fishery, $position] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00);

    // Okno otwarte, ale bez procentu.
    $fishery->salePeriods()->update([
        'presale_opens_on' => '2026-01-01',
        'presale_closes_on' => '2026-01-31',
        'presale_discount_percent' => null,
    ]);
    $withoutPercent = StayFixtures::pricing($position)->breakdown('2026-05-07', 1)->nights[0];

    // Procent jest, ale okno już zamknięte.
    $fishery->salePeriods()->update([
        'presale_opens_on' => '2025-12-01',
        'presale_closes_on' => '2025-12-31',
        'presale_discount_percent' => 10.00,
    ]);
    $closedWindow = StayFixtures::pricing($position)->breakdown('2026-05-07', 1)->nights[0];

    expect($withoutPercent->discountInCents)->toBe(0)
        ->and($withoutPercent->discountPercent)->toBeNull()
        ->and($closedWindow->discountInCents)->toBe(0)
        ->and($closedWindow->discountPercent)->toBeNull();

    Date::setTestNow();
});

test('podstawą obniżki jest dokładnie suma pozycji doby — bez przesunięcia o grosz', function () {
    Date::setTestNow(CarbonImmutable::parse('2026-01-10 09:00', 'Europe/Warsaw'));
    [$fishery, $position] = StayFixtures::fisheryWithPosition();
    $fishery->salePeriods()->update([
        'presale_opens_on' => '2026-01-01',
        'presale_closes_on' => '2026-01-31',
        'presale_discount_percent' => 10.00,
    ]);

    // 14 gr → 1,4 gr → 1 gr; 15 gr → 1,5 gr → 2 gr. Granica połówki odróżnia podstawę o grosz.
    $rate = StayFixtures::rate($fishery, 0.14);
    $fourteen = StayFixtures::pricing($position)->breakdown('2026-05-07', 1)->nights[0];

    $rate->update(['amount' => 0.15]);
    $fifteen = StayFixtures::pricing($position)->breakdown('2026-05-07', 1)->nights[0];

    expect($fourteen->discountInCents)->toBe(1)
        ->and($fifteen->discountInCents)->toBe(2)
        ->and($fifteen->discountPercent)->toBe('10.00');

    Date::setTestNow();
});

test('arytmetyka obniżki: setne części procenta, dzielnik i progi zera', function () {
    // Procent z ułamkiem, którego iloczyn przez 100 w zmiennym przecinku wypada TUŻ OBOK
    // liczby całkowitej — zaokrąglenie musi być do najbliższej, nie w dół ani w górę.
    expect(StayPricing::discountInCents(10000, '0.29'))->toBe(29)
        ->and(StayPricing::discountInCents(10000, '1.10'))->toBe(110)
        // Najmniejszy dodatni procent i najmniejsza dodatnia podstawa nadal dają obniżkę.
        ->and(StayPricing::discountInCents(1_000_000, '0.01'))->toBe(100)
        ->and(StayPricing::discountInCents(1, '100.00'))->toBe(1)
        // Pełna podstawa przy 100% — pilnuje dzielnika.
        ->and(StayPricing::discountInCents(1_000_000, '100.00'))->toBe(1_000_000)
        // Procent ujemny i zerowy nie dają obniżki (ani dopłaty).
        ->and(StayPricing::discountInCents(1000, '-10.00'))->toBe(0)
        ->and(StayPricing::discountInCents(1000, '0.00'))->toBe(0)
        ->and(StayPricing::discountInCents(0, '10.00'))->toBe(0);
});

test('wycena z cennikiem podanym z zewnątrz nie czyta go z bazy', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00);

    $rules = $fishery->priceRules()->get()->all();
    $pricing = new StayPricing($position->fresh(), $rules);
    $priceRuleQueries = 0;

    DB::listen(function ($query) use (&$priceRuleQueries): void {
        if (str_contains($query->sql, 'from `price_rules`')) {
            $priceRuleQueries++;
        }
    });

    expect($pricing->breakdown('2026-05-07', 2)->totalInCents())->toBe(14000)
        ->and($priceRuleQueries)->toBe(0);
});
