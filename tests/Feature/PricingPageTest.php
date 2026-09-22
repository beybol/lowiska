<?php

namespace Tests\Feature;

use App\Enums\ParticipantRole;
use App\Enums\PriceRuleKind;
use App\Filament\Resources\FisheryResource;
use App\Filament\Resources\FisheryResource\Pages\ManagePricing;
use App\Models\PriceRule;
use App\Models\User;
use App\Services\OwnerRoleProvisioner;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\StayFixtures;

/**
 * Ekran „Cennik" — nowa pozycja sub-nawigacji łowiska (zadanie 018).
 *
 * ⚠️ Strona jest STRONĄ ZASOBU `FisheryResource`, więc trafia do obu paneli bez dotykania
 * providerów. Testy pilnują też dwóch rzeczy niewidocznych po samym zapisie: remis jest
 * **błędem** (G2), a dziura i konflikt priorytetu z osobą towarzyszącą — **ostrzeżeniami**.
 */
beforeEach(function () {
    Filament::setCurrentPanel('owner');
});

/**
 * @return array<string, mixed>
 */
function pricingRow(array $overrides = []): array
{
    return array_merge([
        'amount' => '70.00',
        'label' => null,
        'priority' => 0,
        'is_suspended' => false,
        'effective_from' => null,
        'effective_to' => null,
        'weekdays' => null,
        'first_day_on' => null,
        'last_day_on' => null,
        'anglers_count' => null,
        'participant_role' => null,
    ], $overrides);
}

test('an owner can add a rate and a surcharge on separate lists', function () {
    [$fishery, , $owner] = StayFixtures::fisheryWithPosition();
    $this->actingAs($owner);

    Livewire::test(ManagePricing::class, ['record' => $fishery->getRouteKey()])
        ->fillForm([
            'rateRules' => [pricingRow(['amount' => '70.00'])],
            'surchargeRules' => [pricingRow(['amount' => '20,00', 'label' => 'Wylacznosc'])],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $rate = PriceRule::where('fishery_id', $fishery->id)->where('kind', PriceRuleKind::Rate->value)->firstOrFail();
    $surcharge = PriceRule::where('fishery_id', $fishery->id)->where('kind', PriceRuleKind::Surcharge->value)->firstOrFail();

    // ⚠️ Rodzaj ustawia hook repeatera — relacja zawężona po `kind` NIE wypełnia go sama.
    expect($rate->kind)->toBe(PriceRuleKind::Rate)
        ->and($surcharge->kind)->toBe(PriceRuleKind::Surcharge)
        // Przecinek i kropka zapisują się tak samo; asercja przez `DB::table`, nie przez akcesor.
        ->and((float) DB::table('price_rules')->where('id', $surcharge->id)->value('amount'))->toBe(20.0)
        ->and((float) DB::table('price_rules')->where('id', $rate->id)->value('amount'))->toBe(70.0);
});

/**
 * ⚠️ Remis jest BŁĘDEM zapisu, nie ostrzeżeniem — G2. Cena rozstrzygnięta po cichu byłaby ceną,
 * której operator nie zamierzał.
 */
test('two indistinguishable rates are rejected at save', function () {
    [$fishery, , $owner] = StayFixtures::fisheryWithPosition();
    $this->actingAs($owner);

    Livewire::test(ManagePricing::class, ['record' => $fishery->getRouteKey()])
        ->fillForm([
            'rateRules' => [
                pricingRow(['amount' => '70.00', 'priority' => 10, 'participant_role' => ParticipantRole::Companion->value]),
                pricingRow(['amount' => '90.00', 'priority' => 10, 'weekdays' => [5]]),
            ],
        ])
        ->call('save')
        ->assertHasFormErrors(['rateRules']);

    expect(PriceRule::where('fishery_id', $fishery->id)->count())->toBe(0);
});

/**
 * ⚠️ Podniesienie priorytetu stawki towarzyszącej rozwiązuje remis raz dla całego cennika —
 * to jest ta zasada konfiguracji, którą `MANUAL.md` musi wyłożyć operatorowi.
 */
test('raising the companion rate priority resolves the tie and the pricing follows', function () {
    [$fishery, $position, $owner] = StayFixtures::fisheryWithPosition();
    $this->actingAs($owner);

    Livewire::test(ManagePricing::class, ['record' => $fishery->getRouteKey()])
        ->fillForm([
            'rateRules' => [
                pricingRow(['amount' => '0.00', 'priority' => 100, 'participant_role' => ParticipantRole::Companion->value]),
                pricingRow(['amount' => '90.00', 'priority' => 10, 'weekdays' => [5]]),
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    // W piątek łowiący płaci 90, a towarzysząca 0.
    $breakdown = StayFixtures::pricing($position)->breakdown('2026-05-01', 1, anglers: 1, companions: 1);

    expect($breakdown->totalInCents())->toBe(9000);
});

/**
 * ⚠️ Priorytet WYŻSZY niż stawka towarzyszącej przechodzi z OSTRZEŻENIEM, nie błędem —
 * łowisko może świadomie chcieć, żeby w sylwestra płacili wszyscy.
 */
test('a rate outranking the companion rate saves with a warning', function () {
    [$fishery, $position, $owner] = StayFixtures::fisheryWithPosition();
    $this->actingAs($owner);

    Livewire::test(ManagePricing::class, ['record' => $fishery->getRouteKey()])
        ->fillForm([
            'rateRules' => [
                pricingRow(['amount' => '0.00', 'priority' => 100, 'participant_role' => ParticipantRole::Companion->value]),
                pricingRow(['amount' => '150.00', 'priority' => 200, 'label' => 'Sylwester']),
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(PriceRule::where('fishery_id', $fishery->id)->count())->toBe(2);

    // Skutek, o którym ostrzega komunikat: towarzysząca płaci pełną stawkę.
    $breakdown = StayFixtures::pricing($position)->breakdown('2026-05-01', 1, anglers: 1, companions: 1);

    expect($breakdown->totalInCents())->toBe(30000);
});

test('a price rule with reversed dates is rejected', function () {
    [$fishery, , $owner] = StayFixtures::fisheryWithPosition();
    $this->actingAs($owner);

    Livewire::test(ManagePricing::class, ['record' => $fishery->getRouteKey()])
        ->fillForm([
            'rateRules' => [pricingRow([
                'first_day_on' => '2026-06-01',
                'last_day_on' => '2026-05-01',
            ])],
        ])
        ->call('save')
        ->assertHasFormErrors(['rateRules']);
});

/**
 * ⚠️ Zawieszenie zostaje w cenniku — reguła nie znika, tylko przestaje brać udział w wycenie.
 */
test('a rule can be suspended without being removed', function () {
    [$fishery, $position, $owner] = StayFixtures::fisheryWithPosition();
    $this->actingAs($owner);

    Livewire::test(ManagePricing::class, ['record' => $fishery->getRouteKey()])
        ->fillForm([
            'rateRules' => [pricingRow(['amount' => '130.00'])],
            'surchargeRules' => [pricingRow(['amount' => '30.00', 'is_suspended' => true])],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(PriceRule::where('fishery_id', $fishery->id)->count())->toBe(2)
        ->and(StayFixtures::pricing($position)->breakdown('2026-05-01', 1)->totalInCents())->toBe(13000);
});

/**
 * ⚠️ Zawężenie widoczności bierze się z rodzica: strona należy do `FisheryResource`, więc
 * cudze łowisko dla niej NIE ISTNIEJE (404), nie „istnieje, ale zabronione".
 */
test('an owner can not open the pricing of a fishery he does not own', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();

    $intruder = User::factory()->create();
    OwnerRoleProvisioner::addOwnerRole($intruder);
    $this->actingAs($intruder);

    $this->get(FisheryResource::getUrl('pricing', ['record' => $fishery]))
        ->assertNotFound();
});

/**
 * ⚠️ Ten sam niezmiennik co przy `SalePeriod` i `WholeTermPeriod`: `shield:generate` wyprowadza
 * uprawnienia z zarejestrowanych zasobów, więc polityka dla `PriceRule` wywróciłaby
 * `ShieldPermissionNamesTest` (`autoryzacja.md` §5).
 */
test('the price rule model does not get a policy of its own', function () {
    expect(file_exists(base_path('app/Policies/PriceRulePolicy.php')))->toBeFalse()
        ->and(count(glob(base_path('app/Policies/*.php'))))->toBe(17);
});
