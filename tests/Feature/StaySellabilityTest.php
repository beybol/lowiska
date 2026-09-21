<?php

namespace Tests\Feature;

use App\Enums\PositionStatus;
use App\Enums\SaleUnavailabilityReason;
use App\Models\Position;
use App\Services\StaySellability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Tests\Support\StayFixtures;

/**
 * Reguły POBYTU — ciągu dób kupowanego razem (ADR-013, zadanie 017).
 *
 * ⚠️ Testy pilnują rzeczy, której nie widać po samym „przechodzi / nie przechodzi":
 * **kolejności warunków**. Jest ona umową produktową, nie szczegółem implementacji —
 * przy zlanym pakiecie krótszy pobyt musi odpaść jako „przerwany pakiet", a NIE jako
 * „za krótki", bo tylko pierwszy komunikat mówi wędkarzowi, co zrobić.
 *
 * ⚠️ Horyzont i okno przedsprzedaży zależą od „dzisiaj", więc każdy dotykający ich
 * test **zamraża czas**. Bez tego pakiet zielenieje albo czerwienieje zależnie od dnia
 * uruchomienia.
 *
 * Kalendarz odniesienia (2026): 30.04 to czwartek, 01.05 piątek, 02.05 sobota,
 * 03.05 niedziela, 04.05 poniedziałek.
 */
test('a fishery without rules sells a stay of any length', function () {
    [, $position] = StayFixtures::fisheryWithPosition();

    foreach ([1, 3, 14] as $nights) {
        expect(StayFixtures::stay($position)->isSellable('2026-06-10', $nights))->toBeTrue();
    }
});

test('a stay shorter or longer than the fishery allows is refused with its own reason', function () {
    [, $position] = StayFixtures::fisheryWithPosition(['min_nights' => 3, 'max_nights' => 7]);

    $tooShort = StayFixtures::stay($position)->verdict('2026-06-10', 2);
    $tooLong = StayFixtures::stay($position)->verdict('2026-06-10', 8);

    expect($tooShort->sellable)->toBeFalse()
        ->and($tooShort->reason)->toBe(SaleUnavailabilityReason::StayTooShort)
        ->and($tooLong->sellable)->toBeFalse()
        ->and($tooLong->reason)->toBe(SaleUnavailabilityReason::StayTooLong)
        ->and(StayFixtures::stay($position)->isSellable('2026-06-10', 3))->toBeTrue()
        ->and(StayFixtures::stay($position)->isSellable('2026-06-10', 7))->toBeTrue();
});

/**
 * ⚠️ To jest reguła, której pierwsza wersja zadania NIE wyrażała i dla której powstało
 * pojęcie spoiwa: „przyjazd w piątek → min. 2 doby" nie zabrania przyjazdu w sobotę.
 * Każdy z przypadków niżej odpadłby przy tamtym modelu albo przeszedł mimo zakazu.
 *
 * Weekend `{5, 6}` = doby pt→sob i sob→nd. Notacja: „od X, N dób (dni rozpoczęcia)".
 */
test('a weekend sold whole has to be covered entirely', function () {
    [, $position] = StayFixtures::fisheryWithPosition(['weekend_days' => [5, 6]]);

    // 2026-05-01 to piątek, 02.05 sobota, 03.05 niedziela.
    expect(StayFixtures::stay($position)->isSellable('2026-05-02', 1))->toBeFalse()  // sama sobota
        ->and(StayFixtures::stay($position)->isSellable('2026-05-02', 2))->toBeFalse()  // sob–nd
        ->and(StayFixtures::stay($position)->isSellable('2026-05-01', 1))->toBeFalse()  // sam piątek
        ->and(StayFixtures::stay($position)->isSellable('2026-05-01', 2))->toBeTrue()   // pt–sob
        ->and(StayFixtures::stay($position)->isSellable('2026-04-30', 2))->toBeFalse()  // czw–pt
        ->and(StayFixtures::stay($position)->isSellable('2026-04-30', 3))->toBeTrue();  // czw–sob
});

/**
 * ⚠️ Potwierdzone przez łowisko (pytanie 7a): doba `nd 15:00 → pon 15:00` leży POZA
 * weekendem i sprzedaje się jak zwykły dzień. Gdyby kiedyś odpowiedź się odwróciła,
 * jest to zmiana DANYCH (`{5, 6, 7}`), nie modelu — i wtedy ten test ma zmienić się
 * razem z nimi, a nie zniknąć.
 */
test('the sunday night falls outside the weekend and sells on its own', function () {
    [, $position] = StayFixtures::fisheryWithPosition(['weekend_days' => [5, 6]]);

    expect(StayFixtures::stay($position)->isSellable('2026-05-03', 1))->toBeTrue();
});

test('the weekend refusal carries the full range of the package to cover', function () {
    [, $position] = StayFixtures::fisheryWithPosition(['weekend_days' => [5, 6]]);

    $verdict = StayFixtures::stay($position)->verdict('2026-05-02', 1);

    expect($verdict->reason)->toBe(SaleUnavailabilityReason::WeekendBroken)
        ->and($verdict->bundleFirstDay?->toDateString())->toBe('2026-05-01')
        ->and($verdict->bundleLastDay?->toDateString())->toBe('2026-05-02')
        ->and($verdict->bundleNights())->toBe(2);
});

/**
 * „Spoiwo tylko zaostrza" — pakiet czysto weekendowy nie zwalnia z niczego.
 */
test('a purely weekend package does not exempt anything from the minimum', function () {
    [, $position] = StayFixtures::fisheryWithPosition(['weekend_days' => [5, 6], 'min_nights' => 3]);

    $verdict = StayFixtures::stay($position)->verdict('2026-05-01', 2);

    expect($verdict->sellable)->toBeFalse()
        ->and($verdict->reason)->toBe(SaleUnavailabilityReason::StayTooShort)
        ->and(StayFixtures::stay($position)->isSellable('2026-05-01', 3))->toBeTrue();
});

test('a stay covering part of a term is refused and covering all of it passes', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();
    StayFixtures::wholeTerm($fishery, '2026-04-30', 3); // czw 30.04, pt 01.05, sob 02.05

    expect(StayFixtures::stay($position)->isSellable('2026-04-30', 2))->toBeFalse()
        ->and(StayFixtures::stay($position)->isSellable('2026-05-01', 2))->toBeFalse()
        ->and(StayFixtures::stay($position)->isSellable('2026-04-30', 3))->toBeTrue()
        // Święto wraz z dobami przed i po nim.
        ->and(StayFixtures::stay($position)->isSellable('2026-04-29', 5))->toBeTrue();
});

/**
 * ⚠️ Monotoniczność zwolnienia. Wariant „zwolnienie tylko dla pobytu RÓWNEGO świętu"
 * dałby ciąg „3 przechodzi, 4 odmowa, 5 przechodzi", którego wędkarz nie zrozumie —
 * dlatego zwolnienie należy do PAKIETU i jest zero-jedynkowe.
 */
test('the exemption from the minimum is monotonic', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition(['min_nights' => 5]);
    StayFixtures::wholeTerm($fishery, '2026-04-30', 3);

    foreach ([3, 4, 5, 6] as $nights) {
        expect(StayFixtures::stay($position)->isSellable('2026-04-30', $nights))
            ->toBeTrue("pobyt {$nights}-dobowy obejmujący święto powinien przechodzić");
    }

    // Kontrola negatywna: ta sama długość, ale bez dotknięcia święta.
    expect(StayFixtures::stay($position)->isSellable('2026-06-10', 3))->toBeFalse()
        ->and(StayFixtures::stay($position)->verdict('2026-06-10', 3)->reason)
        ->toBe(SaleUnavailabilityReason::StayTooShort);
});

test('the maximum stay length still applies to a stay covering a term', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition(['min_nights' => 5, 'max_nights' => 4]);
    StayFixtures::wholeTerm($fishery, '2026-04-30', 3);

    // Zwolnienie dotyczy WYŁĄCZNIE dolnej granicy.
    expect(StayFixtures::stay($position)->isSellable('2026-04-30', 3))->toBeTrue()
        ->and(StayFixtures::stay($position)->verdict('2026-04-30', 5)->reason)
        ->toBe(SaleUnavailabilityReason::StayTooLong);
});

/**
 * PRZYCINANIE, nie unieważnianie. Alternatywa „pakiet z niesprzedawalną dobą jest cały
 * niesprzedawalny" została odrzucona: blokada soboty wyłączałaby cicho piątek.
 */
test('a package is trimmed to the nights that are sellable', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition(['weekend_days' => [5, 6]]);

    // ⚠️ Blokada WYŁĄCZA, więc wystarczy PRZECIĘCIE doby z oknem (`dostepnosc.md` §1).
    // Okno na 02.05 objęłoby także dobę pt 01.05 15:00 → 02.05 15:00, bo ta przecina
    // 02.05 od północy. Żeby wyciąć wyłącznie dobę sob→nd, okno musi stać na 03.05.
    StayFixtures::blockSale($fishery, $position, '2026-05-03', '2026-05-03');

    // Tej soboty i tak nie ma, więc piątek sprzedaje się sam.
    expect(StayFixtures::stay($position)->isSellable('2026-05-01', 1))->toBeTrue();
});

test('a trimmed term keeps its exemption from the minimum', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition(['min_nights' => 5]);
    StayFixtures::wholeTerm($fishery, '2026-04-30', 3);
    // Blokada zabiera dwie z trzech dób święta (01.05 i 02.05); zostaje pakiet
    // jednodobowy na 30.04. ⚠️ Okno startuje 02.05, nie 01.05 — inaczej przecięłoby
    // także dobę czw 30.04 15:00 → 01.05 15:00 i nie zostałoby nic do kupienia.
    StayFixtures::blockSale($fishery, $position, '2026-05-02', '2026-05-03');

    expect(StayFixtures::stay($position)->isSellable('2026-04-30', 1))->toBeTrue();
});

/**
 * ZLEWANIE — spoiwo jest przechodnie. Święto śr–pt nachodzące na weekend pt–sob daje
 * jeden pakiet śr–sob (4 doby).
 */
test('overlapping packages merge into one', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition(['weekend_days' => [5, 6]]);
    StayFixtures::wholeTerm($fishery, '2026-04-29', 3); // śr 29.04, czw 30.04, pt 01.05

    $verdict = StayFixtures::stay($position)->verdict('2026-04-29', 3);

    expect($verdict->sellable)->toBeFalse()
        // ⚠️ Wskazany zakres to PEŁNY pakiet, nie sam zakres święta.
        ->and($verdict->bundleFirstDay?->toDateString())->toBe('2026-04-29')
        ->and($verdict->bundleLastDay?->toDateString())->toBe('2026-05-02')
        ->and($verdict->bundleNights())->toBe(4)
        // ⚠️ Zlany pakiet odmawia jako „przerwane święto", nie „przerwany weekend"
        // (rozstrzygnięcie 22): to święto nadaje pakietowi zwolnienie z minimum.
        ->and($verdict->reason)->toBe(SaleUnavailabilityReason::WholeTermBroken)
        ->and(StayFixtures::stay($position)->isSellable('2026-04-29', 4))->toBeTrue();
});

/**
 * ⚠️ Sedno kolejności warunków: spoiwo sprawdza się PRZED długością.
 */
test('a merged package is refused as broken, not as too short', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition(['weekend_days' => [5, 6], 'min_nights' => 5]);
    StayFixtures::wholeTerm($fishery, '2026-04-29', 3);

    // Pobyt obejmuje pakiet ze świętem, więc nie podlega minimum — 4 doby przechodzą
    // mimo `min_nights = 5`.
    expect(StayFixtures::stay($position)->isSellable('2026-04-29', 4))->toBeTrue();

    $verdict = StayFixtures::stay($position)->verdict('2026-04-29', 3);

    expect($verdict->reason)->toBe(SaleUnavailabilityReason::WholeTermBroken)
        ->and($verdict->reason)->not->toBe(SaleUnavailabilityReason::StayTooShort);
});

test('a refusal passed from the day level points at the night that failed', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();

    // ⚠️ Okno na 16.06 wycina dobę rozpoczynającą się 15.06 (15.06 15:00 → 16.06 15:00),
    // bo blokadzie wystarczy PRZECIĘCIE. Doba z 14.06 kończy się 15.06 o 15:00, więc
    // okna nie dotyka — to jest cały sens asymetrii z `dostepnosc.md` §1.
    StayFixtures::blockSale($fishery, $position, '2026-06-16', '2026-06-16');

    // Pobyt ośmiodobowy od 10.06; blokada wypada na szóstej dobie.
    $verdict = StayFixtures::stay($position)->verdict('2026-06-10', 8);

    expect($verdict->sellable)->toBeFalse()
        ->and($verdict->reason)->toBe(SaleUnavailabilityReason::SaleBlocked)
        ->and($verdict->day?->startsOn->toDateString())->toBe('2026-06-15');
});

test('the service calls position availability instead of repeating its conditions', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();

    expect(StayFixtures::stay($position)->isSellable('2026-06-10', 2))->toBeTrue();

    // Zmiana po stronie dostępności zmienia wynik bez dotykania kodu reguł pobytu.
    $position->update(['status' => PositionStatus::Withdrawn]);

    $verdict = StayFixtures::stay($position)->verdict('2026-06-10', 2);

    expect($verdict->reason)->toBe(SaleUnavailabilityReason::PositionWithdrawn);

    $position->update(['status' => PositionStatus::Available]);
    $fishery->salePeriods()->delete();

    expect(StayFixtures::stay($position)->verdict('2026-06-10', 2)->reason)
        ->toBe(SaleUnavailabilityReason::NoSalePeriodDefined);
});

test('a stay has to last at least one night', function () {
    [, $position] = StayFixtures::fisheryWithPosition();

    expect(fn () => StayFixtures::stay($position)->verdict('2026-06-10', 0))
        ->toThrow(\InvalidArgumentException::class);
});

test('a position without a fishery has no stay sellability', function () {
    $position = Position::factory()->create(['fishery_id' => null]);

    expect(fn () => new StaySellability($position))
        ->toThrow(\InvalidArgumentException::class);
});

/**
 * ⚠️ Granica horyzontu jest DOMKNIĘTA, a porównanie idzie na datach w strefie łowiska.
 */
test('the sale horizon boundary is closed', function () {
    Date::setTestNow(CarbonImmutable::parse('2026-06-01 09:00', 'Europe/Warsaw'));

    [, $position] = StayFixtures::fisheryWithPosition(['sale_horizon_days' => 30]);

    // 01.06 + 30 dni = 01.07 — ta doba jeszcze się sprzedaje.
    expect(StayFixtures::stay($position)->isSellable('2026-07-01', 1))->toBeTrue();

    $verdict = StayFixtures::stay($position)->verdict('2026-07-02', 1);

    expect($verdict->sellable)->toBeFalse()
        ->and($verdict->reason)->toBe(SaleUnavailabilityReason::BeyondSaleHorizon)
        ->and($verdict->day?->startsOn->toDateString())->toBe('2026-07-02');

    Date::setTestNow();
});

test('the horizon is checked for every night of the stay, not only the first', function () {
    Date::setTestNow(CarbonImmutable::parse('2026-06-01 09:00', 'Europe/Warsaw'));

    [, $position] = StayFixtures::fisheryWithPosition(['sale_horizon_days' => 30]);

    // Pierwsza doba mieści się w horyzoncie, ostatnia już nie.
    $verdict = StayFixtures::stay($position)->verdict('2026-06-29', 5);

    expect($verdict->sellable)->toBeFalse()
        ->and($verdict->reason)->toBe(SaleUnavailabilityReason::BeyondSaleHorizon)
        ->and($verdict->day?->startsOn->toDateString())->toBe('2026-07-02');

    Date::setTestNow();
});
