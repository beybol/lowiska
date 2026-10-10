<?php

namespace Tests\Feature;

use App\Enums\ConfirmationSource;
use App\Enums\ReservationStatus;
use App\Filament\Resources\FisheryResource\Pages\ManageSaleSettings;
use App\Filament\Resources\ReservationResource\Pages\CreateReservation;
use App\Mail\ReservationExpiredMail;
use App\Models\Reservation;
use App\Services\AnglerDetails;
use App\Services\ReservationBooking;
use App\Services\ReservationConfirmation;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\ReservationFixtures;

/**
 * Ustawienia rezerwacji łowiska i wygasanie niepotwierdzonych (zadanie 045, ADR-024 pkt 7).
 */
beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-04-01 12:00', 'Europe/Warsaw'));
    Filament::setCurrentPanel('owner');
    Mail::fake();
});

afterEach(function () {
    Date::setTestNow();
});

test('confirmation is off by default and the settings save with validation', function () {
    [$fishery, , $owner] = ReservationFixtures::bookableFishery();
    $this->actingAs($owner);

    expect($fishery->fresh()->manual_reservation_confirmation_required)->toBeFalse()
        ->and($fishery->fresh()->public_availability_enabled)->toBeFalse();

    Livewire::test(ManageSaleSettings::class, ['record' => $fishery->getKey()])
        ->fillForm(['manual_reservation_confirmation_required' => true, 'manual_reservation_hold_hours' => null])
        ->call('save')
        ->assertHasFormErrors(['manual_reservation_hold_hours' => 'required']);

    Livewire::test(ManageSaleSettings::class, ['record' => $fishery->getKey()])
        ->fillForm(['manual_reservation_confirmation_required' => true, 'manual_reservation_hold_hours' => 9999])
        ->call('save')
        ->assertHasFormErrors(['manual_reservation_hold_hours']);

    Livewire::test(ManageSaleSettings::class, ['record' => $fishery->getKey()])
        ->fillForm(['manual_reservation_confirmation_required' => true, 'manual_reservation_hold_hours' => 24, 'public_availability_enabled' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $fishery->fresh();

    expect($fresh->manual_reservation_confirmation_required)->toBeTrue()
        ->and($fresh->manual_reservation_hold_hours)->toBe(24)
        ->and($fresh->public_availability_enabled)->toBeTrue();
});

test('a manual reservation gets confirm_by only when the fishery requires confirmation', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    $this->actingAs($owner);
    $form = fn (string $from) => [
        'angler_name' => 'Jan', 'angler_phone' => '+48600', 'position_id' => $position->id,
        'starts_on' => $from, 'nights' => 1, 'anglers' => 1, 'companions' => 0,
    ];

    Livewire::withQueryParams(['fishery' => $fishery->id])->test(CreateReservation::class)
        ->fillForm($form('2026-05-13'))->call('create')->assertHasNoFormErrors();

    $fishery->update(['manual_reservation_confirmation_required' => true, 'manual_reservation_hold_hours' => 24]);

    Livewire::withQueryParams(['fishery' => $fishery->id])->test(CreateReservation::class)
        ->fillForm($form('2026-05-20'))->call('create')->assertHasNoFormErrors();

    [$first, $second] = Reservation::query()->orderBy('id')->get()->all();

    expect($first->confirm_by)->toBeNull()
        ->and($first->status)->toBe(ReservationStatus::Confirmed)
        ->and($second->status)->toBe(ReservationStatus::Pending)
        ->and($second->confirm_by?->toIso8601String())->toBe(CarbonImmutable::now()->addHours(24)->utc()->toIso8601String());
});

test('changing the setting does not rewrite deadlines of existing reservations', function () {
    [$fishery, $position] = ReservationFixtures::bookableFishery(['manual_reservation_confirmation_required' => true, 'manual_reservation_hold_hours' => 48]);
    $reservation = ReservationFixtures::book($position, '2026-05-13', 1, ['confirmBy' => ReservationConfirmation::deadlineFor($fishery)]);
    $before = $reservation->confirm_by;

    $fishery->update(['manual_reservation_hold_hours' => 2]);

    expect($reservation->fresh()->confirm_by->equalTo($before))->toBeTrue();
});

test('the command expires past-deadline reservations, frees the slots, mails the angler and is idempotent', function () {
    [, $position] = ReservationFixtures::bookableFishery();
    $due = ReservationFixtures::book($position, '2026-05-13', 2, ['confirmBy' => CarbonImmutable::now()->addHour()]);
    $notYet = ReservationFixtures::book($position, '2026-05-20', 1, ['angler' => new AnglerDetails('B', 'b@example.com'), 'confirmBy' => CarbonImmutable::now()->addDays(3)]);
    $confirmed = ReservationFixtures::book($position, '2026-05-25', 1, ['angler' => new AnglerDetails('C', 'c@example.com'), 'confirmBy' => CarbonImmutable::now()->addHour()]);
    (new ReservationBooking)->confirm($confirmed, ConfirmationSource::Operator);

    Date::setTestNow(CarbonImmutable::now()->addHours(2));

    Artisan::call('reservations:expire');

    expect($due->fresh()->status)->toBe(ReservationStatus::Expired)
        ->and($due->fresh()->slots()->count())->toBe(0)
        ->and($notYet->fresh()->status)->toBe(ReservationStatus::Pending)
        ->and($confirmed->fresh()->status)->toBe(ReservationStatus::Confirmed);

    Mail::assertSent(ReservationExpiredMail::class, fn ($mail): bool => $mail->hasTo('jan@example.com'));
    Mail::assertSentCount(1);

    Artisan::call('reservations:expire');

    Mail::assertSentCount(1);
    expect(Artisan::output())->toContain('Expired reservations: 0.');
});

test('a phone-only reservation expires without a mail and without landing on the contact list', function () {
    [, $position] = ReservationFixtures::bookableFishery();
    $reservation = ReservationFixtures::book($position, '2026-05-13', 1, ['angler' => new AnglerDetails('Tel', null, '+48600'), 'confirmBy' => CarbonImmutable::now()->addHour()]);
    Date::setTestNow(CarbonImmutable::now()->addHours(2));

    Artisan::call('reservations:expire');

    Mail::assertNothingSent();
    expect($reservation->fresh()->status)->toBe(ReservationStatus::Expired)
        ->and($reservation->fresh()->needsContact())->toBeFalse();
});

test('the command is scheduled every five minutes', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'reservations:expire'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *');
});
