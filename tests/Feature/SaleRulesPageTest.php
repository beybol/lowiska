<?php

namespace Tests\Feature;

use App\Filament\Resources\FisheryResource;
use App\Filament\Resources\FisheryResource\Pages\ManageSaleRules;
use App\Models\User;
use App\Models\WholeTermPeriod;
use App\Services\OwnerRoleProvisioner;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\StayFixtures;

/**
 * Ekran „Reguły sprzedaży" — nowa pozycja sub-nawigacji łowiska (zadanie 017).
 *
 * ⚠️ Strona jest STRONĄ ZASOBU `FisheryResource`, nie stroną panelu, więc trafia do obu
 * paneli bez dotykania providerów. Testy pilnują też dwóch rzeczy, których nie widać
 * po samym zapisie: przełącznik weekendu **nie jest kolumną** (stan wynika z danych,
 * wyłączenie czyści zbiór), a święta **nie dostają polityki** — dostępu pilnuje
 * `FisheryPolicy`.
 */
beforeEach(function () {
    Filament::setCurrentPanel('owner');
});

test('an owner can set the stay length', function () {
    [$fishery, , $owner] = StayFixtures::fisheryWithPosition();
    $this->actingAs($owner);

    Livewire::test(ManageSaleRules::class, ['record' => $fishery->getRouteKey()])
        ->fillForm(['min_nights' => 2, 'max_nights' => 7])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($fishery->fresh()->min_nights)->toBe(2)
        ->and($fishery->fresh()->max_nights)->toBe(7);
});

test('a maximum stay lower than the minimum is rejected', function () {
    [$fishery, , $owner] = StayFixtures::fisheryWithPosition();
    $this->actingAs($owner);

    Livewire::test(ManageSaleRules::class, ['record' => $fishery->getRouteKey()])
        ->fillForm(['min_nights' => 5, 'max_nights' => 3])
        ->call('save')
        ->assertHasFormErrors(['max_nights']);
});

/**
 * ⚠️ Przełącznik jest POLEM FORMULARZA, nie kolumną (`panel-wlasciciela.md` §6).
 * Stan wynika z wypełnienia `weekend_days`, a wyłączenie CZYŚCI zbiór — inaczej
 * zostawałby w kolumnie i wracał przy następnym włączeniu jako cicha reguła.
 */
test('the weekend toggle state comes from the data and switching it off clears the set', function () {
    [$fishery, , $owner] = StayFixtures::fisheryWithPosition(['weekend_days' => [5, 6]]);
    $this->actingAs($owner);

    Livewire::test(ManageSaleRules::class, ['record' => $fishery->getRouteKey()])
        ->assertFormSet(['weekend_whole' => true])
        ->fillForm(['weekend_whole' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($fishery->fresh()->weekend_days)->toBeNull();

    Livewire::test(ManageSaleRules::class, ['record' => $fishery->fresh()->getRouteKey()])
        ->assertFormSet(['weekend_whole' => false]);
});

test('the weekend set is saved as integers and validated as a run', function () {
    [$fishery, , $owner] = StayFixtures::fisheryWithPosition();
    $this->actingAs($owner);

    Livewire::test(ManageSaleRules::class, ['record' => $fishery->getRouteKey()])
        ->fillForm(['weekend_whole' => true, 'weekend_days' => ['5', '6']])
        ->call('save')
        ->assertHasNoFormErrors();

    // ⚠️ Liczby, nie łańcuchy: `CheckboxList` zapisuje łańcuchy, a kolumna JSON oddaje
    // to, co w niej zapisano.
    expect($fishery->fresh()->weekend_days)->toBe([5, 6]);

    Livewire::test(ManageSaleRules::class, ['record' => $fishery->fresh()->getRouteKey()])
        ->fillForm(['weekend_whole' => true, 'weekend_days' => ['1', '3']])
        ->call('save')
        ->assertHasFormErrors(['weekend_days']);
});

test('the weekend toggle and the terms section are off on a fishery without fishing day hours', function () {
    [$fishery, , $owner] = StayFixtures::fisheryWithPosition();
    $fishery->update(['day_start_time' => null, 'day_end_time' => null]);
    $this->actingAs($owner);

    Livewire::test(ManageSaleRules::class, ['record' => $fishery->fresh()->getRouteKey()])
        ->assertFormFieldIsDisabled('weekend_whole')
        ->assertFormFieldDoesNotExist('wholeTermPeriods');

    // Po ustawieniu godzin jedno i drugie wraca.
    $fishery->update(['day_start_time' => '15:00:00', 'day_end_time' => '15:00:00']);

    Livewire::test(ManageSaleRules::class, ['record' => $fishery->fresh()->getRouteKey()])
        ->assertFormFieldIsEnabled('weekend_whole')
        ->assertFormFieldExists('wholeTermPeriods');
});

test('an owner can add a term sold whole through the repeater', function () {
    [$fishery, , $owner] = StayFixtures::fisheryWithPosition();
    $this->actingAs($owner);

    Livewire::test(ManageSaleRules::class, ['record' => $fishery->getRouteKey()])
        ->fillForm([
            'wholeTermPeriods' => [
                ['name' => 'Majowka', 'first_day_on' => '2026-04-30', 'last_day_on' => '2026-05-02'],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $term = WholeTermPeriod::where('fishery_id', $fishery->id)->firstOrFail();

    expect($term->name)->toBe('Majowka')
        ->and($term->first_day_on->toDateString())->toBe('2026-04-30')
        ->and($term->last_day_on->toDateString())->toBe('2026-05-02');
});

test('a term outside the season is rejected by the form', function () {
    [$fishery, , $owner] = StayFixtures::fisheryWithPosition();
    $fishery->salePeriods()->delete();
    $fishery->salePeriods()->create(['starts_on' => '2026-05-01', 'ends_on' => '2026-05-31']);
    $this->actingAs($owner);

    Livewire::test(ManageSaleRules::class, ['record' => $fishery->fresh()->getRouteKey()])
        ->fillForm([
            'wholeTermPeriods' => [
                ['name' => 'Za sezonem', 'first_day_on' => '2026-06-10', 'last_day_on' => '2026-06-12'],
            ],
        ])
        ->call('save')
        ->assertHasFormErrors(['wholeTermPeriods']);

    expect(WholeTermPeriod::where('fishery_id', $fishery->id)->count())->toBe(0);
});

/**
 * ⚠️ Zawężenie widoczności bierze się z rodzica: strona należy do `FisheryResource`,
 * więc wiązanie rekordu przechodzi przez `getEloquentQuery()` z `forCurrentUser()`.
 * Cudze łowisko dla tej strony NIE ISTNIEJE (404), nie „istnieje, ale zabronione".
 */
test('an owner can not open the sale rules of a fishery he does not own', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();

    $intruder = User::factory()->create();
    OwnerRoleProvisioner::addOwnerRole($intruder);
    $this->actingAs($intruder);

    $this->get(FisheryResource::getUrl('sale-rules', ['record' => $fishery]))
        ->assertNotFound();
});

/**
 * ⚠️ Kontrola niezmiennika, nie kosmetyka: `WholeTermPeriod` nie dostaje polityki,
 * bo `shield:generate` wyprowadza uprawnienia z zarejestrowanych zasobów — polityka
 * pytająca o `'view_any:whole_term_period'` wywróciłaby `ShieldPermissionNamesTest`
 * (`autoryzacja.md` §5).
 */
test('the whole term period model does not get a policy of its own', function () {
    expect(file_exists(base_path('app/Policies/WholeTermPeriodPolicy.php')))->toBeFalse()
        ->and(count(glob(base_path('app/Policies/*.php'))))->toBe(17);
});

/*
 * Test dopisany po mutacjach zadania 023: pod chipami weekendu stoi podsumowanie wyboru.
 */
test('pod chipami weekendu widać podsumowanie zaznaczonych dób', function () {
    [$fishery, , $owner] = StayFixtures::fisheryWithPosition(['weekend_days' => [5, 6]]);
    $this->actingAs($owner);
    app()->setLocale('pl');

    Livewire::test(ManageSaleRules::class, ['record' => $fishery->getRouteKey()])
        ->assertSeeText('Od pt 15:00 do ndz 15:00 · 2 doby')
        ->assertSeeText('pt → sob');
});
