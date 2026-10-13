<?php

namespace Tests\Feature;

use App\Enums\AnglerLinkStatus;
use App\Filament\Resources\FisheryResource\Pages\ManageCalendar;
use App\Filament\Resources\ReservationResource\Pages\CreateReservation;
use App\Models\Fishery;
use App\Models\FisheryAngler;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;
use Tests\Support\ReservationFixtures;

/**
 * Podpowiedź istniejącego wędkarza łowiska w formularzach rezerwacji (zadanie 052) — reguła dopasowania
 * (`FisheryAngler::similarTo()`), pełny formularz C i krótki formularz kalendarza. Nic nie dowiązuje się bez kliknięcia.
 */
beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-05-13 12:00', 'Europe/Warsaw'));
    Filament::setCurrentPanel('owner');
});

afterEach(function () {
    Date::setTestNow();
});

function existingAngler(Fishery $fishery, string $name, ?string $phone = null, array $attributes = []): FisheryAngler
{
    return FisheryAngler::query()->create(array_merge([
        'fishery_id' => $fishery->id,
        'given_name' => $name,
        'given_phone' => $phone,
    ], $attributes));
}

test('candidates match a part of the name from three characters or the last nine digits of the phone', function () {
    [$fishery] = ReservationFixtures::bookableFishery();
    [$foreign] = ReservationFixtures::bookableFishery();
    $jan = existingAngler($fishery, 'Jan Kowalski');
    $phone = existingAngler($fishery, 'Ktoś Inny', '600-100-200');
    existingAngler($fishery, 'Jan Kowalski', null, ['status' => AnglerLinkStatus::Revoked->value]);
    existingAngler($foreign, 'Jan Kowalski', '600100200');

    $ids = fn (?string $name, ?string $number = null): array => FisheryAngler::similarTo($fishery, $name, $number)->modelKeys();

    expect($ids('kowal'))->toBe([$jan->id]) // część nazwy, bez wielkości liter; cofnięty i cudze łowisko poza
        ->and($ids('  jan   KOWALSKI '))->toBe([$jan->id]) // zbędne spacje
        ->and($ids('Ja'))->toBe([]) // za krótka fraza
        ->and($ids(null, '+48 600 100 200'))->toBe([$phone->id]) // ostatnie 9 cyfr
        ->and($ids(null, '00 100 200'))->toBe([]) // za krótki numer
        ->and($ids('Kowalski', '600100200'))->toBe([$phone->id, $jan->id]); // trafienie po telefonie przed częściową nazwą
});

test('there are at most five candidates and an exact name comes first', function () {
    [$fishery] = ReservationFixtures::bookableFishery();

    foreach (['Anna Nowak-Zielińska', 'Barbara Nowak', 'Celina Nowak', 'Dorota Nowak', 'Ewa Nowak', 'Felicja Nowak'] as $name) {
        existingAngler($fishery, $name);
    }
    $exact = existingAngler($fishery, 'Nowak');

    $candidates = FisheryAngler::similarTo($fishery, 'nowak', null);

    expect($candidates)->toHaveCount(FisheryAngler::SIMILAR_LIMIT)
        ->and($candidates->first()->id)->toBe($exact->id);
});

test('the full form suggests the existing angler and using it books without a new relation', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    $existing = existingAngler($fishery, 'Jan Kowalski', '+48 500 100 200');
    $this->actingAs($owner);

    Livewire::withQueryParams(['fishery' => $fishery->id])
        ->test(CreateReservation::class)
        ->fillForm(['angler_name' => 'jan kowalski', 'position_id' => $position->id, 'starts_on' => '2026-05-20', 'nights' => 1, 'anglers' => 1, 'companions' => 0])
        ->assertSee(__('This fishery already has an angler with these details'))
        ->assertSeeHtml('data-similar-angler="'.$existing->id.'"')
        ->callAction(TestAction::make('useSimilarAngler0')->schemaComponent('similarAngler0'))
        ->assertSchemaStateSet(['fishery_angler_id' => (string) $existing->id, 'angler_name' => 'Jan Kowalski', 'angler_phone' => '+48 500 100 200'])
        ->assertDontSee(__('This fishery already has an angler with these details'))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(FisheryAngler::query()->count())->toBe(1)
        ->and(Reservation::query()->sole()->fishery_angler_id)->toBe($existing->id);
});

test('without using the suggestion the form books a new relation as before', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    $existing = existingAngler($fishery, 'Jan Kowalski');
    $this->actingAs($owner);

    Livewire::withQueryParams(['fishery' => $fishery->id])
        ->test(CreateReservation::class)
        ->fillForm(['angler_name' => 'Jan Kowalski', 'position_id' => $position->id, 'starts_on' => '2026-05-20', 'nights' => 1, 'anglers' => 1, 'companions' => 0])
        ->assertSee(__('This fishery already has an angler with these details'))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(FisheryAngler::query()->count())->toBe(2)
        ->and(Reservation::query()->sole()->fishery_angler_id)->not->toBe($existing->id);
});

test('the quick calendar form suggests the existing angler, can undo the choice and books to the chosen relation', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    $existing = existingAngler($fishery, 'Jan Kowalski', '600100200');
    $this->actingAs($owner);
    $quickBook = TestAction::make('quickBook')->arguments(['position' => $position->id, 'date' => '2026-05-14', 'nights' => 1]);

    Livewire::test(ManageCalendar::class, ['record' => $fishery->getKey()])
        ->mountAction($quickBook)
        ->fillForm(['angler_name' => 'Klient', 'angler_phone' => '+48 600 100 200'])
        ->assertMountedActionModalSee(__('This fishery already has an angler with these details'))
        ->callAction(TestAction::make('useSimilarAngler0')->schemaComponent('similarAngler0', 'mountedActionSchema0'))
        ->assertSchemaStateSet(['fishery_angler_id' => (string) $existing->id, 'angler_name' => 'Jan Kowalski'], 'mountedActionSchema0')
        ->assertMountedActionModalSee(__('Existing angler of this fishery'))
        // „Change" odpina relację i zostawia wpisane wartości.
        ->callAction(TestAction::make('changeAngler')->schemaComponent('chosenAngler', 'mountedActionSchema0'))
        ->assertSchemaStateSet(['fishery_angler_id' => null, 'angler_name' => 'Jan Kowalski'], 'mountedActionSchema0')
        ->callAction(TestAction::make('useSimilarAngler0')->schemaComponent('similarAngler0', 'mountedActionSchema0'))
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(FisheryAngler::query()->count())->toBe(1)
        ->and(Reservation::query()->sole()->fishery_angler_id)->toBe($existing->id);
});

test('a candidate id from another fishery does not link the quick booking', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    [$foreign] = ReservationFixtures::bookableFishery();
    $stranger = existingAngler($foreign, 'Jan Kowalski');
    $this->actingAs($owner);

    Livewire::test(ManageCalendar::class, ['record' => $fishery->getKey()])
        ->callAction(
            TestAction::make('quickBook')->arguments(['position' => $position->id, 'date' => '2026-05-14', 'nights' => 1]),
            data: ['fishery_angler_id' => (string) $stranger->id, 'angler_name' => 'Jan Kowalski', 'nights' => 1, 'anglers' => 1],
        );

    expect(Reservation::query()->where('fishery_angler_id', $stranger->id)->exists())->toBeFalse()
        ->and(Reservation::query()->count())->toBe(0);
});
