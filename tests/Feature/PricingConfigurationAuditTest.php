<?php

namespace Tests\Feature;

use App\Models\Fishery;
use App\Models\Position;
use App\Services\PricingConfigurationAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Tests\Support\StayFixtures;

/**
 * Ostrzeżenia i diagnostyka cennika — testy dopisane po mutacjach zadania 023.
 *
 * ⚠️ Czas zamrożony: „dziś" wyznacza początek sprawdzania dziur, a strefa łowiska decyduje,
 * który to dzień.
 */
beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-05-04 09:00', 'Europe/Warsaw'));
});

afterEach(function () {
    Date::setTestNow();
});

test('bez którejkolwiek z godzin doby sprawdzenie dziury milczy', function () {
    [$noStart] = StayFixtures::fisheryWithPosition(['day_start_time' => null]);
    [$noEnd] = StayFixtures::fisheryWithPosition(['day_end_time' => null]);

    expect((new PricingConfigurationAudit($noStart->fresh()))->firstPricingGap())->toBeNull()
        ->and((new PricingConfigurationAudit($noEnd->fresh()))->firstPricingGap())->toBeNull();
});

test('dziura zaczyna się dziś, a nie w przeszłej części okresu', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00, ['first_day_on' => '2026-06-01']);

    expect((new PricingConfigurationAudit($fishery->fresh()))->firstPricingGap()?->toDateString())
        ->toBe('2026-05-04');
});

/**
 * ⚠️ „Dziś" liczy się w strefie ŁOWISKA. O 01:00 w Warszawie w Nowym Jorku jest jeszcze
 * poprzedni dzień — i to on jest pierwszą niesprzedawalną dobą.
 */
test('dziś jest liczone w strefie łowiska', function () {
    Date::setTestNow(CarbonImmutable::parse('2026-05-04 01:00', 'Europe/Warsaw'));
    [$fishery] = StayFixtures::fisheryWithPosition(['timezone' => 'America/New_York']);
    StayFixtures::rate($fishery, 70.00, ['first_day_on' => '2026-06-01']);

    expect((new PricingConfigurationAudit($fishery->fresh()))->firstPricingGap()?->toDateString())
        ->toBe('2026-05-03');
});

test('martwa jest tylko przegrywająca aktywna stawka — nie zawieszona i nie dopłata', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    $base = StayFixtures::rate($fishery, 70.00);
    $dead = StayFixtures::rate($fishery, 90.00, ['first_day_on' => '2026-07-01', 'last_day_on' => '2026-08-31']);
    // Zawieszona tańsza stawka NIE bierze udziału — inaczej to ona wygrywałaby wszędzie
    // i „martwa" byłaby także stawka bazowa.
    StayFixtures::rate($fishery, 10.00, ['is_suspended' => true]);
    // Dopłata nie jest stawką i nie może trafić na listę martwych stawek.
    StayFixtures::surcharge($fishery, 5.00, 'Prad');

    $deadRates = (new PricingConfigurationAudit($fishery->fresh()))->deadRates();

    // Klucz 0: lista jest przenumerowana, a nie wycinkiem z dziurami po odfiltrowanych.
    expect(array_keys($deadRates))->toBe([0])
        ->and($deadRates[0]->id)->toBe($dead->id)
        ->and($base->id)->not->toBe($dead->id);
});

test('stawki bez dat: przegrywa droższa', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00);
    $expensive = StayFixtures::rate($fishery, 90.00);

    $deadRates = (new PricingConfigurationAudit($fishery->fresh()))->deadRates();

    expect(array_map(static fn ($rule) => $rule->id, $deadRates))->toBe([$expensive->id]);
});

/**
 * ⚠️ Granice sprawdza się w kolejności DAT, nie w kolejności zapisu reguł. Stawka otwarta
 * od dołu wygrywa wyłącznie w przedziale przed najwcześniejszą granicą — przy złej kolejności
 * ten przedział znika i żywa stawka zostaje uznana za martwą.
 */
test('stawka otwarta od dołu żyje przed najwcześniejszą granicą, niezależnie od kolejności zapisu', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 90.00, ['first_day_on' => '2026-06-01']);
    StayFixtures::rate($fishery, 60.00, ['last_day_on' => '2026-03-31']);

    expect((new PricingConfigurationAudit($fishery->fresh()))->deadRates())->toBe([]);
});

test('jedna stawka nigdy nie jest martwa', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00, ['first_day_on' => '2026-06-01', 'last_day_on' => '2026-06-30']);

    expect((new PricingConfigurationAudit($fishery->fresh()))->deadRates())->toBe([]);
});

test('największa obsada bierze maksimum z pojemności, a brak pojemności liczy jako 1', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();
    $position->update(['max_anglers' => 3]);
    Position::factory()->create(['fishery_id' => $fishery->id, 'max_anglers' => null]);

    [$onlyUnknown, $unknown] = StayFixtures::fisheryWithPosition();
    $unknown->update(['max_anglers' => null]);

    $empty = Fishery::factory()->create();

    expect((new PricingConfigurationAudit($fishery->fresh()))->largestAnglerCapacity())->toBe(3)
        ->and((new PricingConfigurationAudit($onlyUnknown->fresh()))->largestAnglerCapacity())->toBe(1)
        ->and((new PricingConfigurationAudit($empty->fresh()))->largestAnglerCapacity())->toBe(1);
});
