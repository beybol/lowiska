<?php

namespace Tests\Feature;

use App\Enums\BookingProblem;
use App\Enums\CancellationReason;
use App\Enums\ConfirmationSource;
use App\Enums\PaymentStatus;
use App\Enums\PositionStatus;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Enums\SaleUnavailabilityReason;
use App\Enums\TermsExceptionKind;
use App\Models\FisheryAngler;
use App\Models\PriceRule;
use App\Models\Reservation;
use App\Models\ReservationSlot;
use App\Services\AnglerDetails;
use App\Services\CancellationRequest;
use App\Services\PositionAvailability;
use App\Services\ReservationBooking;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReservationFixtures;
use Tests\Support\StayFixtures;

/**
 * Rdzeń rezerwacji — jedna ścieżka zapisu z blokadą (zadanie 039, ADR-024…027).
 *
 * Kalendarz odniesienia (2026): doba 15:00 → 15:00 (Warszawa, CEST = UTC+2), sezon cały rok,
 * „dziś" = 01.04.2026, więc wszystkie terminy maja są przyszłe.
 */
beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-04-01 12:00', 'Europe/Warsaw'));
});

afterEach(function () {
    Date::setTestNow();
});

test('a booking writes the reservation, one UTC slot per night and the frozen terms', function () {
    [, $position] = ReservationFixtures::bookableFishery();

    $reservation = ReservationFixtures::book($position, '2026-05-13', 2);

    expect($reservation->status)->toBe(ReservationStatus::Confirmed)
        ->and($reservation->confirmation_source)->toBe(ConfirmationSource::Operator)
        ->and($reservation->source)->toBe(ReservationSource::Operator)
        ->and($reservation->nights)->toBe(2)
        ->and($reservation->first_night_on->toDateString())->toBe('2026-05-13');

    $slots = $reservation->slots()->orderBy('starts_at')->get();

    // 15:00 czasu warszawskiego w maju to 13:00 UTC — w bazie leży UTC.
    expect($slots)->toHaveCount(2)
        ->and(DB::table('reservation_slots')->orderBy('starts_at')->value('starts_at'))->toBe('2026-05-13 13:00:00')
        ->and($slots[0]->starts_at->toIso8601String())->toBe('2026-05-13T13:00:00+00:00')
        ->and($slots[1]->ends_at->toIso8601String())->toBe('2026-05-15T13:00:00+00:00');

    $terms = $reservation->terms;

    expect($terms->total_cents)->toBe(14000)
        ->and($terms->computed_total_cents)->toBe(14000)
        ->and($terms->anglers)->toBe(1)
        ->and($terms->price_breakdown['nights'])->toHaveCount(2)
        ->and($terms->contact_snapshot['email'])->toBe('jan@example.com')
        ->and($terms->position_snapshot['name'])->toBe($position->name)
        ->and($terms->exceptions)->toBe([]);
});

test('the second booking of the same term is refused as occupied and writes nothing', function () {
    [, $position] = ReservationFixtures::bookableFishery();
    ReservationFixtures::book($position, '2026-05-13', 2);

    $second = ReservationFixtures::attempt($position, '2026-05-13', 2, [
        'angler' => new AnglerDetails('Anna Nowak', 'anna@example.com'),
    ]);

    expect($second->succeeded)->toBeFalse()
        ->and($second->reason)->toBe(SaleUnavailabilityReason::Occupied)
        ->and($second->night?->toDateString())->toBe('2026-05-13')
        ->and(Reservation::query()->count())->toBe(1)
        ->and(ReservationSlot::query()->count())->toBe(2)
        // Wycofana transakcja nie zostawia też relacji wędkarza.
        ->and(FisheryAngler::query()->where('given_email', 'anna@example.com')->exists())->toBeFalse();
});

test('partial overlap is refused while adjacent stays are allowed', function () {
    [, $position] = ReservationFixtures::bookableFishery();
    ReservationFixtures::book($position, '2026-05-13', 2); // 13 → 15

    $overlap = ReservationFixtures::attempt($position, '2026-05-14', 2); // 14 → 16
    $adjacentAfter = ReservationFixtures::attempt($position, '2026-05-15', 2); // 15 → 17
    $adjacentBefore = ReservationFixtures::attempt($position, '2026-05-11', 2); // 11 → 13

    expect($overlap->reason)->toBe(SaleUnavailabilityReason::Occupied)
        ->and($overlap->night?->toDateString())->toBe('2026-05-14')
        ->and($adjacentAfter->succeeded)->toBeTrue()
        ->and($adjacentBefore->succeeded)->toBeTrue();
});

test('the write locks the position row before it reads availability', function () {
    [, $position] = ReservationFixtures::bookableFishery();
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    ReservationFixtures::book($position, '2026-05-13');

    $lockIndex = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'from `positions`') && str_contains($sql, 'for update'));
    $slotReadIndex = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'from `reservation_slots`'));
    $slotWriteIndex = collect($queries)->search(fn (string $sql): bool => str_starts_with($sql, 'insert into `reservation_slots`'));

    expect($lockIndex)->not->toBeFalse()
        ->and($lockIndex)->toBeLessThan($slotReadIndex)
        ->and($slotReadIndex)->toBeLessThan($slotWriteIndex);
});

/**
 * ⚠️ Siatka bezpieczeństwa z bazy: dwa sloty tego samego stanowiska z tym samym początkiem nie
 * wejdą nawet z pominięciem serwisu (ADR-025 pkt 3).
 */
test('the database refuses a second slot starting at the same moment', function () {
    [, $position] = ReservationFixtures::bookableFishery();
    $reservation = ReservationFixtures::book($position, '2026-05-13');

    expect(fn () => DB::table('reservation_slots')->insert([
        'reservation_id' => $reservation->id,
        'position_id' => $position->id,
        'slot_type' => 'fishing_day',
        'starts_at' => '2026-05-13 13:00:00',
        'ends_at' => '2026-05-14 13:00:00',
    ]))->toThrow(QueryException::class);
});

test('availability refuses an occupied night as the last reason in the chain', function () {
    [$fishery, $position] = ReservationFixtures::bookableFishery();
    ReservationFixtures::book($position, '2026-05-13');

    $availability = new PositionAvailability($position->fresh());

    expect($availability->availability('2026-05-13')->reason)->toBe(SaleUnavailabilityReason::Occupied)
        ->and($availability->isSellable('2026-05-14'))->toBeTrue()
        ->and($availability->isSellable('2026-05-12'))->toBeTrue();

    // Blokada jest przyczyną trwalszą niż zajętość — wygrywa w łańcuchu, ale zajętość nadal widać wprost.
    StayFixtures::blockSale($fishery, $position, '2026-05-13', '2026-05-13');
    $blocked = new PositionAvailability($position->fresh());

    expect($blocked->availability('2026-05-13')->reason)->toBe(SaleUnavailabilityReason::SaleBlocked)
        ->and($blocked->isOccupied('2026-05-13'))->toBeTrue();
});

test('later price and refund policy changes do not touch existing terms', function () {
    [$fishery, $position] = ReservationFixtures::bookableFishery([
        'refund_policy' => [['days' => 7, 'percent' => 100]],
    ]);
    $reservation = ReservationFixtures::book($position, '2026-05-13', 2);
    $before = $reservation->terms->fresh()->toArray();

    PriceRule::query()->where('fishery_id', $fishery->id)->update(['amount' => 99.00]);
    $fishery->update(['refund_policy' => [['days' => 1, 'percent' => 10]]]);
    $position->update(['name' => 'Inna nazwa']);

    $after = $reservation->terms->fresh();

    expect($after->toArray())->toBe($before)
        ->and($after->refund_policy)->toBe([['days' => 7, 'percent' => 100]])
        ->and($after->total_cents)->toBe(14000);
});

test('an operator books a blocked position only with a reason, recorded as an exception', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    StayFixtures::blockSale($fishery, $position, '2026-05-13', '2026-05-13');

    $withoutReason = ReservationFixtures::attempt($position, '2026-05-13');

    expect($withoutReason->succeeded)->toBeFalse()
        ->and($withoutReason->problem)->toBe(BookingProblem::ExceptionReasonRequired)
        ->and($withoutReason->reason)->toBe(SaleUnavailabilityReason::SaleBlocked);

    $booked = ReservationFixtures::book($position, '2026-05-13', 1, ['exceptionReason' => 'Zawody']);
    $exception = $booked->terms->exceptionsOf(TermsExceptionKind::SaleBlocked)[0];

    expect($exception['reason'])->toBe('Zawody')
        ->and($exception['by'])->toBe($owner->id)
        ->and($exception['at'])->not->toBeNull()
        ->and($exception['rule'])->toBe('sale_blocked')
        // Cena wyliczona mimo odmowy sprzedaży.
        ->and($booked->terms->total_cents)->toBe(7000);
});

test('the angler channel never bypasses a block, even with a reason', function () {
    [$fishery, $position] = ReservationFixtures::bookableFishery();
    StayFixtures::blockSale($fishery, $position, '2026-05-13', '2026-05-13');

    $outcome = ReservationFixtures::attempt($position, '2026-05-13', 1, [
        'source' => ReservationSource::Online,
        'exceptionReason' => 'Proszę',
    ]);

    expect($outcome->succeeded)->toBeFalse()
        ->and($outcome->reason)->toBe(SaleUnavailabilityReason::SaleBlocked)
        ->and($outcome->problem)->toBeNull();
});

test('no exception bypasses an occupied slot, also on a blocked position', function () {
    [$fishery, $position] = ReservationFixtures::bookableFishery();
    ReservationFixtures::book($position, '2026-05-13');
    StayFixtures::blockSale($fishery, $position, '2026-05-13', '2026-05-13');

    $outcome = ReservationFixtures::attempt($position, '2026-05-13', 1, ['exceptionReason' => 'Zawody']);

    expect($outcome->reason)->toBe(SaleUnavailabilityReason::Occupied);
});

test('booking against stay rules is an exception with the refused rule', function () {
    [, $position] = ReservationFixtures::bookableFishery(['min_nights' => 3]);

    $refused = ReservationFixtures::attempt($position, '2026-05-13');
    $booked = ReservationFixtures::book($position, '2026-05-13', 1, ['exceptionReason' => 'Stały klient']);

    expect($refused->reason)->toBe(SaleUnavailabilityReason::StayTooShort)
        ->and($booked->terms->exceptionsOf(TermsExceptionKind::SaleRule)[0]['rule'])->toBe('stay_too_short');
});

test('an agreed price needs a reason and keeps the computed price next to it', function () {
    [, $position] = ReservationFixtures::bookableFishery();

    $withoutReason = ReservationFixtures::attempt($position, '2026-05-13', 2, ['agreedTotalCents' => 10000]);
    $booked = ReservationFixtures::book($position, '2026-05-13', 2, [
        'agreedTotalCents' => 10000,
        'agreedPriceReason' => 'Rabat stałego klienta',
    ]);

    expect($withoutReason->problem)->toBe(BookingProblem::AgreedPriceReasonRequired)
        ->and($booked->terms->total_cents)->toBe(10000)
        ->and($booked->terms->computed_total_cents)->toBe(14000)
        ->and($booked->terms->agreed_total_cents)->toBe(10000)
        ->and($booked->terms->exceptionsOf(TermsExceptionKind::AgreedPrice)[0]['computed_total_cents'])->toBe(14000);
});

test('the angler channel can not set an agreed price', function () {
    [, $position] = ReservationFixtures::bookableFishery();

    $outcome = ReservationFixtures::attempt($position, '2026-05-13', 1, [
        'source' => ReservationSource::Online,
        'agreedTotalCents' => 100,
        'agreedPriceReason' => 'Tanio',
    ]);

    expect($outcome->problem)->toBe(BookingProblem::AgreedPriceReasonRequired);
});

test('capacity is validated on the write path', function () {
    [, $position] = ReservationFixtures::bookableFishery();
    $position->update(['max_anglers' => 2, 'max_people' => 3]);

    expect(ReservationFixtures::attempt($position, '2026-05-13', 1, ['anglers' => 3])->problem)
        ->toBe(BookingProblem::CapacityExceeded)
        ->and(ReservationFixtures::attempt($position, '2026-05-13', 1, ['anglers' => 2, 'companions' => 2])->problem)
        ->toBe(BookingProblem::CapacityExceeded)
        ->and(ReservationFixtures::attempt($position, '2026-05-13', 1, ['anglers' => 2, 'companions' => 1])->succeeded)
        ->toBeTrue();
});

test('marking a pending reservation as paid confirms it', function () {
    [, $position] = ReservationFixtures::bookableFishery();
    $pending = ReservationFixtures::book($position, '2026-05-13', 1, ['confirmBy' => CarbonImmutable::now()->addDays(2)]);
    $confirmed = ReservationFixtures::book($position, '2026-05-20');
    $confirmedAt = $confirmed->confirmed_at;

    expect($pending->status)->toBe(ReservationStatus::Pending);

    // Inny status płatności niż „opłacona" nie potwierdza.
    (new ReservationBooking)->setPaymentStatus($pending, PaymentStatus::Unpaid);
    expect($pending->fresh()->status)->toBe(ReservationStatus::Pending);

    expect((new ReservationBooking)->setPaymentStatus($pending, PaymentStatus::Paid)->succeeded)->toBeTrue();

    $fresh = $pending->fresh();

    expect($fresh->status)->toBe(ReservationStatus::Confirmed)
        ->and($fresh->payment_status)->toBe(PaymentStatus::Paid)
        ->and($fresh->confirmation_source)->toBe(ConfirmationSource::Payment)
        ->and($fresh->confirmed_at)->not->toBeNull()
        ->and($fresh->confirm_by)->toBeNull();

    // Już potwierdzona zachowuje swoje źródło i chwilę potwierdzenia.
    (new ReservationBooking)->setPaymentStatus($confirmed, PaymentStatus::Paid);

    expect($confirmed->fresh()->confirmation_source)->toBe(ConfirmationSource::Operator)
        ->and($confirmed->fresh()->confirmed_at?->equalTo($confirmedAt))->toBeTrue();
});

test('cancellation frees the slots and keeps the reservation and terms', function () {
    [, $position, $owner] = ReservationFixtures::bookableFishery();
    $reservation = ReservationFixtures::book($position, '2026-05-13', 2);
    $reservation->update(['payment_status' => PaymentStatus::Paid]);

    $outcome = (new ReservationBooking)->cancel($reservation, new CancellationRequest(
        reason: CancellationReason::FisheryDecision,
        actor: $owner,
        message: 'Awaria pomostu',
    ));

    $fresh = $reservation->fresh();

    expect($outcome->succeeded)->toBeTrue()
        ->and($fresh->status)->toBe(ReservationStatus::Cancelled)
        ->and($fresh->cancelled_by)->toBe($owner->id)
        ->and($fresh->cancel_message)->toBe('Awaria pomostu')
        // Anulacja przez łowisko — pełny zwrot, w etapie 3 jako status.
        ->and($fresh->payment_status)->toBe(PaymentStatus::Refund)
        ->and($fresh->slots()->count())->toBe(0)
        ->and($fresh->terms)->not->toBeNull()
        // Termin zostaje w historii mimo usunięcia slotów.
        ->and($fresh->first_night_on->toDateString())->toBe('2026-05-13');

    // Zwolniony termin da się zarezerwować ponownie.
    expect(ReservationFixtures::attempt($position, '2026-05-13', 2)->succeeded)->toBeTrue();
});

test('an angler cancellation refunds by the frozen policy and an override needs a reason', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery([
        'refund_policy' => [['days' => 30, 'percent' => 0]],
    ]);
    $reservation = ReservationFixtures::book($position, '2026-05-13');
    $reservation->update(['payment_status' => PaymentStatus::Paid]);
    $second = ReservationFixtures::book($position, '2026-05-20');
    $second->update(['payment_status' => PaymentStatus::Paid]);
    // Bieżąca polityka zmieniona po rezerwacji — liczy się ta z warunków.
    $fishery->update(['refund_policy' => [['days' => 1, 'percent' => 100]]]);

    (new ReservationBooking)->cancel($reservation, new CancellationRequest(CancellationReason::Angler, $owner));

    expect($reservation->fresh()->payment_status)->toBe(PaymentStatus::Paid);

    expect(fn () => (new ReservationBooking)->cancel($second, new CancellationRequest(CancellationReason::Angler, $owner, refundPercent: 100)))
        ->toThrow(\InvalidArgumentException::class);

    (new ReservationBooking)->cancel($second, new CancellationRequest(
        CancellationReason::Angler, $owner, refundPercent: 100, refundReason: 'Choroba',
    ));

    $exception = $second->fresh()->terms->exceptionsOf(TermsExceptionKind::Refund)[0];

    expect($second->fresh()->payment_status)->toBe(PaymentStatus::Refund)
        ->and($exception['percent'])->toBe(100)
        ->and($exception['policy_percent'])->toBe(0)
        ->and($exception['reason'])->toBe('Choroba');
});

test('a cancelled reservation can not be cancelled or confirmed again', function () {
    [, $position, $owner] = ReservationFixtures::bookableFishery();
    $reservation = ReservationFixtures::book($position, '2026-05-13');
    $booking = new ReservationBooking;
    $booking->cancel($reservation, new CancellationRequest(CancellationReason::FisheryDecision, $owner));

    expect($booking->cancel($reservation, new CancellationRequest(CancellationReason::FisheryDecision, $owner))->problem)
        ->toBe(BookingProblem::NotActive)
        ->and($booking->confirm($reservation, ConfirmationSource::Operator)->problem)->toBe(BookingProblem::NotActive);
});

test('an unconfirmed reservation expires after its deadline and frees the slots, exactly once', function () {
    [, $position] = ReservationFixtures::bookableFishery();
    $reservation = ReservationFixtures::book($position, '2026-05-13', 1, [
        'confirmBy' => CarbonImmutable::parse('2026-04-03 12:00', 'Europe/Warsaw'),
    ]);
    $booking = new ReservationBooking;

    expect($reservation->status)->toBe(ReservationStatus::Pending)
        ->and($booking->expire($reservation, CarbonImmutable::parse('2026-04-02 12:00', 'Europe/Warsaw')))->toBeFalse()
        ->and($booking->expire($reservation, CarbonImmutable::parse('2026-04-03 12:01', 'Europe/Warsaw')))->toBeTrue()
        ->and($booking->expire($reservation, CarbonImmutable::parse('2026-04-03 12:02', 'Europe/Warsaw')))->toBeFalse();

    $fresh = $reservation->fresh();

    expect($fresh->status)->toBe(ReservationStatus::Expired)
        ->and($fresh->slots()->count())->toBe(0)
        ->and($fresh->terms)->not->toBeNull();
});

test('a confirmed reservation does not expire', function () {
    [, $position] = ReservationFixtures::bookableFishery();
    $reservation = ReservationFixtures::book($position, '2026-05-13', 1, [
        'confirmBy' => CarbonImmutable::parse('2026-04-03 12:00', 'Europe/Warsaw'),
    ]);
    $booking = new ReservationBooking;
    $booking->confirm($reservation, ConfirmationSource::Operator);

    expect($reservation->fresh()->confirm_by)->toBeNull()
        ->and($booking->expire($reservation, CarbonImmutable::parse('2026-05-01', 'Europe/Warsaw')))->toBeFalse()
        ->and($reservation->fresh()->status)->toBe(ReservationStatus::Confirmed);
});

test('a rebooking moves the slots and refusal keeps the old term intact', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    $reservation = ReservationFixtures::book($position, '2026-05-13', 2);
    ReservationFixtures::book($position, '2026-05-20', 1, ['angler' => new AnglerDetails('Inny', 'inny@example.com')]);
    $booking = new ReservationBooking;

    $refused = $booking->rebook(
        $reservation,
        ReservationFixtures::request($position, '2026-05-19', 2, ['angler' => $reservation->angler]),
        'Wędkarz prosi o zmianę',
    );

    expect($refused->reason)->toBe(SaleUnavailabilityReason::Occupied)
        ->and($reservation->fresh()->slots()->count())->toBe(2)
        ->and($reservation->fresh()->first_night_on->toDateString())->toBe('2026-05-13');

    $moved = $booking->rebook(
        $reservation,
        ReservationFixtures::request($position, '2026-05-14', 3, ['angler' => $reservation->angler]),
        'Wędkarz prosi o zmianę',
    );

    expect($moved->succeeded)->toBeTrue()
        ->and($reservation->fresh()->nights)->toBe(3)
        ->and($reservation->fresh()->slots()->count())->toBe(3)
        ->and($reservation->fresh()->terms->total_cents)->toBe(21000)
        ->and(Reservation::query()->count())->toBe(2);
});

test('the same email in a fishery reuses the angler relation, another fishery gets its own', function () {
    [, $position] = ReservationFixtures::bookableFishery();
    [, $otherPosition] = ReservationFixtures::bookableFishery();

    $first = ReservationFixtures::book($position, '2026-05-13', 1, ['angler' => new AnglerDetails('Jan', 'JAN@example.com ')]);
    $second = ReservationFixtures::book($position, '2026-05-20', 1, ['angler' => new AnglerDetails('Jan K.', 'jan@example.com')]);
    $elsewhere = ReservationFixtures::book($otherPosition, '2026-05-13', 1, ['angler' => new AnglerDetails('Jan', 'jan@example.com')]);

    expect($second->fishery_angler_id)->toBe($first->fishery_angler_id)
        ->and($elsewhere->fishery_angler_id)->not->toBe($first->fishery_angler_id)
        ->and(FisheryAngler::query()->count())->toBe(2);
});

test('a phone-only booking stays an unlinked relation', function () {
    [, $position] = ReservationFixtures::bookableFishery();

    $reservation = ReservationFixtures::book($position, '2026-05-13', 1, [
        'angler' => new AnglerDetails('Pan z telefonu', null, '+48600000000'),
    ]);

    expect($reservation->angler->status->value)->toBe('unlinked')
        ->and($reservation->angler->given_email)->toBeNull()
        ->and($reservation->terms->contact_snapshot['phone'])->toBe('+48600000000');
});

test('a withdrawn position is an overridable sale rule, but the angler channel is refused', function () {
    [, $position] = ReservationFixtures::bookableFishery();
    $position->update(['status' => PositionStatus::Withdrawn]);

    expect(ReservationFixtures::attempt($position, '2026-05-13', 1, ['source' => ReservationSource::Online])->reason)
        ->toBe(SaleUnavailabilityReason::PositionWithdrawn)
        ->and(ReservationFixtures::attempt($position, '2026-05-13', 1, ['exceptionReason' => 'Serwis'])->succeeded)
        ->toBeTrue();
});
