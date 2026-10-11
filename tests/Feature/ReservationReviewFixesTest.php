<?php

namespace Tests\Feature;

use App\Enums\AnglerLinkStatus;
use App\Enums\BookingProblem;
use App\Enums\CalendarWindow;
use App\Enums\CancellationReason;
use App\Enums\CollisionDecision;
use App\Enums\ConfigurationChangeKind;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Enums\ServiceScope;
use App\Enums\TermsExceptionKind;
use App\Filament\Resources\FisheryResource\Pages\ManageCalendar;
use App\Filament\Resources\PositionResource\Pages\EditPosition;
use App\Filament\Resources\ReservationResource\Pages\CreateReservation;
use App\Filament\Resources\ReservationResource\ReservationFormQuote;
use App\Filament\Resources\ReservationResource\ReservationFormSchema;
use App\Filament\Resources\ReservationResource\ReservationFormSubmission;
use App\Mail\ReservationCancelledMail;
use App\Mail\ReservationRequestMail;
use App\Models\AdditionalService;
use App\Models\AvailabilityBlock;
use App\Models\FisheryAngler;
use App\Models\Position;
use App\Services\AnglerDetails;
use App\Services\AnglerLinking;
use App\Services\CancellationRequest;
use App\Services\ConfigurationChange;
use App\Services\ConfigurationCollisionService;
use App\Services\ReservationBooking;
use App\Services\ReservationCalendar;
use App\Services\ReservationConfirmation;
use App\Services\SaleCalendar;
use App\Services\StayOffer;
use App\Services\StayPriceExplanation;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\ReservationFixtures;
use Tests\Support\StayFixtures;

/**
 * Poprawki modułu rezerwacji po przeglądzie serii 039–046 (zadanie 047).
 *
 * „Dziś" = środa 13.05.2026, 12:00 (Warszawa); doba 15:00 → 15:00, stawka 70 zł.
 */
beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-05-13 12:00', 'Europe/Warsaw'));
    Filament::setCurrentPanel('owner');
    Mail::fake();
});

afterEach(function () {
    Date::setTestNow();
});

function unpinnedService($fishery, array $state = []): AdditionalService
{
    return AdditionalService::factory()->create(array_merge([
        'fishery_id' => $fishery->id,
        'is_active' => true,
        'scope' => ServiceScope::SelectedPositions->value,
        'price' => '20.00',
        'available_count' => null,
        'name' => 'Łódka',
    ], $state));
}

// ─── B: formularze ────────────────────────────────────────────────────────────────

test('a service not offered on the position is an exception with a reason, never for the angler channel', function () {
    [$fishery, $position] = ReservationFixtures::bookableFishery();
    $boat = unpinnedService($fishery);

    $without = ReservationFixtures::attempt($position, '2026-05-20', 1, ['services' => [$boat->id => 1]]);
    $online = ReservationFixtures::attempt($position, '2026-05-20', 1, [
        'services' => [$boat->id => 1],
        'serviceExceptionReason' => 'Prośba',
        'source' => ReservationSource::Online,
    ]);
    $with = ReservationFixtures::attempt($position, '2026-05-20', 1, ['services' => [$boat->id => 1], 'serviceExceptionReason' => 'Stały klient']);

    $exception = $with->reservation?->terms->exceptionsOf(TermsExceptionKind::Service)[0] ?? [];

    expect($without->problem)->toBe(BookingProblem::ServiceExceptionReasonRequired)
        ->and($online->problem)->toBe(BookingProblem::ServiceUnavailable)
        ->and($with->succeeded)->toBeTrue()
        ->and($exception['service_id'])->toBe($boat->id)
        ->and($exception['reason'])->toBe('Stały klient')
        ->and($exception['causes'])->toContain(__('not offered on this position'))
        ->and($with->reservation->terms->services_total_cents)->toBe(2000);
});

test('an exhausted unit limit has no exception, even with a reason', function () {
    [$fishery, $position] = ReservationFixtures::bookableFishery();
    $second = Position::factory()->create(['fishery_id' => $fishery->id, 'status' => 'available']);
    $boat = ReservationFixtures::service($fishery, $position, ['available_count' => 1]);
    $second->additionalServices()->attach($boat->id, ['is_required' => false]);
    ReservationFixtures::book($position, '2026-05-20', 1, ['services' => [$boat->id => 1]]);

    $outcome = ReservationFixtures::attempt($second, '2026-05-20', 1, [
        'services' => [$boat->id => 1],
        'serviceExceptionReason' => 'Bardzo proszę',
        'angler' => new AnglerDetails('Anna', 'anna@example.com'),
    ]);

    expect($outcome->problem)->toBe(BookingProblem::ServiceLimitExceeded);
});

test('the sale rules reason field appears only when the offer is refused', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    StayFixtures::blockSale($fishery, $position, '2026-05-25', '2026-05-25');
    $this->actingAs($owner);

    Livewire::withQueryParams(['fishery' => $fishery->id])
        ->test(CreateReservation::class)
        ->fillForm(['position_id' => $position->id, 'starts_on' => '2026-05-20', 'nights' => 1, 'anglers' => 1])
        ->assertFormFieldIsHidden('exception_reason')
        ->assertSee(__('Total'))
        ->fillForm(['starts_on' => '2026-05-25'])
        ->assertFormFieldIsVisible('exception_reason')
        ->assertSee(__('Outside the sale rules'));
});

test('one price explanation serves the forms and the portal: stay lines, services and total', function () {
    [$fishery, $position] = ReservationFixtures::bookableFishery();
    $boat = ReservationFixtures::service($fishery, $position, ['name' => 'Łódka', 'price' => '30.00']);
    $offer = new StayOffer($position->fresh(['fishery']));

    $explanation = StayPriceExplanation::of(
        $offer->offer('2026-05-20', 2, 2)->breakdown,
        $offer->services('2026-05-20', 2, [$boat->id => 1]),
    );

    expect($explanation->stayLines)->toHaveCount(1)
        ->and($explanation->stayLines[0]['cents'])->toBe(28000)
        ->and($explanation->serviceLines[0]['label'])->toContain('Łódka')
        ->and($explanation->serviceLines[0]['cents'])->toBe(6000)
        ->and($explanation->totalInCents())->toBe(34000);
});

// ─── C: edycja ────────────────────────────────────────────────────────────────────

test('a contact-only change keeps the frozen terms untouched', function () {
    [, $position, $owner] = ReservationFixtures::bookableFishery();
    $reservation = ReservationFixtures::book($position, '2026-05-20', 2, ['internalNote' => 'VIP']);
    $reservation->terms->update(['total_cents' => 12345]);
    $this->actingAs($owner);

    $outcome = ReservationFormSubmission::update($reservation->fresh(), array_merge(ReservationFormSchema::quickState($reservation->fresh()), [
        'angler_phone' => '+48999',
        'internal_note' => '',
    ]));

    $fresh = $reservation->fresh();

    expect($outcome->succeeded)->toBeTrue()
        ->and($fresh->terms->total_cents)->toBe(12345)
        ->and($fresh->angler->given_phone)->toBe('+48999')
        ->and($fresh->internal_note)->toBeNull();
});

test('a rebooking keeps exceptions, the kept-at-block decision, the refund policy and the documents', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    $reservation = ReservationFixtures::book($position, '2026-05-20', 1, ['agreedTotalCents' => 5000, 'agreedPriceReason' => 'Rabat']);
    $block = StayFixtures::blockSale($fishery, $position, '2026-05-20', '2026-05-21');
    (new ReservationBooking)->recordException($reservation, TermsExceptionKind::SaleBlocked, 'Blokada: zostaw', $owner, ['block_id' => $block->id]);
    $reservation->terms->update(['refund_policy' => ['frozen' => true], 'documents' => ['regulations' => 999]]);
    $this->actingAs($owner);

    // Zmiana składu przelicza warunki, ale nie wymaga ponownych powodów.
    $outcome = ReservationFormSubmission::update($reservation->fresh(), array_merge(ReservationFormSchema::quickState($reservation->fresh()), [
        'anglers' => 2,
        'change_reason' => 'Dojechał kolega',
    ]));

    $terms = $reservation->fresh()->terms;

    expect($outcome->succeeded)->toBeTrue()
        ->and($terms->anglers)->toBe(2)
        ->and($terms->exceptionsOf(TermsExceptionKind::SaleBlocked)[0]['block_id'])->toBe($block->id)
        ->and($terms->exceptionsOf(TermsExceptionKind::AgreedPrice))->toHaveCount(1)
        ->and($terms->refund_policy)->toBe(['frozen' => true])
        ->and($terms->documents)->toBe(['regulations' => 999]);
});

test('an address taken by another angler of the fishery is a message, not an error', function () {
    [, $position, $owner] = ReservationFixtures::bookableFishery();
    $first = ReservationFixtures::book($position, '2026-05-20', 1);
    ReservationFixtures::book($position, '2026-05-22', 1, ['angler' => new AnglerDetails('Anna', 'anna@example.com')]);
    $this->actingAs($owner);

    $outcome = ReservationFormSubmission::update($first->fresh(), array_merge(ReservationFormSchema::quickState($first->fresh()), [
        'angler_email' => 'Anna@example.com',
    ]));

    expect($outcome->succeeded)->toBeFalse()
        ->and($outcome->problem)->toBe(BookingProblem::EmailTaken)
        ->and($first->angler->fresh()->given_email)->toBe('jan@example.com');
});

test('quick edit from the calendar replaces the old edit modal', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    $reservation = ReservationFixtures::book($position, '2026-05-14', 1);
    $this->actingAs($owner);

    Livewire::test(ManageCalendar::class, ['record' => $fishery->getKey()])
        ->callAction(TestAction::make('quickEditReservation')->arguments(['reservation' => $reservation->id]), data: [
            'nights' => 2,
            'change_reason' => 'Przedłużenie',
        ])
        ->assertHasNoActionErrors();

    expect($reservation->fresh()->nights)->toBe(2)
        ->and($reservation->fresh()->slots()->count())->toBe(2);
});

// ─── D: bezpieczeństwo ────────────────────────────────────────────────────────────

test('a swapped fishery id gives the reactive fields nothing from the other fishery', function () {
    [$fishery, , $owner] = ReservationFixtures::bookableFishery();
    [$foreign, $foreignPosition] = ReservationFixtures::bookableFishery();
    $foreignPosition->update(['name' => 'OBCE-STANOWISKO']);
    $foreignReservation = ReservationFixtures::book($foreignPosition, '2026-05-20', 1, ['angler' => new AnglerDetails('Obcy Wędkarz', 'obcy@example.com')]);
    $this->actingAs($owner);

    expect(ReservationFormSchema::verifiedFishery($foreign->id))->toBeNull()
        ->and(ReservationFormSubmission::findAngler(ReservationFormSchema::verifiedFishery($foreign->id), $foreignReservation->fishery_angler_id))->toBeNull();

    Livewire::withQueryParams(['fishery' => $fishery->id])
        ->test(CreateReservation::class)
        ->fillForm(['fishery_id' => $foreign->id, 'fishery_angler_id' => $foreignReservation->fishery_angler_id])
        ->assertDontSee('OBCE-STANOWISKO')
        ->assertDontSee('obcy@example.com');
});

test('changing the address of a pending angler invalidates the link sent to the old address', function () {
    [, $position, $owner] = ReservationFixtures::bookableFishery();
    $reservation = ReservationFixtures::book($position, '2026-05-20', 1, [
        'angler' => new AnglerDetails('Jan Kowalski', 'jan@exmaple.com'),
        'confirmBy' => CarbonImmutable::now()->addDays(2),
    ]);
    ReservationConfirmation::afterBooking($reservation);
    $url = null;
    Mail::assertSent(ReservationRequestMail::class, function (ReservationRequestMail $mail) use (&$url): bool {
        $url = $mail->confirmUrl;

        return true;
    });
    $this->actingAs($owner);

    ReservationFormSubmission::update($reservation->fresh(), array_merge(ReservationFormSchema::quickState($reservation->fresh()), [
        'angler_email' => 'jan@example.com',
    ]));

    $angler = $reservation->angler->fresh();

    expect($angler->link_token_hash)->toBeNull()
        ->and($angler->status)->toBe(AnglerLinkStatus::Unlinked);

    $this->post($url)->assertNotFound();
    expect($reservation->fresh()->status)->toBe(ReservationStatus::Pending);
});

test('the link token is bound to the address it was sent to and re-checked under the lock', function () {
    [, $position] = ReservationFixtures::bookableFishery();
    $reservation = ReservationFixtures::book($position, '2026-05-20', 1, ['confirmBy' => CarbonImmutable::now()->addDays(2)]);
    ReservationConfirmation::afterBooking($reservation);
    $token = null;
    Mail::assertSent(ReservationRequestMail::class, function (ReservationRequestMail $mail) use (&$token): bool {
        $token = basename($mail->confirmUrl);

        return true;
    });
    $linking = new AnglerLinking;
    $angler = $linking->findByLinkToken($token)['angler'];

    // „To nie ja" w międzyczasie — potwierdzenie ze starym tokenem nic nie robi.
    $linking->notMe($reservation->fresh());

    expect($linking->confirm($angler, 'profile', $token))->toBeNull()
        ->and($reservation->fresh()->status)->toBe(ReservationStatus::Pending)
        ->and($angler->fresh()->user_id)->toBeNull();

    // Adres zmieniony z pominięciem formularza — token wysłany na stary adres też nie działa.
    $other = ReservationFixtures::book($position, '2026-05-25', 1, [
        'angler' => new AnglerDetails('Ola', 'ola@example.com'),
        'confirmBy' => CarbonImmutable::now()->addDays(2),
    ]);
    ReservationConfirmation::afterBooking($other);
    $olaToken = null;
    Mail::assertSent(ReservationRequestMail::class, function (ReservationRequestMail $mail) use (&$olaToken): bool {
        $olaToken = basename($mail->confirmUrl);

        return $mail->hasTo('ola@example.com');
    });
    FisheryAngler::query()->whereKey($other->fishery_angler_id)->sole()->update(['given_email' => 'ktos@example.com']);

    expect($linking->findByLinkToken($olaToken)['angler'])->toBeNull();
});

// ─── E: wygasanie ─────────────────────────────────────────────────────────────────

test('the confirmation deadline never passes the start of the stay and a stay in progress does not expire', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery([
        'manual_reservation_confirmation_required' => true,
        'manual_reservation_hold_hours' => 48,
    ]);
    $this->actingAs($owner);

    $outcome = ReservationFormSubmission::book($fishery, [
        'position_id' => $position->id, 'starts_on' => '2026-05-13', 'nights' => 2, 'anglers' => 1,
        'angler_name' => 'Dziś', 'angler_phone' => '+48600',
    ]);

    expect($outcome->reservation->confirm_by->equalTo($outcome->reservation->starts_at))->toBeTrue();

    // Rezerwacja sprzed reguły — termin po początku pobytu: w trakcie pobytu nie wygasa.
    $legacy = ReservationFixtures::book($position, '2026-05-16', 2, [
        'angler' => new AnglerDetails('Stara', 'stara@example.com'),
        'confirmBy' => CarbonImmutable::parse('2026-05-17 12:00', 'Europe/Warsaw'),
    ]);

    expect((new ReservationBooking)->expire($legacy, CarbonImmutable::parse('2026-05-17 13:00', 'Europe/Warsaw')))->toBeFalse()
        ->and($legacy->fresh()->status)->toBe(ReservationStatus::Pending);
});

// ─── F: kolizje i stanowiska ──────────────────────────────────────────────────────

test('a position with upcoming reservations can not be deleted', function () {
    [, $position, $owner] = ReservationFixtures::bookableFishery();
    ReservationFixtures::book($position, '2026-05-20', 1);
    $this->actingAs($owner);

    Livewire::test(EditPosition::class, ['record' => $position->getKey()])
        ->callAction('delete')
        ->assertNotified(__('The position was not deleted'));

    expect($position->fresh()->trashed())->toBeFalse();
});

test('a reservation cancelled in the meantime is neither counted nor mailed twice', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    $reservation = ReservationFixtures::book($position, '2026-05-20', 1);
    $change = new ConfigurationChange(ConfigurationChangeKind::PositionWithdrawal, $fishery->id, [$position->id], payload: ['position_id' => $position->id]);

    $result = (new ConfigurationCollisionService)->apply(
        $change,
        [$reservation->id => CollisionDecision::Cancel->value],
        // Anulacja inną drogą między listą a decyzją (tu: wewnątrz zapisu zmiany).
        function () use ($reservation): ?int {
            (new ReservationBooking)->cancel($reservation, new CancellationRequest(reason: CancellationReason::Angler));

            return null;
        },
        $owner,
    );

    expect($result->cancelled)->toBe(0);
    Mail::assertNotSent(ReservationCancelledMail::class);
});

// ─── G: wydajność ─────────────────────────────────────────────────────────────────

test('calendars load reservation slots only from the window on, and none without occupancy', function () {
    [$fishery, $position] = ReservationFixtures::bookableFishery();
    ReservationFixtures::book($position, '2026-05-04', 1, ['exceptionReason' => 'test']);
    ReservationFixtures::book($position, '2026-05-14', 1, ['angler' => new AnglerDetails('B', 'b@example.com')]);

    $grid = (new SaleCalendar($fishery->fresh()))->grid(CarbonImmutable::parse('2026-05-11'), CalendarWindow::Week);
    $public = (new SaleCalendar($fishery->fresh()))->grid(CarbonImmutable::parse('2026-05-11'), CalendarWindow::Week, includeOccupancy: false);
    $reservations = (new ReservationCalendar($fishery->fresh()))->grid(CarbonImmutable::parse('2026-05-11'), 7);

    expect($grid->rows[0]->position->reservationSlots)->toHaveCount(1)
        ->and($public->rows[0]->position->relationLoaded('reservationSlots'))->toBeFalse()
        ->and($reservations->rows[0]->position->reservationSlots)->toHaveCount(1);
});

// ─── A: kalendarz ─────────────────────────────────────────────────────────────────

test('empty nights carry the offer and bars carry the frozen total', function () {
    [$fishery, $position] = ReservationFixtures::bookableFishery();
    $reservation = ReservationFixtures::book($position, '2026-05-14', 2);
    $model = $fishery->fresh();
    $offer = (new SaleCalendar($model))->grid(CarbonImmutable::parse('2026-05-11'), CalendarWindow::Week);

    $grid = (new ReservationCalendar($model))->grid(CarbonImmutable::parse('2026-05-11'), 7, offer: $offer);
    $cells = collect($grid->rows[0]->cells)->keyBy(fn ($cell) => $cell->day->toDateString());

    expect($cells['2026-05-17']->offer?->sellable)->toBeTrue()
        ->and($cells['2026-05-17']->offer?->totalInCents)->toBe(7000)
        ->and($grid->rows[0]->bars[0]->totalInCents())->toBe($reservation->terms->total_cents)
        ->and($grid->rows[0]->bars[0]->people())->toBe(1);
});

test('clicking the selected night again closes the popover', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    $this->actingAs($owner);

    Livewire::test(ManageCalendar::class, ['record' => $fishery->getKey()])
        ->call('selectCell', $position->id, '2026-05-14')
        ->assertSeeHtml('data-selection-popover')
        ->assertSeeHtml('data-popover-anchor')
        ->call('selectCell', $position->id, '2026-05-14')
        ->assertSet('selStart', null)
        ->assertDontSeeHtml('data-selection-popover');
});

test('a block is a bar with its own popover', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    $block = StayFixtures::blockSale($fishery, $position, '2026-05-15', '2026-05-15');
    $block->update(['reason' => 'Koszenie trawy']);
    $this->actingAs($owner);

    $grid = (new ReservationCalendar($fishery->fresh()))->grid(CarbonImmutable::parse('2026-05-11'), 7);

    expect($grid->rows[0]->blocks)->toHaveCount(1)
        ->and($grid->rows[0]->blocks[0]->column)->toBe(4)
        ->and($grid->rows[0]->blocks[0]->span)->toBe(2) // model połówek: doba 15.05, wyjazd rano 16.05
        ->and($grid->rows[0]->blocks[0]->nights())->toBe(1)
        ->and($grid->rows[0]->blocks[0]->startsBefore)->toBeFalse()
        ->and($grid->rows[0]->blocks[0]->endsAfter)->toBeFalse();

    Livewire::test(ManageCalendar::class, ['record' => $fishery->getKey()])
        ->assertSeeHtml('data-block-bar="'.$block->id.'"')
        ->call('selectBlock', $block->id)
        ->assertSeeHtml('data-block-popover')
        ->assertSee('Koszenie trawy')
        ->callAction(TestAction::make('deleteBlock')->arguments(['block' => $block->id]));

    expect(AvailabilityBlock::query()->count())->toBe(0);
});

test('counter chips filter by dimming the bars that do not match', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    $today = ReservationFixtures::book($position, '2026-05-13', 1);
    $later = ReservationFixtures::book($position, '2026-05-15', 1, ['angler' => new AnglerDetails('B', 'b@example.com')]);
    $this->actingAs($owner);

    $grid = (new ReservationCalendar($fishery->fresh()))->grid(CarbonImmutable::parse('2026-05-11'), 7, filter: 'arrivals');
    $bars = collect($grid->rows[0]->bars)->keyBy(fn ($bar) => $bar->reservation->id);

    expect($bars[$today->id]->dimmed)->toBeFalse()
        ->and($bars[$later->id]->dimmed)->toBeTrue();

    Livewire::test(ManageCalendar::class, ['record' => $fishery->getKey()])
        ->call('toggleFilter', 'arrivals')
        ->assertSet('statusFilter', 'arrivals')
        ->call('toggleFilter', 'arrivals')
        ->assertSet('statusFilter', null);
});

test('the quick block over a reservation goes to the decision screen instead of refusing', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    ReservationFixtures::book($position, '2026-05-15', 1);
    $this->actingAs($owner);

    Livewire::test(ManageCalendar::class, ['record' => $fishery->getKey()])
        ->callAction(TestAction::make('quickBlock')->arguments(['position' => $position->id, 'from' => '2026-05-15', 'to' => '2026-05-15']), data: ['reason' => 'Zawody'])
        ->assertRedirectContains('/collisions/');

    expect(AvailabilityBlock::query()->count())->toBe(0);
});

test('the phone sheet quote prices the stay without re-rendering', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    $this->actingAs($owner);

    $quote = Livewire::test(ManageCalendar::class, ['record' => $fishery->getKey()])
        ->instance()
        ->quote($position->id, '2026-05-14', 2);

    expect($quote['price'])->toContain('140')
        ->and($quote['refusal'])->toBeNull()
        ->and($quote['arrival'])->toBe('14.05 15:00');
});

// ─── H: drobne ────────────────────────────────────────────────────────────────────

test('storing a token does not write an empty history entry', function () {
    [, $position] = ReservationFixtures::bookableFishery();
    $reservation = ReservationFixtures::book($position, '2026-05-20', 1);
    $before = Activity::query()->count();

    $reservation->forceFill(['manage_token_hash' => hash('sha256', 'x')])->save();
    $reservation->angler->forceFill(['link_token_hash' => hash('sha256', 'y')])->save();

    expect(Activity::query()->count())->toBe($before);
});

test('a stay longer than the fishery maximum names the configured limit', function () {
    [$fishery, $position] = ReservationFixtures::bookableFishery(['max_nights' => 3]);
    app()->setLocale('pl');

    $quote = ReservationFormQuote::for($fishery->fresh(), [
        'position_id' => $position->id, 'starts_on' => '2026-05-20', 'nights' => 5, 'anglers' => 1, 'companions' => 0,
    ]);

    expect($quote->refused())->toBeTrue()
        ->and($quote->refusalLines)->toContain('Rezerwacja jest dłuższa niż 3 dni w ustawieniach maksymalnej długości rezerwacji.')
        ->and((string) $quote->html())->toContain('Nadal możesz zarezerwować — podaj powód; zostanie zapisany jako wyjątek.');
});
