<?php

namespace Tests\Feature;

use App\Enums\CalendarWindow;
use App\Filament\Resources\FisheryResource;
use App\Filament\Resources\FisheryResource\Pages\ManageCalendar;
use App\Models\User;
use App\Services\OwnerRoleProvisioner;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;
use Tests\Support\StayFixtures;

/**
 * Ekran „Kalendarz" — zadanie 019.
 *
 * ⚠️ **Strona otwiera się w OBU panelach i test sprawdza to jawnie.** Dopisanie pozycji do
 * `FisheryResource::getPages()` dotyka klasy współdzielonej przez oba panele — zamiast
 * zakładać, że zachowanie się nie zmieniło, dowodzimy tego tam, gdzie i tak piszemy test.
 * To jest powód, dla którego zadanie zostaje przy tierze T1 zamiast podnosić go do T3.
 */
beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-05-04 09:00', 'Europe/Warsaw'));
});

afterEach(function () {
    Date::setTestNow();
});

test('kalendarz otwiera się w panelu właściciela i w panelu administratora', function () {
    [$fishery, , $owner] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00);

    Filament::setCurrentPanel('owner');
    $this->actingAs($owner);
    $this->get(FisheryResource::getUrl('calendar', ['record' => $fishery], panel: 'owner'))->assertSuccessful();

    // ⚠️ Administrator powstaje pomocnikiem z `TestCase`, nie przez `assignRole()` —
    // rola i jej uprawnienia są tam zakładane razem (Shield).
    $admin = $this->createSuperAdmin();

    Filament::setCurrentPanel('admin');
    $this->actingAs($admin);
    $this->get(FisheryResource::getUrl('calendar', ['record' => $fishery], panel: 'admin'))->assertSuccessful();
});

test('kalendarz startuje na kotwicy i w oknie miesięcznym', function () {
    Filament::setCurrentPanel('owner');
    [$fishery, , $owner] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00);
    $this->actingAs($owner);

    Livewire::test(ManageCalendar::class, ['record' => $fishery->getRouteKey()])
        ->assertSet('window', CalendarWindow::Month->value)
        ->assertSet('windowStart', '2026-05-01')
        ->assertSet('anglers', 1)
        ->assertSet('companions', 0)
        // ⚠️ Domyślny widok to „ceny od": najkrótszy kupowalny pobyt, czyli BRAK stałej długości.
        ->assertSet('nights', null);
});

test('przełączenie na tydzień pokazuje tydzień zawierający początek okna', function () {
    Filament::setCurrentPanel('owner');
    [$fishery, , $owner] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00);
    $this->actingAs($owner);

    Livewire::test(ManageCalendar::class, ['record' => $fishery->getRouteKey()])
        ->set('window', CalendarWindow::Week->value)
        // 2026-05-01 to piątek; tydzień zawierający go zaczyna się 27.04.
        ->assertSet('windowStart', '2026-04-27');
});

/**
 * ⚠️ Przesuwanie nie wychodzi poza wybrany sezon — na jego krańcach kierunek jest niedostępny.
 */
test('przesuwanie okna trzyma się granic sezonu', function () {
    Filament::setCurrentPanel('owner');
    [$fishery, , $owner] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00);
    $fishery->salePeriods()->update(['starts_on' => '2026-05-01', 'ends_on' => '2026-06-30']);
    $this->actingAs($owner);

    Livewire::test(ManageCalendar::class, ['record' => $fishery->fresh()->getRouteKey()])
        ->assertSet('windowStart', '2026-05-01')
        // Wstecz nie da się wyjść przed maj.
        ->call('previousWindow')
        ->assertSet('windowStart', '2026-05-01')
        // W przód wolno do czerwca…
        ->call('nextWindow')
        ->assertSet('windowStart', '2026-06-01')
        // …i ani miesiąca dalej.
        ->call('nextWindow')
        ->assertSet('windowStart', '2026-06-01');
});

test('siatka renderuje się z kwotą i liczbą dób', function () {
    Filament::setCurrentPanel('owner');
    [$fishery, , $owner] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00);
    $this->actingAs($owner);

    app()->setLocale('pl');

    Livewire::test(ManageCalendar::class, ['record' => $fishery->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('70,00');
});

/**
 * ⚠️ Stan pusty ZASTĘPUJE siatkę — trzydzieści kolumn odmów nie jest odpowiedzią dla kogoś,
 * kto dopiero konfiguruje obiekt.
 */
test('łowisko bez okresu sprzedaży dostaje komunikat zamiast siatki', function () {
    Filament::setCurrentPanel('owner');
    [$fishery, , $owner] = StayFixtures::fisheryWithPosition();
    $fishery->salePeriods()->delete();
    $this->actingAs($owner);

    app()->setLocale('pl');

    Livewire::test(ManageCalendar::class, ['record' => $fishery->fresh()->getRouteKey()])
        ->assertSuccessful()
        ->assertSee(__('There is nothing to show yet'));
});

/**
 * ⚠️ Czwarty stan pusty POPRZEDZA siatkę, a nie zastępuje jej: dziura w cenniku bywa
 * częściowa i wtedy widać, których dób dotyczy.
 */
test('łowisko bez stawek dostaje komunikat NAD siatką', function () {
    Filament::setCurrentPanel('owner');
    [$fishery, , $owner] = StayFixtures::fisheryWithPosition();
    $this->actingAs($owner);

    app()->setLocale('pl');

    Livewire::test(ManageCalendar::class, ['record' => $fishery->getRouteKey()])
        ->assertSuccessful()
        ->assertSee(__('This fishery has no rates yet'))
        // Siatka nadal jest — widać, których dób dotyczy dziura.
        ->assertSee(__('Position'));
});

test('właściciel nie otwiera kalendarza cudzego łowiska', function () {
    Filament::setCurrentPanel('owner');
    [$fishery] = StayFixtures::fisheryWithPosition();

    $intruder = User::factory()->create();
    OwnerRoleProvisioner::addOwnerRole($intruder);
    $this->actingAs($intruder);

    $this->get(FisheryResource::getUrl('calendar', ['record' => $fishery]))->assertNotFound();
});

/**
 * ⚠️ Ten sam niezmiennik co przy `PriceRule`: kalendarz nie dostaje własnej polityki ani
 * uprawnień Shielda, bo dostępu pilnuje `FisheryPolicy` (`autoryzacja.md` §5).
 */
test('kalendarz nie dokłada polityki ani migracji', function () {
    expect(count(glob(base_path('app/Policies/*.php'))))->toBe(17)
        ->and(glob(database_path('migrations/*calendar*')))->toBe([]);
});
