<?php

namespace Tests\Feature;

use App\Actions\CleanActivityLogKeepingRetained;
use App\Enums\CancellationReason;
use App\Models\Reservation;
use App\Models\ReservationTerms;
use App\Services\CancellationRequest;
use App\Services\ReservationBooking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\ReservationFixtures;

/**
 * Dziennik zmian rezerwacji — osobny kanał `reservations`, wyłączony z czyszczenia (ADR-026 pkt 3–4).
 */
beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-04-01 12:00', 'Europe/Warsaw'));
});

afterEach(function () {
    Date::setTestNow();
});

test('creation, a change of terms and cancellation land in the reservations channel', function () {
    [, $position, $owner] = ReservationFixtures::bookableFishery();
    $this->actingAs($owner);
    $reservation = ReservationFixtures::book($position, '2026-05-13', 2);
    $booking = new ReservationBooking;

    $booking->rebook($reservation, ReservationFixtures::request($position, '2026-05-14', 2, ['angler' => $reservation->angler]), 'Prośba wędkarza');
    $booking->cancel($reservation, new CancellationRequest(CancellationReason::FisheryDecision, $owner, 'Komunikat'));

    $entries = Activity::query()->where('log_name', Reservation::LOG_NAME)->where('subject_type', Reservation::class)
        ->where('subject_id', $reservation->id)->orderBy('id')->get();

    $change = $entries->firstWhere('event', 'terms_changed');
    $cancel = $entries->last();

    expect($entries->pluck('event')->all())->toContain('created', 'terms_changed', 'updated')
        ->and($change->properties['reason'])->toBe('Prośba wędkarza')
        ->and($change->attribute_changes['old']['first_night_on'])->not->toBe($change->attribute_changes['attributes']['first_night_on'])
        ->and($cancel->attribute_changes['attributes']['status'])->toBe('cancelled')
        ->and($cancel->attribute_changes['old']['status'])->toBe('confirmed')
        ->and($cancel->causer_id)->toBe($owner->id);

    // Warunki też są w kanale — z wartościami sprzed i po zmianie.
    expect(Activity::query()->where('log_name', Reservation::LOG_NAME)->where('subject_type', ReservationTerms::class)->count())
        ->toBeGreaterThanOrEqual(2);
});

test('cleaning the activity log keeps the reservations channel', function () {
    [, $position] = ReservationFixtures::bookableFishery();
    ReservationFixtures::book($position, '2026-05-13');
    DB::table('activity_log')->update(['created_at' => '2020-01-01 00:00:00']);

    Artisan::call('activitylog:clean', ['--force' => true]);
    Artisan::call('activitylog:clean', ['log' => Reservation::LOG_NAME, '--force' => true]);

    expect(config('activitylog.actions.clean_log'))->toBe(CleanActivityLogKeepingRetained::class)
        ->and(Activity::query()->where('log_name', Reservation::LOG_NAME)->count())->toBeGreaterThan(0)
        ->and(Activity::query()->where('log_name', '!=', Reservation::LOG_NAME)->count())->toBe(0);
});
