<?php

namespace Tests\Feature;

use App\Enums\ParticipantRole;
use App\Enums\PositionStatus;
use App\Enums\SaleUnavailabilityReason;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Tests\Support\StayFixtures;

/**
 * Warstwa oferty — **jedyne wejście** dla kalendarza (019), koszyka i portalu (ADR-015).
 *
 * ⚠️ Testy pilnują trzech rzeczy, których nie widać po samym „dostępne / niedostępne":
 * **kolejności** (sprzedawalność przed ceną), **tego, że warstwa nie liczy nic własnego**
 * (najkrótszy pobyt bierze się z odmów warstwy niżej) oraz **kształtu konstruktora** —
 * bez niego pomiar kosztu w 019 wyszedłby zły z powodu budowania zależności od nowa.
 *
 * Kalendarz odniesienia (2026): 29.04 śr · 30.04 czw · **01.05 pt** · 02.05 sob · 03.05 nd.
 */
test('an offer carries the breakdown when the stay is sellable and priced', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00);

    $verdict = StayFixtures::offer($position)->offer('2026-05-01', 2);

    expect($verdict->available)->toBeTrue()
        ->and($verdict->breakdown?->totalInCents())->toBe(14000)
        ->and($verdict->reason)->toBeNull();
});

/**
 * ⚠️ Kolejność: **najpierw sprzedawalność, potem cena.** Pobyt niesprzedawalny nie jest
 * wyceniany, więc odmowa niesie przyczynę trwalszą.
 */
test('an unsellable stay is refused without being priced', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00);
    $position->update(['status' => PositionStatus::Withdrawn]);

    $verdict = StayFixtures::offer($position)->offer('2026-05-01', 2);

    expect($verdict->available)->toBeFalse()
        ->and($verdict->reason)->toBe(SaleUnavailabilityReason::PositionWithdrawn)
        // Wyceny w ogóle nie było.
        ->and($verdict->breakdown)->toBeNull()
        // Powód ze sprzedawalności wraca wraz ze wskazaniem doby.
        ->and($verdict->sellability?->day?->startsOn->toDateString())->toBe('2026-05-01');
});

test('a sellable stay without a matching rate is refused as having no price', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();
    // ⚠️ Stawka nie zna dni tygodnia — dziura w cenniku ma dziś wyłącznie przyczynę DATOWĄ.
    StayFixtures::rate($fishery, 90.00, ['first_day_on' => '2026-05-01']);

    // 30.04: sprzedawalny wg 017, ale cennik zaczyna się dopiero następnego dnia.
    $verdict = StayFixtures::offer($position)->offer('2026-04-30', 1);

    expect($verdict->available)->toBeFalse()
        ->and($verdict->reason)->toBe(SaleUnavailabilityReason::NoPriceDefined)
        // ⚠️ Odmowa wskazuje, CZEGO dotyczy — sam powód nie mówi, którą regułę dopisać.
        ->and($verdict->unpricedNight?->toDateString())->toBe('2026-04-30')
        ->and($verdict->unpricedRole)->toBe(ParticipantRole::Angler)
        ->and($verdict->unpricedAnglersCount)->toBe(1);
});

/**
 * ⚠️ Przy obu przyczynach naraz wygrywa ta z 017 — trwalsza.
 */
test('with both causes at once the sellability reason wins', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();
    // Ani stawki, ani sprzedawalności.
    $position->update(['status' => PositionStatus::Withdrawn]);

    expect(StayFixtures::offer($position)->offer('2026-05-01', 1)->reason)
        ->toBe(SaleUnavailabilityReason::PositionWithdrawn)
        ->not->toBe(SaleUnavailabilityReason::NoPriceDefined);
});

/**
 * ⚠️ **Nachodzenie stawek NIE jest już odmową.** Remis blokujący sprzedaż został wycofany wraz
 * z priorytetami (ADR-014, sekcja „Aktualizacja") — dwie stawki na tę samą dobę rozstrzygają się
 * na korzyść wędkarza, a oferta jest DOSTĘPNA. Ten test pilnuje odwróconego niezmiennika: gdyby
 * kiedykolwiek wróciła odmowa z tytułu nachodzenia, zaczerwienieje.
 */
test('overlapping rates do not refuse the offer, they resolve in the anglers favour', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00);
    StayFixtures::rate($fishery, 90.00, ['first_day_on' => '2026-04-01', 'last_day_on' => '2026-06-30']);

    $verdict = StayFixtures::offer($position)->offer('2026-05-01', 1);

    expect($verdict->available)->toBeTrue()
        ->and($verdict->reason)->toBeNull()
        ->and($verdict->breakdown?->totalInCents())->toBe(7000);
});

test('the shortest buyable stay is one night when nothing constrains it', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00);

    $shortest = StayFixtures::offer($position)->shortestOffer('2026-05-06');

    expect($shortest->available)->toBeTrue()
        ->and($shortest->nights)->toBe(1)
        ->and($shortest->breakdown?->totalInCents())->toBe(7000);
});

test('the shortest buyable stay respects the minimum stay length', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition(['min_nights' => 3]);
    StayFixtures::rate($fishery, 70.00);

    $shortest = StayFixtures::offer($position)->shortestOffer('2026-05-06');

    expect($shortest->nights)->toBe(3)
        ->and($shortest->breakdown?->totalInCents())->toBe(21000);
});

test('the shortest buyable stay covers a whole weekend package', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition(['weekend_days' => [5, 6]]);
    StayFixtures::rate($fishery, 70.00);

    // Piątek zaczyna pakiet pt–sob, więc najkrótszy pobyt to dwie doby.
    expect(StayFixtures::offer($position)->shortestOffer('2026-05-01')->nights)->toBe(2);
});

/**
 * ⚠️ Doba w ŚRODKU pakietu nie ma odpowiedzi „ile", tylko „gdzie zacząć".
 */
test('a night in the middle of a package says where the stay has to start', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition(['weekend_days' => [5, 6]]);
    StayFixtures::rate($fishery, 70.00);

    $shortest = StayFixtures::offer($position)->shortestOffer('2026-05-02');

    expect($shortest->available)->toBeFalse()
        ->and($shortest->nights)->toBeNull()
        ->and($shortest->startEarlierOn?->toDateString())->toBe('2026-05-01')
        ->and($shortest->reason)->toBe(SaleUnavailabilityReason::WeekendBroken);
});

/**
 * ⚠️ **Zwolnienie świąteczne działa też tutaj** — i to jest cały powód, dla którego ta
 * odpowiedź należy do warstwy oferty, a nie do kalendarza. Pominięcie wyjątku dałoby zawyżoną
 * cenę „od" dokładnie w dniach, które operator sprawdza najuważniej.
 */
test('the holiday exemption also applies to the shortest buyable stay', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition(['min_nights' => 5]);
    StayFixtures::rate($fishery, 70.00);
    StayFixtures::wholeTerm($fishery, '2026-04-30', 3);

    $shortest = StayFixtures::offer($position)->shortestOffer('2026-04-30');

    // Trzy doby, nie pięć — pobyt obejmujący pakiet ze świętem nie podlega minimum.
    expect($shortest->nights)->toBe(3)
        ->and($shortest->breakdown?->totalInCents())->toBe(21000);

    // Kontrola negatywna: doba nietknięta świętem nadal wymaga pięciu dób.
    expect(StayFixtures::offer($position)->shortestOffer('2026-06-10')->nights)->toBe(5);
});

test('the shortest buyable stay honours the presale minimum', function () {
    Date::setTestNow(CarbonImmutable::parse('2026-01-10 09:00', 'Europe/Warsaw'));

    [$fishery, $position] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00);

    $fishery->salePeriods()->update([
        'presale_opens_on' => '2026-01-01',
        'presale_closes_on' => '2026-01-31',
        'presale_min_nights' => 4,
    ]);

    expect(StayFixtures::offer($position)->shortestOffer('2026-05-06')->nights)->toBe(4);

    Date::setTestNow();
});

test('a night is unsellable when the shortest possible stay exceeds the maximum', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition(['min_nights' => 5, 'max_nights' => 3]);
    StayFixtures::rate($fishery, 70.00);

    $shortest = StayFixtures::offer($position)->shortestOffer('2026-05-06');

    expect($shortest->available)->toBeFalse()
        ->and($shortest->reason)->toBe(SaleUnavailabilityReason::StayTooLong);
});

test('a night with no price at all has no shortest buyable stay', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 90.00, ['first_day_on' => '2026-06-01']);

    $shortest = StayFixtures::offer($position)->shortestOffer('2026-05-06');

    expect($shortest->available)->toBeFalse()
        ->and($shortest->reason)->toBe(SaleUnavailabilityReason::NoPriceDefined);
});

/**
 * ⚠️ **Kształt konstruktora, nie mikrooptymalizacja.** Warstwa bierze `Position`, więc blokady,
 * okresy i cennik wczytują się RAZ na instancję. Gdyby budowała zależności przy każdym
 * wywołaniu, pomiar kosztu w 019 wyszedłby zły z powodu kształtu konstruktora, nie realnej
 * ceny algorytmu — i skłoniłby do bufora, którego może w ogóle nie trzeba.
 */
test('one offer instance does not reload its dependencies for every question', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00);

    $offer = StayFixtures::offer($position);

    // Pierwsze pytanie wczytuje wszystko.
    $offer->offer('2026-05-06', 1);

    DB::enableQueryLog();

    foreach (['2026-05-07', '2026-05-08', '2026-05-09', '2026-05-10'] as $date) {
        $offer->offer($date, 1);
    }

    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Cztery kolejne pytania o to samo stanowisko nie mają czego dociągać.
    expect($queries)->toBe(0);
});
