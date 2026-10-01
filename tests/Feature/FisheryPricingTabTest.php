<?php

namespace Tests\Feature;

use App\Enums\PositionStatus;
use App\Enums\ServiceBillingUnit;
use App\Enums\ServiceScope;
use App\Models\AdditionalService;
use App\Models\Company;
use App\Models\Fishery;
use App\Models\Position;
use App\Models\PositionAttribute;
use App\Models\PriceRule;
use App\Models\SalePeriod;
use App\Models\State;
use App\Services\AmountFormatter;
use App\Services\PortalPriceList;
use App\Services\PricingConfigurationAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Tests\Support\StayFixtures;

/**
 * Zakładka „Cennik" strony łowiska (zadanie 034, portal-v3 „Łowisko — Cennik").
 *
 * ⚠️ Czas zamrożony: pon 04.05.2026. Portal niczego tu nie liczy — testy sprawdzają, że pokazuje
 * to, co mówi konfiguracja, i NIE pokazuje stawek zakończonych, zawieszonych i martwych.
 */
beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-05-04 09:00', 'Europe/Warsaw'));
});

afterEach(function () {
    Date::setTestNow();
});

/** Łowisko opublikowane pod adresem `/pl/wielkopolskie/klasztorne`, z jednym stanowiskiem „1" w sprzedaży. */
function pricedFishery(): Fishery
{
    [$fishery, $position] = StayFixtures::fisheryWithPosition([
        'name' => 'Klasztorne',
        'state_id' => State::factory()->create(['name' => 'Greater Poland'])->id,
        'company_id' => Company::factory()->create()->id,
        'published_at' => now(),
    ]);
    $position->update(['name' => '1']);

    return $fishery->fresh();
}

function perNight(float $amount, Fishery $fishery): string
{
    return AmountFormatter::forVisitor((int) round($amount * 100), $fishery->currency?->name).' / doba';
}

function pricingPage(Fishery $fishery, string $locale = 'pl'): string
{
    return test()->get("/{$locale}/wielkopolskie/klasztorne")->assertOk()->getContent();
}

test('rates show with their periods and the future period is marked', function () {
    $fishery = pricedFishery();
    StayFixtures::rate($fishery, 137.00, ['first_day_on' => null, 'last_day_on' => '2026-10-31']);
    StayFixtures::rate($fishery, 101.00, ['first_day_on' => '2026-11-01', 'last_day_on' => '2027-03-31']);

    $html = pricingPage($fishery);

    expect($html)
        ->toContain(e(perNight(137, $fishery)))
        ->toContain(e(perNight(101, $fishery)))
        ->toContain('do 31.10.2026')
        ->toContain('od 01.11.2026 do 31.03.2027')
        ->toContain('kolejny okres');
});

test('an open-ended rate without dates reads as all year', function () {
    $fishery = pricedFishery();
    StayFixtures::rate($fishery, 137.00);

    expect(pricingPage($fishery))->toContain('cały rok');
});

test('ended, suspended and dead rates are not shown', function () {
    $fishery = pricedFishery();
    StayFixtures::rate($fishery, 137.00);
    // zakończona przed dziś
    StayFixtures::rate($fishery, 111.00, ['first_day_on' => '2026-01-01', 'last_day_on' => '2026-03-31']);
    // zawieszona
    StayFixtures::rate($fishery, 113.00, ['is_suspended' => true]);
    // martwa — droższa wstawka w bezterminowym cenniku nigdy nie wygra (wygrywa tańsza)
    StayFixtures::rate($fishery, 193.00, ['first_day_on' => '2026-07-01', 'last_day_on' => '2026-08-31']);

    $html = pricingPage($fishery);

    expect($html)
        ->toContain(e(perNight(137, $fishery)))
        ->not->toContain(e(perNight(111, $fishery)))
        ->not->toContain(e(perNight(113, $fishery)))
        ->not->toContain(e(perNight(193, $fishery)));
});

test('a single rate that already ended is dead from today', function () {
    $fishery = pricedFishery();
    $ended = StayFixtures::rate($fishery, 111.00, ['first_day_on' => '2026-01-01', 'last_day_on' => '2026-03-31']);

    $audit = new PricingConfigurationAudit($fishery);

    expect($audit->deadRates())->toBe([])
        ->and(array_map(fn (PriceRule $rule): int => $rule->id, $audit->deadRates(CarbonImmutable::parse('2026-05-04', 'Europe/Warsaw'))))
        ->toBe([$ended->id]);
});

test('the calendar analysis of dead rates is unchanged without a start date', function () {
    $fishery = pricedFishery();
    StayFixtures::rate($fishery, 70.00);
    $dead = StayFixtures::rate($fishery, 90.00, ['first_day_on' => '2026-07-01', 'last_day_on' => '2026-08-31']);
    // zakończona wstawka tańsza niż bezterminowa wygrywała w przeszłości, więc bez daty NIE jest martwa
    $past = StayFixtures::rate($fishery, 50.00, ['first_day_on' => '2026-01-01', 'last_day_on' => '2026-03-31']);

    $ids = array_map(fn (PriceRule $rule): int => $rule->id, (new PricingConfigurationAudit($fishery))->deadRates());

    expect($ids)->toBe([$dead->id])->not->toContain($past->id);
});

test('a surcharge shows under its name with the condition in the visitor words', function () {
    $fishery = pricedFishery();
    StayFixtures::rate($fishery, 137.00);
    StayFixtures::surcharge($fishery, 41.00, 'Stanowisko tylko dla Ciebie', [
        'anglers_count' => 1,
        'weekdays' => [4, 5, 6, 7],
    ]);

    $html = pricingPage($fishery);

    expect($html)
        ->toContain('Stanowisko tylko dla Ciebie')
        ->toContain('+ '.e(perNight(41, $fishery)))
        ->toContain('przy 1 łowiącym')
        ->toContain('doby czw→pon');
});

test('suspended and ended surcharges are not shown', function () {
    $fishery = pricedFishery();
    StayFixtures::rate($fishery, 137.00);
    StayFixtures::surcharge($fishery, 43.00, 'Zawieszona dopłata', ['is_suspended' => true]);
    StayFixtures::surcharge($fishery, 47.00, 'Zakończona dopłata', ['first_day_on' => '2026-01-01', 'last_day_on' => '2026-03-31']);
    StayFixtures::surcharge($fishery, 53.00, 'Przyszła dopłata', ['first_day_on' => '2026-07-01', 'last_day_on' => '2026-08-31']);

    $html = pricingPage($fishery);

    expect($html)
        ->not->toContain('Zawieszona dopłata')
        ->not->toContain('Zakończona dopłata')
        ->toContain('Przyszła dopłata')
        ->toContain('od 01.07.2026 do 31.08.2026');
});

test('the companion price shows with each rate, free as free and missing as nothing', function () {
    $fishery = pricedFishery();
    StayFixtures::rate($fishery, 137.00, ['first_day_on' => null, 'last_day_on' => '2026-10-31', 'amount_companion' => 0.00]);
    StayFixtures::rate($fishery, 101.00, ['first_day_on' => '2026-11-01', 'last_day_on' => null, 'amount_companion' => null]);

    $html = pricingPage($fishery);

    expect($html)->toContain('osoba towarzysząca: bezpłatna');
    expect(substr_count($html, 'osoba towarzysząca:'))->toBe(1);
});

test('services show with unit, scope, required pins and required features', function () {
    $fishery = pricedFishery();
    $second = Position::factory()->create(['fishery_id' => $fishery->id, 'name' => '2', 'status' => PositionStatus::Available]);
    $first = $fishery->positions()->where('name', '1')->firstOrFail();

    AdditionalService::factory()->create([
        'fishery_id' => $fishery->id, 'name' => 'Prysznic', 'price' => 11.00, 'is_active' => true,
        'billing_unit' => ServiceBillingUnit::PerNight, 'scope' => ServiceScope::WholeFishery,
    ]);

    $trailer = AdditionalService::factory()->create([
        'fishery_id' => $fishery->id, 'name' => 'Postawienie przyczepy', 'price' => 0, 'is_active' => true,
        'billing_unit' => ServiceBillingUnit::PerNight, 'scope' => ServiceScope::SelectedPositions,
    ]);
    $trailer->positions()->attach($second->id, ['is_required' => false]);
    $vehicle = PositionAttribute::factory()->create(['name' => 'Wjazd z przyczepą']);
    $trailer->requiredAttributes()->attach($vehicle->id);

    $cleaning = AdditionalService::factory()->create([
        'fishery_id' => $fishery->id, 'name' => 'Sprzątanie stanowiska', 'price' => 31.00, 'is_active' => true,
        'billing_unit' => ServiceBillingUnit::PerStay, 'scope' => ServiceScope::SelectedPositions,
    ]);
    $cleaning->positions()->attach([$first->id => ['is_required' => true], $second->id => ['is_required' => true]]);

    $html = pricingPage($fishery);

    expect($html)
        ->toContain('Prysznic')
        ->toContain('Całe łowisko')
        ->toContain('Postawienie przyczepy')
        ->toContain('bezpłatna')
        ->toContain('stanowiska: 2')
        ->toContain('wymaga: Wjazd z przyczepą')
        ->toContain('Sprzątanie stanowiska')
        ->toContain('/ pobyt')
        ->toContain('wszystkie stanowiska')
        ->toContain('obowiązkowa');
});

test('required services come first and a required pin on only some positions says where', function () {
    $fishery = pricedFishery();
    $second = Position::factory()->create(['fishery_id' => $fishery->id, 'name' => '2', 'status' => PositionStatus::Available]);
    $first = $fishery->positions()->where('name', '1')->firstOrFail();

    AdditionalService::factory()->create([
        'fishery_id' => $fishery->id, 'name' => 'Aaa opcjonalna', 'price' => 5.00, 'is_active' => true,
        'scope' => ServiceScope::WholeFishery,
    ]);
    $partial = AdditionalService::factory()->create([
        'fishery_id' => $fishery->id, 'name' => 'Zzz częściowo obowiązkowa', 'price' => 5.00, 'is_active' => true,
        'scope' => ServiceScope::SelectedPositions,
    ]);
    $partial->positions()->attach([$first->id => ['is_required' => true], $second->id => ['is_required' => false]]);

    app()->setLocale('pl');
    $services = (new PortalPriceList($fishery->fresh()))->services();

    expect(array_column($services, 'name'))->toBe(['Zzz częściowo obowiązkowa', 'Aaa opcjonalna'])
        ->and($services[0]['required'])->toBe('obowiązkowa na stanowiskach: 1');
});

test('inactive services and services with no position in sale are not shown', function () {
    $fishery = pricedFishery();
    $sold = Position::factory()->create(['fishery_id' => $fishery->id, 'name' => '9', 'status' => PositionStatus::Withdrawn]);

    AdditionalService::factory()->create([
        'fishery_id' => $fishery->id, 'name' => 'Nieaktywna usługa', 'price' => 9.00, 'is_active' => false,
        'scope' => ServiceScope::WholeFishery,
    ]);
    $orphan = AdditionalService::factory()->create([
        'fishery_id' => $fishery->id, 'name' => 'Usługa bez stanowiska', 'price' => 9.00, 'is_active' => true,
        'scope' => ServiceScope::SelectedPositions,
    ]);
    $orphan->positions()->attach($sold->id, ['is_required' => false]);

    expect(pricingPage($fishery))
        ->not->toContain('Nieaktywna usługa')
        ->not->toContain('Usługa bez stanowiska');
});

test('a presale that is open appears in the price list', function () {
    $fishery = pricedFishery();
    SalePeriod::factory()->create([
        'fishery_id' => $fishery->id,
        'starts_on' => '2027-01-01',
        'ends_on' => '2027-12-31',
        'presale_opens_on' => '2026-04-01',
        'presale_closes_on' => '2026-06-30',
        'presale_min_nights' => 5,
        'presale_discount_percent' => 10,
    ]);

    expect(pricingPage($fishery))->toContain('−10%')->toContain('min. 5 dób');
});

test('a fishery with no price list says so instead of showing an empty page', function () {
    $fishery = pricedFishery();

    expect(pricingPage($fishery))->toContain('Cennik w przygotowaniu');
});

test('the tab has a language dependent anchor and a button to the calendar', function () {
    $fishery = pricedFishery();
    StayFixtures::rate($fishery, 137.00);

    $pl = pricingPage($fishery, 'pl');
    $en = pricingPage($fishery, 'en');

    expect($pl)->toContain('id="cennik"')->toContain('href="#cennik"')->toContain('data-tab-open="mapa-i-terminy"')
        ->and($en)->toContain('id="pricing"')->toContain('href="#pricing"')->toContain('data-tab-open="map-and-dates"');
});

test('the price list is shown in the other language with the same data', function () {
    $fishery = pricedFishery();
    StayFixtures::rate($fishery, 137.00);
    StayFixtures::surcharge($fishery, 41.00, 'Solo', ['anglers_count' => 1]);

    expect(pricingPage($fishery, 'en'))->toContain('Rate per angler')->toContain('with 1 angler');
});

test('a fractional presale discount uses the decimal separator of the page language', function () {
    $fishery = pricedFishery();
    SalePeriod::factory()->create([
        'fishery_id' => $fishery->id,
        'starts_on' => '2027-01-01',
        'ends_on' => '2027-12-31',
        'presale_opens_on' => '2026-04-01',
        'presale_closes_on' => '2026-06-30',
        'presale_discount_percent' => 12.5,
    ]);

    expect(pricingPage($fishery, 'pl'))->toContain('−12,5%')
        ->and(pricingPage($fishery, 'en'))->toContain('−12.5%');
});
