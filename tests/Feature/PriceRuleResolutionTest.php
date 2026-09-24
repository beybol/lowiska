<?php

use App\Enums\PriceRuleKind;
use App\Enums\PricingFailure;
use App\Models\Fishery;
use App\Models\PriceRule;
use App\Services\FishingDay;
use App\Services\FishingDayCalendar;
use App\Services\PriceRulePeriods;
use App\Services\PriceRuleResolver;
use App\Services\PricingConfigurationAudit;
use Carbon\CarbonImmutable;
use Tests\Support\StayFixtures;

/**
 * Rozstrzyganie cennika po przedefiniowaniu zadania 018 (ADR-014, sekcja „Aktualizacja").
 *
 * ⚠️ **Czego tu celowo NIE MA:** priorytetów, szczegółowości osiami i remisu blokującego zapis.
 * Zostały wycofane, bo nie obsługiwały żadnego realnego przypadku. Gdyby wróciły do kodu, ten
 * plik przestałby się kompilować — i o to chodzi.
 */
function nightOn(Fishery $fishery, string $day): FishingDay
{
    $night = (new FishingDayCalendar($fishery->fresh()))->dayStartingOn(
        CarbonImmutable::parse($day, $fishery->timezone)->startOfDay(),
    );

    expect($night)->not->toBeNull();

    return $night;
}

/**
 * @param  array<int, PriceRule>  $rules
 */
function resolverFor(array $rules): PriceRuleResolver
{
    return new PriceRuleResolver($rules);
}

it('wybiera stawkę najkorzystniejszą dla wędkarza, a nie pierwszą z brzegu', function (): void {
    [$fishery] = StayFixtures::fisheryWithPosition();

    $expensive = StayFixtures::rate($fishery, 90.00);
    $cheap = StayFixtures::rate($fishery, 70.00);

    $resolution = resolverFor([$expensive, $cheap])->resolve(nightOn($fishery, '2026-05-10'), 1);

    expect($resolution->isResolved())->toBeTrue()
        ->and($resolution->rate->id)->toBe($cheap->id);
});

it('niesie KOMPLET kandydatów, nie samego zwycięzcę — to z tego 019 rysuje nachodzenie', function (): void {
    [$fishery] = StayFixtures::fisheryWithPosition();

    $cheap = StayFixtures::rate($fishery, 70.00);
    $expensive = StayFixtures::rate($fishery, 90.00);

    $resolution = resolverFor([$expensive, $cheap])->resolve(nightOn($fishery, '2026-05-10'), 1);

    expect($resolution->candidates)->toHaveCount(2)
        ->and($resolution->candidates[0]->id)->toBe($cheap->id)
        ->and($resolution->candidates[1]->id)->toBe($expensive->id);
});

it('przy jednej pasującej stawce lista kandydatów ma jedną pozycję i nie ma nachodzenia', function (): void {
    [$fishery] = StayFixtures::fisheryWithPosition();

    $only = StayFixtures::rate($fishery, 70.00);

    $resolution = resolverFor([$only])->resolve(nightOn($fishery, '2026-05-10'), 1);

    expect($resolution->candidates)->toHaveCount(1);
});

it('identyczne kwoty też są w komplecie — dwie stawki to nadal nachodzenie', function (): void {
    [$fishery] = StayFixtures::fisheryWithPosition();

    $first = StayFixtures::rate($fishery, 70.00);
    $second = StayFixtures::rate($fishery, 70.00);

    $resolution = resolverFor([$first, $second])->resolve(nightOn($fishery, '2026-05-10'), 1);

    expect($resolution->candidates)->toHaveCount(2);
});

it('przy równych kwotach rozstrzyga deterministycznie i zawsze tak samo', function (): void {
    [$fishery] = StayFixtures::fisheryWithPosition();

    $first = StayFixtures::rate($fishery, 70.00);
    $second = StayFixtures::rate($fishery, 70.00);

    $night = nightOn($fishery, '2026-05-10');

    $a = resolverFor([$first, $second])->resolve($night, 1)->rate->id;
    $b = resolverFor([$second, $first])->resolve($night, 1)->rate->id;

    expect($a)->toBe($b)->and($a)->toBe(min($first->id, $second->id));
});

it('stawka bez ceny za osobę towarzyszącą przegrywa remis kwotowy', function (): void {
    [$fishery] = StayFixtures::fisheryWithPosition();

    // Zapisana PIERWSZA, więc przy rozstrzyganiu po `id` wygrałaby — ma przegrać po `null`.
    $withoutCompanion = PriceRule::factory()->amount(70.00)->companionAmount(null)
        ->create(['fishery_id' => $fishery->id]);
    $withCompanion = StayFixtures::rate($fishery, 70.00);

    $resolution = resolverFor([$withoutCompanion, $withCompanion])
        ->resolve(nightOn($fishery, '2026-05-10'), 1);

    expect($resolution->rate->id)->toBe($withCompanion->id);
});

it('pomija stawki zawieszone i miękko usunięte, mimo że są tańsze', function (): void {
    [$fishery] = StayFixtures::fisheryWithPosition();

    $normal = StayFixtures::rate($fishery, 70.00);
    $suspended = PriceRule::factory()->amount(10.00)->suspended()->create(['fishery_id' => $fishery->id]);
    $trashed = PriceRule::factory()->amount(5.00)->create(['fishery_id' => $fishery->id]);
    $trashed->delete();

    /** @var array<int, PriceRule> $rules */
    $rules = $fishery->priceRules()->get()->all();

    $resolution = resolverFor($rules)->resolve(nightOn($fishery, '2026-05-10'), 1);

    expect($resolution->rate->id)->toBe($normal->id)
        ->and($resolution->candidates)->toHaveCount(1)
        ->and($suspended->fresh())->not->toBeNull();
});

it('stawka nie zna dni tygodnia — ta sama doba w poniedziałek i sobotę daje tę samą kwotę', function (): void {
    [$fishery] = StayFixtures::fisheryWithPosition();

    $rate = StayFixtures::rate($fishery, 70.00);
    $resolver = resolverFor([$rate]);

    // 2026-05-04 to poniedziałek, 2026-05-09 to sobota.
    expect($resolver->resolve(nightOn($fishery, '2026-05-04'), 1)->anglerAmountInCents())->toBe(7000)
        ->and($resolver->resolve(nightOn($fishery, '2026-05-09'), 1)->anglerAmountInCents())->toBe(7000);
});

it('odrzuca stawkę spoza jej zakresu dat, z granicami domkniętymi', function (): void {
    [$fishery] = StayFixtures::fisheryWithPosition();

    $rate = StayFixtures::rate($fishery, 70.00, ['first_day_on' => '2026-05-01', 'last_day_on' => '2026-05-31']);
    $resolver = resolverFor([$rate]);

    expect($resolver->resolve(nightOn($fishery, '2026-04-30'), 1)->isResolved())->toBeFalse()
        ->and($resolver->resolve(nightOn($fishery, '2026-05-01'), 1)->isResolved())->toBeTrue()
        ->and($resolver->resolve(nightOn($fishery, '2026-05-31'), 1)->isResolved())->toBeTrue()
        ->and($resolver->resolve(nightOn($fishery, '2026-06-01'), 1)->isResolved())->toBeFalse();
});

it('dopłaty sumują się, a warunek obsady rozstrzyga o wejściu każdej z nich', function (): void {
    [$fishery] = StayFixtures::fisheryWithPosition();

    $rate = StayFixtures::rate($fishery, 70.00);
    $always = StayFixtures::surcharge($fishery, 20.00, 'Zawsze');
    $solo = StayFixtures::surcharge($fishery, 20.00, 'Tylko solo', ['anglers_count' => 1]);

    $resolver = resolverFor([$rate, $always, $solo]);
    $night = nightOn($fishery, '2026-05-10');

    expect($resolver->resolve($night, 1)->surcharges)->toHaveCount(2)
        ->and($resolver->resolve($night, 2)->surcharges)->toHaveCount(1);
});

it('warunek dni tygodnia dopłaty liczy się osobno dla każdej doby', function (): void {
    [$fishery] = StayFixtures::fisheryWithPosition();

    $rate = StayFixtures::rate($fishery, 70.00);
    // Czwartek–niedziela: ISO 4,5,6,7.
    $surcharge = StayFixtures::surcharge($fishery, 20.00, 'Weekend', ['weekdays' => [4, 5, 6, 7]]);

    $resolver = resolverFor([$rate, $surcharge]);

    // 2026-05-06 środa, 2026-05-07 czwartek.
    expect($resolver->resolve(nightOn($fishery, '2026-05-06'), 1)->surcharges)->toHaveCount(0)
        ->and($resolver->resolve(nightOn($fishery, '2026-05-07'), 1)->surcharges)->toHaveCount(1);
});

it('komplet kandydatów nie zależy od obsady ani od stanowiska', function (): void {
    [$fishery] = StayFixtures::fisheryWithPosition();

    $cheap = StayFixtures::rate($fishery, 70.00);
    $expensive = StayFixtures::rate($fishery, 90.00);

    $resolver = resolverFor([$cheap, $expensive]);
    $night = nightOn($fishery, '2026-05-10');

    $forOne = array_map(static fn (PriceRule $r): int => (int) $r->id, $resolver->resolve($night, 1)->candidates);
    $forFour = array_map(static fn (PriceRule $r): int => (int) $r->id, $resolver->resolve($night, 4)->candidates);

    expect($forOne)->toBe($forFour);
});

it('zgłasza brak stawki jako wynik, nie wyjątek', function (): void {
    [$fishery] = StayFixtures::fisheryWithPosition();

    $resolution = resolverFor([])->resolve(nightOn($fishery, '2026-05-10'), 1);

    expect($resolution->isResolved())->toBeFalse()
        ->and($resolution->failure)->toBe(PricingFailure::NoMatchingRate)
        ->and($resolution->candidates)->toBe([]);
});

it('domyka poprzednią stawkę bezterminową, gdy powstaje nowy cennik', function (): void {
    [$fishery] = StayFixtures::fisheryWithPosition();

    $old = StayFixtures::rate($fishery, 70.00, ['first_day_on' => '2026-01-01']);
    $new = StayFixtures::rate($fishery, 80.00, ['first_day_on' => '2027-01-01']);

    $closed = (new PriceRulePeriods($fishery))->closeSupersededRates([$new->id]);

    expect($closed)->toHaveCount(1)
        ->and($old->fresh()->last_day_on->toDateString())->toBe('2026-12-31')
        ->and($new->fresh()->last_day_on)->toBeNull();

    /** @var array<int, PriceRule> $rules */
    $rules = $fishery->priceRules()->get()->all();
    $resolver = resolverFor($rules);

    expect($resolver->resolve(nightOn($fishery, '2027-03-01'), 1)->anglerAmountInCents())->toBe(8000);
});

it('bez domknięcia podwyżka NIE zadziałałaby — to dowód, że mechanizm jest potrzebny', function (): void {
    [$fishery] = StayFixtures::fisheryWithPosition();

    StayFixtures::rate($fishery, 70.00, ['first_day_on' => '2026-01-01']);
    StayFixtures::rate($fishery, 80.00, ['first_day_on' => '2027-01-01']);

    // Świadomie NIE wołamy domykania.
    /** @var array<int, PriceRule> $rules */
    $rules = $fishery->priceRules()->get()->all();

    expect(resolverFor($rules)->resolve(nightOn($fishery, '2027-03-01'), 1)->anglerAmountInCents())
        ->toBe(7000);
});

it('stawka-okno NIE domyka stawki bezterminowej — najważniejszy przypadek tej redakcji', function (): void {
    [$fishery] = StayFixtures::fisheryWithPosition();

    $openEnded = StayFixtures::rate($fishery, 70.00, ['first_day_on' => '2026-01-01']);
    $window = StayFixtures::rate($fishery, 90.00, ['first_day_on' => '2026-07-01', 'last_day_on' => '2026-08-31']);

    $closed = (new PriceRulePeriods($fishery))->closeSupersededRates([$window->id]);

    expect($closed)->toBe([])
        ->and($openEnded->fresh()->last_day_on)->toBeNull();

    /** @var array<int, PriceRule> $rules */
    $rules = $fishery->priceRules()->get()->all();
    $resolver = resolverFor($rules);

    // Lipiec: wygrywa tańsza. Wrzesień: stawka bezterminowa nadal żyje, nie ma dziury.
    expect($resolver->resolve(nightOn($fishery, '2026-07-15'), 1)->anglerAmountInCents())->toBe(7000)
        ->and($resolver->resolve(nightOn($fishery, '2026-09-15'), 1)->isResolved())->toBeTrue()
        ->and($resolver->resolve(nightOn($fishery, '2026-09-15'), 1)->anglerAmountInCents())->toBe(7000);
});

it('nie domyka stawek o rozłącznych okresach', function (): void {
    [$fishery] = StayFixtures::fisheryWithPosition();

    $spring = StayFixtures::rate($fishery, 130.00, ['first_day_on' => '2026-03-01', 'last_day_on' => '2026-06-30']);
    $autumn = StayFixtures::rate($fishery, 130.00, ['first_day_on' => '2026-09-01', 'last_day_on' => '2026-12-31']);

    (new PriceRulePeriods($fishery))->closeSupersededRates([$autumn->id]);

    expect($spring->fresh()->last_day_on->toDateString())->toBe('2026-06-30')
        ->and($autumn->fresh()->first_day_on->toDateString())->toBe('2026-09-01');
});

it('domykanie nie dotyka dopłat', function (): void {
    [$fishery] = StayFixtures::fisheryWithPosition();

    $first = StayFixtures::surcharge($fishery, 20.00, 'Pierwsza', ['first_day_on' => '2026-01-01']);
    $second = StayFixtures::surcharge($fishery, 30.00, 'Druga', ['first_day_on' => '2027-01-01']);

    $closed = (new PriceRulePeriods($fishery))->closeSupersededRates([$second->id]);

    expect($closed)->toBe([])
        ->and($first->fresh()->last_day_on)->toBeNull();
});

it('kilka nowych stawek w jednym zapisie domyka się łańcuchem, niezależnie od kolejności', function (): void {
    [$fishery] = StayFixtures::fisheryWithPosition();

    $base = StayFixtures::rate($fishery, 70.00, ['first_day_on' => '2026-01-01']);
    $y2028 = StayFixtures::rate($fishery, 90.00, ['first_day_on' => '2028-01-01']);
    $y2027 = StayFixtures::rate($fishery, 80.00, ['first_day_on' => '2027-01-01']);

    // Kolejność celowo odwrotna do chronologicznej.
    (new PriceRulePeriods($fishery))->closeSupersededRates([$y2028->id, $y2027->id]);

    expect($base->fresh()->last_day_on->toDateString())->toBe('2026-12-31')
        ->and($y2027->fresh()->last_day_on->toDateString())->toBe('2027-12-31')
        ->and($y2028->fresh()->last_day_on)->toBeNull();
});

it('wskazuje stawkę martwą, a nie zgłasza tej, która gdzieś wygrywa', function (): void {
    [$fishery] = StayFixtures::fisheryWithPosition();

    StayFixtures::rate($fishery, 70.00, ['first_day_on' => '2026-01-01']);
    $dead = StayFixtures::rate($fishery, 90.00, ['first_day_on' => '2026-07-01', 'last_day_on' => '2026-08-31']);
    $alive = StayFixtures::rate($fishery, 50.00, ['first_day_on' => '2026-05-01', 'last_day_on' => '2026-05-31']);

    $reported = array_map(
        static fn (PriceRule $r): int => (int) $r->id,
        (new PricingConfigurationAudit($fishery->fresh()))->deadRates(),
    );

    expect($reported)->toContain($dead->id)
        ->and($reported)->not->toContain($alive->id);
});

it('diagnostyka niczego nie zmienia w cenie', function (): void {
    [$fishery] = StayFixtures::fisheryWithPosition();

    StayFixtures::rate($fishery, 70.00);
    StayFixtures::rate($fishery, 90.00);

    /** @var array<int, PriceRule> $rules */
    $rules = $fishery->priceRules()->get()->all();
    $night = nightOn($fishery, '2026-05-10');

    $withoutDiagnostics = resolverFor($rules)->resolve($night, 1)->anglerAmountInCents();

    $resolver = resolverFor($rules);
    $resolver->candidatesFor($night);
    $withDiagnostics = $resolver->resolve($night, 1)->anglerAmountInCents();

    expect($withDiagnostics)->toBe($withoutDiagnostics)->and($withDiagnostics)->toBe(7000);
});

it('reguła zna swój rodzaj i nie miesza go przy zapisie', function (): void {
    [$fishery] = StayFixtures::fisheryWithPosition();

    $rate = StayFixtures::rate($fishery, 70.00);
    $surcharge = StayFixtures::surcharge($fishery, 20.00, 'Dopłata');

    expect($rate->kind)->toBe(PriceRuleKind::Rate)
        ->and($surcharge->kind)->toBe(PriceRuleKind::Surcharge);
});

/*
 * Testy dopisane po mutacjach zadania 023.
 */

it('powiadomienie o domknięciu nazywa stawkę jej nazwą, a bez nazwy — kwotą', function (): void {
    [$fishery] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00, ['first_day_on' => '2026-01-01', 'label' => 'Cennik 2026']);
    $new = StayFixtures::rate($fishery, 80.00, ['first_day_on' => '2027-01-01']);

    $closed = (new PriceRulePeriods($fishery))->closeSupersededRates([$new->id]);

    expect($closed)->toBe([['label' => 'Cennik 2026', 'until' => '2026-12-31']]);

    [$unnamed] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($unnamed, 70.00, ['first_day_on' => '2026-01-01']);
    $newer = StayFixtures::rate($unnamed, 80.00, ['first_day_on' => '2027-01-01']);

    expect((new PriceRulePeriods($unnamed))->closeSupersededRates([$newer->id]))
        ->toBe([['label' => '70.00', 'until' => '2026-12-31']]);
});

it('kandydaci i dopłaty wracają jako lista od zera, także po odfiltrowaniu pierwszej reguły', function (): void {
    [$fishery] = StayFixtures::fisheryWithPosition();

    $outside = StayFixtures::rate($fishery, 50.00, ['first_day_on' => '2027-01-01']);
    StayFixtures::rate($fishery, 70.00);
    StayFixtures::rate($fishery, 90.00);
    $suspended = StayFixtures::surcharge($fishery, 5.00, 'Zawieszona', ['is_suspended' => true]);
    StayFixtures::surcharge($fishery, 20.00, 'Prad');

    $resolver = resolverFor([$outside, ...$fishery->priceRules()->whereKeyNot($outside->id)->get()->all()]);

    expect(array_keys($resolver->candidatesForDay(CarbonImmutable::parse('2026-05-10'))))->toBe([0, 1])
        ->and(array_keys($resolver->resolve(nightOn($fishery, '2026-05-10'), 1)->surcharges))->toBe([0])
        ->and($suspended->is_suspended)->toBeTrue();
});

it('rozstrzygnięcie bez stawki ma zerową kwotę łowiącego i brak kwoty towarzyszącej', function (): void {
    [$fishery] = StayFixtures::fisheryWithPosition();

    $resolution = resolverFor([])->resolve(nightOn($fishery, '2026-05-10'), 1);

    expect($resolution->anglerAmountInCents())->toBe(0)
        ->and($resolution->companionAmountInCents())->toBeNull();
});
