<?php

namespace Tests\Feature;

use App\Enums\ParticipantRole;
use App\Enums\PriceRuleKind;
use App\Filament\Resources\FisheryResource;
use App\Filament\Resources\FisheryResource\Pages\ManagePricing;
use App\Models\PriceRule;
use App\Models\User;
use App\Services\OwnerRoleProvisioner;
use App\Services\PricingConfigurationAudit;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Date;
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

/**
 * ⚠️ Pusty `Select` w formularzu przysyła PUSTY ŁAŃCUCH, nie `null` — i to jest stan NORMALNY,
 * bo stawka bazowa nie ma warunku roli ani obsady. Reguła remisu hydratowała z tego model,
 * więc rzut enuma wywracał CAŁY zapis (`ValueError: "" is not a valid backing value`).
 * Fixture z jawnym `null` tego nie widziała — dlatego ten test podaje dokładnie to, co wysyła
 * przeglądarka.
 */
test('empty selects in the form are treated as no condition', function () {
    [$fishery, , $owner] = StayFixtures::fisheryWithPosition();
    $this->actingAs($owner);

    Livewire::test(ManagePricing::class, ['record' => $fishery->getRouteKey()])
        ->fillForm([
            'rateRules' => [
                pricingRow(['amount' => '70.00', 'participant_role' => '', 'anglers_count' => '', 'weekdays' => []]),
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $rate = PriceRule::where('fishery_id', $fishery->id)->firstOrFail();

    expect($rate->participant_role)->toBeNull()
        ->and($rate->anglers_count)->toBeNull()
        // ⚠️ Pusta oś NIE liczy się do szczegółowości — inaczej stawka bazowa udawałaby
        // regułę warunkową i wygrywałaby remisy, których nie powinna.
        ->and($rate->specificity())->toBe(0);
});

/**
 * ⚠️ Treść ostrzeżenia o dziurze jest **częścią interfejsu**, nie logiem. Odtwarza zgłoszenie
 * z 2026-09-22: stawka z warunkiem „poniedziałek–czwartek" zostawia piątki bez ceny, a operator
 * dostał wtedy komunikat, który nie mówił ani dnia tygodnia, ani gdzie szukać przyczyny — za to
 * wspominał o „regule wygasającej później", choć żadna jego reguła nie miała dat obowiązywania.
 */
test('the pricing gap warning names the weekday and points at the conditions', function () {
    Date::setTestNow(CarbonImmutable::parse('2026-09-22 09:00', 'Europe/Warsaw'));

    [$fishery, , $owner] = StayFixtures::fisheryWithPosition();
    $fishery->salePeriods()->update(['starts_on' => '2026-09-01', 'ends_on' => '2026-12-31']);
    StayFixtures::rate($fishery, 70.00, ['weekdays' => [1, 2, 3, 4]]);

    $gap = (new PricingConfigurationAudit($fishery->fresh()))->firstPricingGap();

    // Pierwsza doba bez ceny to piątek 25.09 — wtorek, środa i czwartek stawkę mają.
    expect($gap)->not->toBeNull()
        ->and($gap['night']->toDateString())->toBe('2026-09-25')
        ->and($gap['role'])->toBe(ParticipantRole::Angler);

    // ⚠️ Asercja idzie przez REALNY zapis i powiadomienie, które zobaczy operator — nie przez
    // refleksję na prywatnej metodzie. Pinujemy komunikat, nie jego implementację.
    app()->setLocale('pl');
    $this->actingAs($owner);

    Livewire::test(ManagePricing::class, ['record' => $fishery->fresh()->getRouteKey()])
        ->call('save')
        ->assertNotified(
            Notification::make()
                ->warning()
                ->title(__('Some nights have no price — anglers can not buy them'))
                ->body(
                    // Dzień tygodnia prowadzi wprost do pola, które trzeba poprawić; rola
                    // i obsada są w zwykłym przypadku szumem, więc komunikat ich nie niesie.
                    __('The first one is :night. None of your rates covers it — check the conditions on your rates: nights of the week, date range, number of anglers, role.', [
                        'night' => 'piątek, 25.09.2026',
                    ])
                    .' '.__('Checked against the price list as it stands today.')
                ),
        );

    Date::setTestNow();
});
