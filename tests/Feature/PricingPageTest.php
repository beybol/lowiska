<?php

namespace Tests\Feature;

use App\Enums\PriceRuleKind;
use App\Enums\SurchargeAudience;
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
 * Ekran „Cennik" po przedefiniowaniu zadania 018.
 *
 * ⚠️ Strona jest STRONĄ ZASOBU `FisheryResource`, więc trafia do obu paneli bez dotykania
 * providerów.
 *
 * ⚠️ **Stawka i dopłata mają teraz RÓŻNE zestawy pól** — dlatego dwie osobne fabryki wierszy.
 * Wspólna fabryka wróciłaby razem z założeniem, że warunki są na obu rodzajach reguł.
 */
beforeEach(function () {
    Filament::setCurrentPanel('owner');
});

/**
 * Wiersz stawki: kwoty i daty, nic więcej.
 *
 * @return array<string, mixed>
 */
function rateRow(array $overrides = []): array
{
    return array_merge([
        'amount' => '70.00',
        'amount_companion' => '0.00',
        'is_suspended' => false,
        'first_day_on' => null,
        'last_day_on' => null,
    ], $overrides);
}

/**
 * Wiersz dopłaty: cały ciężar warunkowy cennika.
 *
 * @return array<string, mixed>
 */
function surchargeRow(array $overrides = []): array
{
    return array_merge([
        'amount' => '20.00',
        'label' => null,
        'applies_to' => SurchargeAudience::Angler->value,
        'is_suspended' => false,
        'first_day_on' => null,
        'last_day_on' => null,
        'weekdays' => null,
        'anglers_count' => null,
    ], $overrides);
}

test('an owner can add a rate and a surcharge on separate lists', function () {
    [$fishery, , $owner] = StayFixtures::fisheryWithPosition();
    $this->actingAs($owner);

    Livewire::test(ManagePricing::class, ['record' => $fishery->getRouteKey()])
        ->fillForm([
            'rateRules' => [rateRow(['amount' => '70.00'])],
            'surchargeRules' => [surchargeRow(['amount' => '20,00', 'label' => 'Wylacznosc'])],
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
 * ⚠️ Pola dopłaty NIE mogą zostać na stawce. Kolumny są wspólne, bo rodzaj reguły jest flagą,
 * ale zostawienie w nich czegokolwiek znaczyłoby trzymanie w bazie danych, których nikt nie
 * interpretuje — a przy pierwszej zmianie w wycenie zaczęłyby coś znaczyć.
 */
test('saving a rate clears the surcharge-only columns', function () {
    [$fishery, , $owner] = StayFixtures::fisheryWithPosition();
    $this->actingAs($owner);

    Livewire::test(ManagePricing::class, ['record' => $fishery->getRouteKey()])
        ->fillForm(['rateRules' => [rateRow()]])
        ->call('save')
        ->assertHasNoFormErrors();

    $row = DB::table('price_rules')->where('fishery_id', $fishery->id)->first();

    expect($row->weekdays)->toBeNull()
        ->and($row->anglers_count)->toBeNull()
        ->and($row->applies_to)->toBeNull();
});

/**
 * ⚠️ Odtwarza zgłoszenie z 2026-09-22: pusty `Select` przysyła PUSTY ŁAŃCUCH, nie `null`,
 * a rzut enuma na `''` wywracał CAŁY zapis (`ValueError`). Fixture z jawnym `null` tego nie
 * widziała — dlatego ten test podaje dokładnie to, co wysyła przeglądarka.
 */
test('empty selects in the form are treated as no condition', function () {
    [$fishery, , $owner] = StayFixtures::fisheryWithPosition();
    $this->actingAs($owner);

    Livewire::test(ManagePricing::class, ['record' => $fishery->getRouteKey()])
        ->fillForm([
            'rateRules' => [rateRow(['first_day_on' => '', 'last_day_on' => ''])],
            'surchargeRules' => [surchargeRow(['anglers_count' => '', 'weekdays' => [], 'label' => ''])],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $rate = PriceRule::where('fishery_id', $fishery->id)->where('kind', PriceRuleKind::Rate->value)->firstOrFail();
    $surcharge = PriceRule::where('fishery_id', $fishery->id)->where('kind', PriceRuleKind::Surcharge->value)->firstOrFail();

    expect($rate->first_day_on)->toBeNull()
        ->and($rate->last_day_on)->toBeNull()
        ->and($surcharge->anglers_count)->toBeNull()
        ->and($surcharge->label)->toBeNull();
});

/**
 * ⚠️ Domyślne „dla łowiącego", NIE „dla każdego": osoba towarzysząca bywa darmowa, więc
 * domyślne obciążanie jej byłoby pomyłką najtrudniejszą do zauważenia w całym cenniku.
 */
test('the default audience of a surcharge does not charge companions', function () {
    [$fishery, $position, $owner] = StayFixtures::fisheryWithPosition();
    $this->actingAs($owner);

    Livewire::test(ManagePricing::class, ['record' => $fishery->getRouteKey()])
        ->fillForm([
            'rateRules' => [rateRow(['amount' => '70.00'])],
            // Wartość domyślna pola — dokładnie to, co zapisze operator, który go nie dotknie.
            'surchargeRules' => [surchargeRow()],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    // ⚠️ Gdyby domyślne było „dla każdego", wyszłoby 110,00 zł — dopłata obciążyłaby DARMOWĄ
    // osobę towarzyszącą. To jest pomyłka najtrudniejsza do zauważenia w całym cenniku.
    expect(StayFixtures::pricing($position)->breakdown('2026-05-07', 1, anglers: 1, companions: 1)->totalInCents())
        ->toBe(9000)
        // Kolejność opcji też jest decyzją: „dla łowiącego" stoi pierwsze, bo jest domyślne.
        ->and(array_key_first(SurchargeAudience::options()))->toBe(SurchargeAudience::Angler->value);
});

/**
 * ⚠️ Puste pole kwoty za osobę towarzyszącą znaczy BRAK CENY, czyli odmowę sprzedaży komuś
 * z osobą towarzyszącą. Formularz nie pozwala tego zapisać przez nieuwagę.
 */
test('a rate without a companion amount is rejected', function () {
    [$fishery, , $owner] = StayFixtures::fisheryWithPosition();
    $this->actingAs($owner);

    Livewire::test(ManagePricing::class, ['record' => $fishery->getRouteKey()])
        ->fillForm(['rateRules' => [rateRow(['amount_companion' => null])]])
        ->call('save')
        ->assertHasFormErrors();

    expect(PriceRule::where('fishery_id', $fishery->id)->count())->toBe(0);
});

test('a price rule with reversed dates is rejected', function () {
    [$fishery, , $owner] = StayFixtures::fisheryWithPosition();
    $this->actingAs($owner);

    Livewire::test(ManagePricing::class, ['record' => $fishery->getRouteKey()])
        ->fillForm([
            'rateRules' => [rateRow([
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
            'rateRules' => [rateRow(['amount' => '130.00'])],
            'surchargeRules' => [surchargeRow(['amount' => '30.00', 'is_suspended' => true])],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(PriceRule::where('fishery_id', $fishery->id)->count())->toBe(2)
        ->and(StayFixtures::pricing($position)->breakdown('2026-05-01', 1)->totalInCents())->toBe(13000);
});

/**
 * ⚠️ Domknięcie jest automatyczne, ale NIGDY ciche — cicha zmiana cudzego wpisu jest gorsza
 * niż brak automatu.
 */
test('adding a new open-ended rate closes the previous one and says so', function () {
    [$fishery, , $owner] = StayFixtures::fisheryWithPosition();
    $this->actingAs($owner);

    StayFixtures::rate($fishery, 70.00, ['first_day_on' => '2026-01-01']);

    app()->setLocale('pl');

    Livewire::test(ManagePricing::class, ['record' => $fishery->fresh()->getRouteKey()])
        ->fillForm([
            'rateRules' => [
                rateRow(['amount' => '70.00', 'first_day_on' => '2026-01-01']),
                rateRow(['amount' => '80.00', 'first_day_on' => '2027-01-01']),
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        // ⚠️ Stawka nie ma pola nazwy (pięć pól), więc powiadomienie nazywa ją KWOTĄ.
        ->assertNotified(__('A previous rate was closed'));

    // ⚠️ Asercja idzie po KWOCIE, nie po identyfikatorze sprzed zapisu: repeater relacyjny
    // Filamenta usuwa wiersze i tworzy je od nowa, więc stary rekord po zapisie już nie żyje.
    $closed = PriceRule::where('fishery_id', $fishery->id)->where('amount', 70.00)->firstOrFail();
    $current = PriceRule::where('fishery_id', $fishery->id)->where('amount', 80.00)->firstOrFail();

    expect($closed->last_day_on->toDateString())->toBe('2026-12-31')
        ->and($current->last_day_on)->toBeNull();
});

/**
 * ⚠️ Stawka-okno NIE domyka niczego i NIE dostaje ostrzeżenia, choć nic nie zrobi. Skutki
 * nachodzenia pokazuje kalendarz (019), żeby ta wiedza miała jeden dom.
 */
test('adding a dated window rate closes nothing', function () {
    [$fishery, , $owner] = StayFixtures::fisheryWithPosition();
    $this->actingAs($owner);

    $openEnded = StayFixtures::rate($fishery, 70.00, ['first_day_on' => '2026-01-01']);

    Livewire::test(ManagePricing::class, ['record' => $fishery->fresh()->getRouteKey()])
        ->fillForm([
            'rateRules' => [
                rateRow(['amount' => '70.00', 'first_day_on' => '2026-01-01']),
                rateRow(['amount' => '90.00', 'first_day_on' => '2026-07-01', 'last_day_on' => '2026-08-31']),
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($openEnded->fresh()->last_day_on)->toBeNull();
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
 * ⚠️ Treść ostrzeżenia o dziurze jest **częścią interfejsu**, nie logiem.
 *
 * ⚠️ Po zdjęciu dni tygodnia ze stawki dziura może mieć już tylko przyczynę DATOWĄ — i komunikat
 * to odzwierciedla: nie wymienia ani warunków tygodnia, ani obsady, ani roli, bo stawka ich nie
 * zna, a wymienianie ich posyłałoby operatora szukać pól, których nie ma.
 */
test('the pricing gap warning names the weekday and points at the dates', function () {
    Date::setTestNow(CarbonImmutable::parse('2026-09-22 09:00', 'Europe/Warsaw'));

    [$fishery, , $owner] = StayFixtures::fisheryWithPosition();
    $fishery->salePeriods()->update(['starts_on' => '2026-09-01', 'ends_on' => '2026-12-31']);
    StayFixtures::rate($fishery, 70.00, ['first_day_on' => '2026-10-01']);

    $gap = (new PricingConfigurationAudit($fishery->fresh()))->firstPricingGap();

    // Sprzedaż jest otwarta od dziś, a stawka zaczyna się dopiero 1.10 — pierwsza dziura to dziś.
    expect($gap)->not->toBeNull()
        ->and($gap->toDateString())->toBe('2026-09-22');

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
                    __('The first one is :night. None of your rates covers it — check the dates on your rates.', [
                        'night' => 'wtorek, 22.09.2026',
                    ])
                    .' '.__('Checked against the price list as it stands today.')
                ),
        );

    Date::setTestNow();
});
