<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Enums\TermsExceptionKind;
use App\Filament\Resources\FisheryResource;
use App\Filament\Resources\FisheryResource\Pages\ManageCalendar;
use App\Filament\Resources\FisheryResource\Pages\ManageReservations;
use App\Filament\Resources\ReservationResource;
use App\Filament\Resources\ReservationResource\Pages\CreateReservation;
use App\Filament\Resources\ReservationResource\Pages\ViewReservation;
use App\Mail\ReservationCancelledMail;
use App\Models\Reservation;
use App\Models\User;
use App\Services\AnglerDetails;
use App\Services\OwnerRoleProvisioner;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\ReservationFixtures;
use Tests\Support\StayFixtures;

/**
 * Rezerwacja przez operatora — lista (B), formularz (C), szczegóły (D), modale (zadanie 041).
 */
beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-04-01 12:00', 'Europe/Warsaw'));
    Filament::setCurrentPanel('owner');
});

afterEach(function () {
    Date::setTestNow();
});

function reservationFormData(int $positionId, array $overrides = []): array
{
    return array_merge([
        'angler_name' => 'Jan Kowalski',
        'angler_phone' => '+48500100200',
        'angler_email' => 'jan@example.com',
        'position_id' => $positionId,
        'starts_on' => '2026-05-13',
        'nights' => 2,
        'anglers' => 1,
        'companions' => 0,
    ], $overrides);
}

test('the operator books from the full-page form through the booking service', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    $this->actingAs($owner);

    Livewire::withQueryParams(['fishery' => $fishery->id])
        ->test(CreateReservation::class)
        ->fillForm(reservationFormData($position->id))
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(route(ManageReservations::getRouteName(), ['record' => $fishery]));

    $reservation = Reservation::query()->sole();

    expect($reservation->slots()->count())->toBe(2)
        ->and($reservation->terms->total_cents)->toBe(14000)
        ->and($reservation->created_by)->toBe($owner->id)
        ->and($reservation->angler->given_email)->toBe('jan@example.com');
});

test('the form prefills position, arrival and nights from the address', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    [, $foreign] = ReservationFixtures::bookableFishery();
    $this->actingAs($owner);

    Livewire::withQueryParams(['fishery' => $fishery->id, 'position' => $position->id, 'from' => '2026-05-20', 'nights' => 3])
        ->test(CreateReservation::class)
        ->assertSchemaStateSet(['position_id' => $position->id, 'starts_on' => '2026-05-20', 'nights' => 3]);

    // Stanowisko cudzego łowiska z adresu się nie wypełnia.
    Livewire::withQueryParams(['fishery' => $fishery->id, 'position' => $foreign->id])
        ->test(CreateReservation::class)
        ->assertSchemaStateSet(['position_id' => null]);
});

test('an occupied term is refused and the form stays open', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    ReservationFixtures::book($position, '2026-05-13', 2);
    $this->actingAs($owner);

    Livewire::withQueryParams(['fishery' => $fishery->id])
        ->test(CreateReservation::class)
        ->fillForm(reservationFormData($position->id, ['angler_email' => 'inny@example.com']))
        ->call('create')
        ->assertNotified()
        ->assertNoRedirect();

    expect(Reservation::query()->count())->toBe(1);
});

test('booking against the sale rules needs a reason and records the exception', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    StayFixtures::blockSale($fishery, $position, '2026-05-13', '2026-05-13');
    $this->actingAs($owner);

    Livewire::withQueryParams(['fishery' => $fishery->id])
        ->test(CreateReservation::class)
        ->fillForm(reservationFormData($position->id, ['nights' => 1]))
        ->assertSee(__('Outside the sale rules'))
        ->call('create')
        ->assertNoRedirect();

    expect(Reservation::query()->count())->toBe(0);

    Livewire::withQueryParams(['fishery' => $fishery->id])
        ->test(CreateReservation::class)
        ->fillForm(reservationFormData($position->id, ['nights' => 1, 'exception_reason' => 'Zawody']))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Reservation::query()->sole()->terms->hasException(TermsExceptionKind::SaleBlocked))->toBeTrue();
});

test('an agreed price requires a reason', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    $this->actingAs($owner);

    Livewire::withQueryParams(['fishery' => $fishery->id])
        ->test(CreateReservation::class)
        ->fillForm(reservationFormData($position->id, ['agreed' => true, 'agreed_total' => '100.00']))
        ->call('create')
        ->assertHasFormErrors(['agreed_reason' => 'required']);

    Livewire::withQueryParams(['fishery' => $fishery->id])
        ->test(CreateReservation::class)
        ->fillForm(reservationFormData($position->id, ['agreed' => true, 'agreed_total' => '100.00', 'agreed_reason' => 'Stały klient']))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Reservation::query()->sole()->terms->total_cents)->toBe(10000);
});

test('the list shows only this fishery, filters by tab and searches within its anglers', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    [, $otherPosition] = ReservationFixtures::bookableFishery();
    $mine = ReservationFixtures::book($position, '2026-05-13', 1, ['angler' => new AnglerDetails('Zenon Wlasny', 'zenon@example.com')]);
    $contact = ReservationFixtures::book($position, '2026-05-20', 1, ['angler' => new AnglerDetails('Ola Telefon', null, '+48700')]);
    $contact->update(['needs_contact_at' => now()]);
    $foreign = ReservationFixtures::book($otherPosition, '2026-05-13', 1, ['angler' => new AnglerDetails('Obcy Wedkarz', 'obcy@example.com')]);
    $this->actingAs($owner);

    Livewire::test(ManageReservations::class, ['record' => $fishery->getKey()])
        ->assertCanSeeTableRecords([$mine, $contact])
        ->assertCanNotSeeTableRecords([$foreign])
        ->searchTable('Obcy')
        ->assertCanNotSeeTableRecords([$foreign, $mine])
        ->searchTable('Zenon')
        ->assertCanSeeTableRecords([$mine])
        ->searchTable(null)
        ->set('activeTab', 'to_contact')
        ->assertCanSeeTableRecords([$contact])
        ->assertCanNotSeeTableRecords([$mine]);
});

test('the reservations page sits right after the calendar and its badge counts what needs attention', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    $this->actingAs($owner);
    $page = Livewire::test(ManageCalendar::class, ['record' => $fishery->getKey()])->instance();
    $pages = collect(FisheryResource::getRecordSubNavigation($page))->map(fn ($item) => $item->getLabel())->values()->all();

    expect(array_search(__('Reservations'), $pages, true))->toBe(array_search(__('Calendar'), $pages, true) + 1);

    expect(ManageReservations::badgeFor($fishery))->toBeNull();

    ReservationFixtures::book($position, '2026-05-13', 1, ['confirmBy' => CarbonImmutable::now()->addHours(5)]);
    ReservationFixtures::book($position, '2026-05-20', 1, ['angler' => new AnglerDetails('B', 'b@example.com')])
        ->update(['needs_contact_at' => now()]);
    ReservationFixtures::book($position, '2026-06-01', 1, ['angler' => new AnglerDetails('C', 'c@example.com'), 'confirmBy' => CarbonImmutable::now()->addDays(3)]);

    expect(ManageReservations::badgeFor($fishery->fresh()))->toBe('2');
});

test('cancelling frees the slots, marks a paid reservation as refund and emails the angler', function () {
    Mail::fake();
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    $reservation = ReservationFixtures::book($position, '2026-05-13', 2);
    $reservation->update(['payment_status' => PaymentStatus::Paid]);
    $this->actingAs($owner);

    Livewire::test(ManageReservations::class, ['record' => $fishery->getKey()])
        ->callAction(TestAction::make('cancelReservation')->table($reservation), data: [
            'refund' => 'terms',
            'message' => 'Awaria pomostu',
        ])
        ->assertHasNoActionErrors();

    $fresh = $reservation->fresh();

    expect($fresh->status)->toBe(ReservationStatus::Cancelled)
        ->and($fresh->payment_status)->toBe(PaymentStatus::Refund)
        ->and($fresh->slots()->count())->toBe(0)
        ->and($fresh->needs_contact_at)->toBeNull();

    Mail::assertSent(ReservationCancelledMail::class, fn (ReservationCancelledMail $mail): bool => $mail->hasTo('jan@example.com')
        && str_contains($mail->render(), 'Awaria pomostu'));
});

test('a reservation without email lands on the to-contact list after cancellation', function () {
    Mail::fake();
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    $reservation = ReservationFixtures::book($position, '2026-05-13', 1, ['angler' => new AnglerDetails('Pan Telefon', null, '+48600')]);
    $this->actingAs($owner);

    Livewire::test(ManageReservations::class, ['record' => $fishery->getKey()])
        ->callAction(TestAction::make('cancelReservation')->table($reservation), data: ['refund' => 'terms'])
        ->assertHasNoActionErrors();

    expect($reservation->fresh()->needsContact())->toBeTrue();
    Mail::assertNothingSent();

    Livewire::test(ManageReservations::class, ['record' => $fishery->getKey()])
        ->set('activeTab', 'to_contact')
        ->callAction(TestAction::make('markContacted')->table($reservation->fresh()));

    expect($reservation->fresh()->needsContact())->toBeFalse();
});

test('cancelling an ongoing stay requires the extra confirmation', function () {
    Mail::fake();
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    $reservation = ReservationFixtures::book($position, '2026-05-13', 3);
    Date::setTestNow(CarbonImmutable::parse('2026-05-14 10:00', 'Europe/Warsaw'));
    $this->actingAs($owner);

    Livewire::test(ManageReservations::class, ['record' => $fishery->getKey()])
        ->callAction(TestAction::make('cancelReservation')->table($reservation), data: ['refund' => 'terms'])
        ->assertHasActionErrors(['confirm_in_progress']);

    expect($reservation->fresh()->status)->toBe(ReservationStatus::Confirmed);

    Livewire::test(ManageReservations::class, ['record' => $fishery->getKey()])
        ->callAction(TestAction::make('cancelReservation')->table($reservation), data: ['refund' => 'terms', 'confirm_in_progress' => true])
        ->assertHasNoActionErrors();

    expect($reservation->fresh()->status)->toBe(ReservationStatus::Cancelled);
});

test('a refund other than the terms needs a reason and is recorded as an exception', function () {
    Mail::fake();
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    $reservation = ReservationFixtures::book($position, '2026-05-13');
    $this->actingAs($owner);

    Livewire::test(ManageReservations::class, ['record' => $fishery->getKey()])
        ->callAction(TestAction::make('cancelReservation')->table($reservation), data: ['refund' => 'custom', 'refund_percent' => 50])
        ->assertHasActionErrors(['refund_reason']);

    Livewire::test(ManageReservations::class, ['record' => $fishery->getKey()])
        ->callAction(TestAction::make('cancelReservation')->table($reservation), data: ['refund' => 'custom', 'refund_percent' => 50, 'refund_reason' => 'Gest'])
        ->assertHasNoActionErrors();

    expect($reservation->fresh()->terms->exceptionsOf(TermsExceptionKind::Refund)[0]['percent'])->toBe(50);
});

test('the edit modal changes the term through the booking service', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    $reservation = ReservationFixtures::book($position, '2026-05-13', 2);
    $this->actingAs($owner);

    Livewire::test(ViewReservation::class, ['record' => $reservation->getKey()])
        ->callAction('editReservation', data: [
            'starts_on' => '2026-05-20',
            'nights' => 3,
            'change_reason' => 'Prośba wędkarza',
        ])
        ->assertHasNoActionErrors();

    $fresh = $reservation->fresh();

    expect($fresh->first_night_on->toDateString())->toBe('2026-05-20')
        ->and($fresh->slots()->count())->toBe(3)
        ->and($fresh->terms->total_cents)->toBe(21000);
});

test('the details page shows the frozen terms and the history', function () {
    [, $position, $owner] = ReservationFixtures::bookableFishery();
    $reservation = ReservationFixtures::book($position, '2026-05-13', 2, ['agreedTotalCents' => 12000, 'agreedPriceReason' => 'Rabat XYZ']);
    $this->actingAs($owner);

    Livewire::test(ViewReservation::class, ['record' => $reservation->getKey()])
        ->assertSuccessful()
        ->assertSee('Jan Kowalski')
        ->assertSee('Rabat XYZ')
        ->assertSee(__('History'));
});

test('an owner of another fishery can neither open nor act on the reservation', function () {
    [, $position] = ReservationFixtures::bookableFishery();
    $reservation = ReservationFixtures::book($position, '2026-05-13');
    $stranger = User::factory()->create();
    OwnerRoleProvisioner::addOwnerRole($stranger);
    $this->actingAs($stranger);

    $this->get(ReservationResource::getUrl('view', ['record' => $reservation], panel: 'owner'))->assertNotFound();

    expect($stranger->can('update', $reservation))->toBeFalse()
        ->and($stranger->can('view', $reservation))->toBeFalse();

    $this->get(route(ManageReservations::getRouteName(), ['record' => $reservation->fishery_id]))->assertNotFound();
});

test('the admin sees reservations of every fishery', function () {
    [, $position] = ReservationFixtures::bookableFishery();
    $reservation = ReservationFixtures::book($position, '2026-05-13');
    $this->actingAs($this->createSuperAdmin());
    Filament::setCurrentPanel('admin');

    $this->get(ReservationResource::getUrl('view', ['record' => $reservation], panel: 'admin'))->assertOk();
});
