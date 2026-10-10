<?php

namespace Tests\Feature;

use App\Enums\AnglerLinkStatus;
use App\Enums\ConfirmationSource;
use App\Enums\ReservationStatus;
use App\Filament\Resources\ReservationResource\Pages\CreateReservation;
use App\Mail\ReservationRequestMail;
use App\Models\FisheryAngler;
use App\Models\Reservation;
use App\Models\User;
use App\Services\AnglerDetails;
use App\Services\AnglerLinking;
use App\Services\PortalRoutes;
use App\Services\ReservationConfirmation;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\ReservationFixtures;

/**
 * Powiązanie wędkarza z kontem i potwierdzenie rezerwacji linkiem z maila (zadanie 043, ADR-024).
 */
beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-04-01 12:00', 'Europe/Warsaw'));
    Mail::fake();
});

afterEach(function () {
    Date::setTestNow();
});

/**
 * Rezerwacja z mailem — zwraca linki wyciągnięte z wysłanej wiadomości.
 *
 * @return array{0: Reservation, 1: string, 2: string}
 */
function bookedWithRequest(array $overrides = []): array
{
    [, $position] = ReservationFixtures::bookableFishery();
    $reservation = ReservationFixtures::book($position, '2026-05-13', 2, $overrides + ['confirmBy' => CarbonImmutable::now()->addDays(2)]);
    ReservationConfirmation::afterBooking($reservation);

    $sent = null;
    Mail::assertSent(ReservationRequestMail::class, function (ReservationRequestMail $mail) use (&$sent): bool {
        $sent = $mail;

        return true;
    });

    return [$reservation->fresh(), $sent->confirmUrl, $sent->manageUrl];
}

test('the request mail has both actions and the tokens are stored only as hashes with an expiry', function () {
    [$reservation, $confirmUrl, $manageUrl] = bookedWithRequest();
    $angler = $reservation->angler->fresh();
    $token = basename($confirmUrl);

    expect($confirmUrl)->toStartWith(rtrim(config('app.url'), '/').'/pl/rezerwacja/potwierdzenie/')
        ->and($manageUrl)->toContain('/pl/rezerwacja/rezygnacja/')
        ->and($angler->link_token_hash)->toBe(hash('sha256', $token))
        ->and($angler->link_token_hash)->not->toBe($token)
        ->and($angler->link_expires_at)->not->toBeNull()
        ->and($angler->status)->toBe(AnglerLinkStatus::Pending)
        ->and($reservation->getRawOriginal('manage_token_hash'))->toBe(hash('sha256', basename($manageUrl)));

    Mail::assertSent(ReservationRequestMail::class, fn (ReservationRequestMail $mail): bool => $mail->hasTo('jan@example.com')
        && str_contains($mail->render(), __('Confirm and link with my account'))
        && str_contains($mail->render(), __('Cancel / this is not me')));
});

test('a phone-only reservation stays unlinked and gets no mail', function () {
    [, $position] = ReservationFixtures::bookableFishery();
    $reservation = ReservationFixtures::book($position, '2026-05-13', 1, ['angler' => new AnglerDetails('Tel', null, '+48600')]);

    ReservationConfirmation::afterBooking($reservation);

    Mail::assertNothingSent();
    expect($reservation->angler->fresh()->status)->toBe(AnglerLinkStatus::Unlinked)
        ->and($reservation->fresh()->needsContact())->toBeFalse();
});

test('the operator sees the same message whether the address has an account or not', function () {
    Filament::setCurrentPanel('owner');
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    User::factory()->create(['email' => 'istnieje@example.com']);
    $this->actingAs($owner);
    $messages = [];

    foreach (['istnieje@example.com', 'nowy@example.com'] as $index => $email) {
        Livewire::withQueryParams(['fishery' => $fishery->id])
            ->test(CreateReservation::class)
            ->fillForm([
                'angler_name' => 'Jan', 'angler_email' => $email, 'position_id' => $position->id,
                'starts_on' => $index === 0 ? '2026-05-13' : '2026-05-20', 'nights' => 1, 'anglers' => 1, 'companions' => 0,
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified(__('The reservation was saved'));

        $messages[] = CreateReservation::requestNotice(Reservation::query()->latest('id')->first());
    }

    expect($messages[0])->toBe($messages[1])->not->toBeNull();
});

test('confirming by link creates a verified account without password, links the relation and confirms the reservation', function () {
    [$reservation, $confirmUrl] = bookedWithRequest();

    $this->get($confirmUrl)->assertOk()->assertSee(__('What the fishery will see'));
    $this->post($confirmUrl)->assertOk()->assertSee(__('Reservation confirmed'));

    $angler = $reservation->angler->fresh();
    $user = User::query()->where('email', 'jan@example.com')->sole();

    expect($angler->status)->toBe(AnglerLinkStatus::Linked)
        ->and($angler->user_id)->toBe($user->id)
        ->and($angler->link_token_hash)->toBeNull()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->has_password)->toBeFalse()
        ->and($user->hasRole('owner'))->toBeFalse()
        ->and($reservation->fresh()->status)->toBe(ReservationStatus::Confirmed)
        ->and($reservation->fresh()->confirmation_source)->toBe(ConfirmationSource::Link)
        ->and($reservation->fresh()->confirm_by)->toBeNull();

    // Token jest jednorazowy.
    $this->get($confirmUrl)->assertNotFound();
    $this->post($confirmUrl)->assertNotFound();
});

test('an existing account is linked, not duplicated', function () {
    $existing = User::factory()->create(['email' => 'jan@example.com', 'phone' => null]);
    [$reservation, $confirmUrl] = bookedWithRequest();

    $this->post($confirmUrl)->assertOk();

    expect(User::query()->where('email', 'jan@example.com')->count())->toBe(1)
        ->and($reservation->angler->fresh()->user_id)->toBe($existing->id)
        // Profil bez telefonu dostaje numer podany łowisku.
        ->and($existing->fresh()->phone)->toBe('+48500100200');
});

test('a phone conflict offers three choices and the override applies only to this fishery', function (string $choice, ?string $override, string $profilePhone) {
    $existing = User::factory()->create(['email' => 'jan@example.com', 'phone' => '+48111222333']);
    [$reservation, $confirmUrl] = bookedWithRequest();

    $this->get($confirmUrl)->assertSee(__('Which phone number should the fishery use?'));
    $this->post($confirmUrl, ['phone' => $choice])->assertOk();

    $angler = $reservation->angler->fresh();

    expect($angler->phone_override)->toBe($override)
        ->and($existing->fresh()->phone)->toBe($profilePhone)
        ->and($angler->effectiveContact()['phone'])->toBe($override ?? $profilePhone);
})->with([
    'from profile' => ['profile', null, '+48111222333'],
    'new and save' => ['save', null, '+48500100200'],
    'new for this fishery' => ['fishery', '+48500100200', '+48111222333'],
]);

test('resigning cancels the reservation and frees the slots', function () {
    [$reservation, , $manageUrl] = bookedWithRequest();

    $this->get($manageUrl)->assertOk()->assertSee(__('Cancel the reservation?'));
    $this->post($manageUrl, ['action' => 'resign', 'reason' => 'Zmiana planów'])->assertOk()->assertSee(__('Reservation cancelled'));

    $fresh = $reservation->fresh();

    expect($fresh->status)->toBe(ReservationStatus::Cancelled)
        ->and($fresh->cancel_reason?->value)->toBe('angler')
        ->and($fresh->cancel_message)->toBe('Zmiana planów')
        ->and($fresh->slots()->count())->toBe(0);

    $this->get($manageUrl)->assertNotFound();
});

test('"this is not me" withdraws the request and marks the reservation for the operator without cancelling it', function () {
    [$reservation, $confirmUrl, $manageUrl] = bookedWithRequest();

    $this->post($manageUrl, ['action' => 'not_me'])->assertOk();

    $fresh = $reservation->fresh();

    expect($fresh->status)->toBe(ReservationStatus::Pending)
        ->and($fresh->needsContact())->toBeTrue()
        ->and($fresh->angler->status)->toBe(AnglerLinkStatus::Unlinked)
        ->and($fresh->slots()->count())->toBe(2);

    $this->get($confirmUrl)->assertNotFound();
});

test('an expired link shows a dedicated message', function () {
    [, $confirmUrl] = bookedWithRequest();
    Date::setTestNow(CarbonImmutable::now()->addDays(AnglerLinking::VALID_DAYS + 1));

    $this->get($confirmUrl)->assertStatus(410)->assertSee(__('This link has expired'));
});

test('a GET does not change anything — only the form submission confirms', function () {
    [$reservation, $confirmUrl] = bookedWithRequest();

    $this->get($confirmUrl)->assertOk();

    expect($reservation->fresh()->status)->toBe(ReservationStatus::Pending)
        ->and(User::query()->where('email', 'jan@example.com')->exists())->toBeFalse();
});

test('token routes are rate limited', function () {
    $url = PortalRoutes::reservationUrl('confirm', str_repeat('a', 48), 'pl');

    for ($attempt = 0; $attempt < 10; $attempt++) {
        $this->get($url)->assertNotFound();
    }

    $this->get($url)->assertStatus(429);
});

test('too many pending requests for one address stop the mail and mark the reservation to contact', function () {
    foreach (range(1, AnglerLinking::MAX_PENDING_PER_EMAIL) as $i) {
        [, $position] = ReservationFixtures::bookableFishery();
        ReservationConfirmation::afterBooking(ReservationFixtures::book($position, '2026-05-13'));
    }

    [, $position] = ReservationFixtures::bookableFishery();
    $limited = ReservationFixtures::book($position, '2026-05-13');
    ReservationConfirmation::afterBooking($limited);

    Mail::assertSentCount(AnglerLinking::MAX_PENDING_PER_EMAIL);
    expect($limited->fresh()->needsContact())->toBeTrue();
});

test('a fishery can not send more requests per day than the limit', function () {
    [$fishery, $position] = ReservationFixtures::bookableFishery();
    RateLimiter::clear("angler-link-requests:fishery:{$fishery->id}");

    for ($i = 0; $i < AnglerLinking::MAX_SENDS_PER_FISHERY_PER_DAY; $i++) {
        RateLimiter::hit("angler-link-requests:fishery:{$fishery->id}", 86400);
    }

    $reservation = ReservationFixtures::book($position, '2026-05-13');
    ReservationConfirmation::afterBooking($reservation);

    Mail::assertNothingSent();
    expect($reservation->fresh()->needsContact())->toBeTrue();
});

test('link events are logged in the reservations channel', function () {
    [$reservation, $confirmUrl] = bookedWithRequest();
    $this->post($confirmUrl);

    $events = Activity::query()
        ->where('log_name', 'reservations')
        ->where('subject_type', FisheryAngler::class)
        ->pluck('event')
        ->all();

    expect($events)->toContain('link_requested', 'linked');
});
