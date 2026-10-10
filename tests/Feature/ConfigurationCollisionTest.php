<?php

namespace Tests\Feature;

use App\Enums\BlockEffect;
use App\Enums\CollisionDecision;
use App\Enums\ConfigurationChangeKind;
use App\Enums\PaymentStatus;
use App\Enums\PositionStatus;
use App\Enums\ReservationStatus;
use App\Enums\SelectionKind;
use App\Enums\TermsExceptionKind;
use App\Filament\Resources\AvailabilityBlockResource\Pages\CreateAvailabilityBlock;
use App\Filament\Resources\FisheryResource\Pages\ResolveCollisions;
use App\Filament\Resources\PositionResource\Pages\EditPosition;
use App\Mail\ReservationCancelledMail;
use App\Models\AvailabilityBlock;
use App\Models\Position;
use App\Models\Reservation;
use App\Services\AnglerDetails;
use App\Services\CollisionDrafts;
use App\Services\CollisionGate;
use App\Services\ConfigurationChange;
use App\Services\ConfigurationCollisionService;
use App\Services\ReservationCalendar;
use App\Services\StaleCollisionDecision;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\ReservationFixtures;

/**
 * Kolizje zmian konfiguracji z rezerwacjami — blokada i wycofanie stanowiska (zadanie 044, ADR-027, G8).
 *
 * „Dziś" = 13.05.2026 12:00 (Warszawa); doba 15:00 → 15:00.
 */
beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-05-13 12:00', 'Europe/Warsaw'));
    Filament::setCurrentPanel('owner');
    Mail::fake();
});

afterEach(function () {
    Date::setTestNow();
});

function blockChange($fishery, array $positionIds, string $from, ?string $to, array $payload = []): ConfigurationChange
{
    return new ConfigurationChange(ConfigurationChangeKind::SaleBlock, $fishery->id, $positionIds, $from, $to, $payload);
}

function blockFields($fishery, string $from, ?string $to, string $reason = 'Zawody'): array
{
    return [
        'fishery_id' => $fishery->id,
        'effect' => BlockEffect::SaleBlocked->value,
        'position_attribute_id' => null,
        'starts_on' => $from,
        'ends_on' => $to,
        'reason' => $reason,
        'reason_visible' => true,
        'selection_kind' => SelectionKind::Manual->value,
        'selection_label' => null,
    ];
}

test('only future, not yet started reservations overlapping the change are affected', function () {
    [$fishery, $position] = ReservationFixtures::bookableFishery();
    $ongoing = ReservationFixtures::book($position, '2026-05-12', 3, ['exceptionReason' => 't']); // trwa
    $overlapping = ReservationFixtures::book($position, '2026-05-20', 2, ['angler' => new AnglerDetails('A', 'a@example.com')]);
    $later = ReservationFixtures::book($position, '2026-06-10', 1, ['angler' => new AnglerDetails('B', 'b@example.com')]);

    $affected = (new ConfigurationCollisionService)->affected(blockChange($fishery, [$position->id], '2026-05-13', '2026-05-21'));

    expect($affected->pluck('id')->all())->toBe([$overlapping->id])
        ->not->toContain($ongoing->id)
        ->not->toContain($later->id);

    // Bez daty końca — wszystko od początku okna, nadal bez trwającego pobytu.
    expect((new ConfigurationCollisionService)->affected(blockChange($fishery, [$position->id], '2026-05-13', null))->pluck('id')->all())
        ->toBe([$overlapping->id, $later->id]);
});

test('saving a colliding block goes to the decision screen and saves nothing yet; without collision it saves as before', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    ReservationFixtures::book($position, '2026-05-20', 1);
    $this->actingAs($owner);

    $form = fn (string $from, string $to) => [
        'effect' => BlockEffect::SaleBlocked->value, 'starts_on' => $from, 'ends_on' => $to,
        'reason' => 'Zawody', 'reason_visible' => true, 'selection_kind' => SelectionKind::Manual->value,
        'positions' => [$position->id],
    ];

    Livewire::withQueryParams(['fishery' => $fishery->id])
        ->test(CreateAvailabilityBlock::class)
        ->fillForm($form('2026-05-20', '2026-05-21'))
        ->call('create')
        ->assertRedirectContains("/fisheries/{$fishery->id}/collisions/");

    expect(AvailabilityBlock::query()->count())->toBe(0);

    Livewire::withQueryParams(['fishery' => $fishery->id])
        ->test(CreateAvailabilityBlock::class)
        ->fillForm($form('2026-06-01', '2026-06-02'))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(AvailabilityBlock::query()->count())->toBe(1);
});

test('cancel frees the slots, sets refund, emails the message — and the block is saved in the same step', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    $paid = ReservationFixtures::book($position, '2026-05-20', 1);
    $paid->update(['payment_status' => PaymentStatus::Paid]);
    $phoneOnly = ReservationFixtures::book($position, '2026-05-21', 1, ['angler' => new AnglerDetails('Tel', null, '+48600')]);
    $this->actingAs($owner);

    $token = CollisionDrafts::store(blockChange($fishery, [$position->id], '2026-05-20', '2026-05-21', ['fields' => blockFields($fishery, '2026-05-20', '2026-05-21')]));

    Livewire::test(ResolveCollisions::class, ['record' => $fishery->getKey(), 'token' => $token])
        ->assertSee($paid->angler->given_name)
        ->set('message', 'Zawody wędkarskie — przepraszamy')
        ->call('applyToAll', 'cancel')
        ->call('apply')
        ->assertRedirect();

    expect($paid->fresh()->status)->toBe(ReservationStatus::Cancelled)
        ->and($paid->fresh()->cancel_reason?->value)->toBe('collision')
        ->and($paid->fresh()->payment_status)->toBe(PaymentStatus::Refund)
        ->and($paid->fresh()->slots()->count())->toBe(0)
        ->and($phoneOnly->fresh()->needsContact())->toBeTrue()
        ->and(AvailabilityBlock::query()->sole()->positions()->pluck('positions.id')->all())->toBe([$position->id]);

    Mail::assertSent(ReservationCancelledMail::class, fn ($mail): bool => str_contains($mail->render(), 'Zawody wędkarskie'));
    Mail::assertSentCount(1);
});

test('keep records the exception and the reservation stays visible on the blocked night', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    $reservation = ReservationFixtures::book($position, '2026-05-20', 1);
    $this->actingAs($owner);
    $token = CollisionDrafts::store(blockChange($fishery, [$position->id], '2026-05-20', '2026-05-20', ['fields' => blockFields($fishery, '2026-05-20', '2026-05-20')]));

    Livewire::test(ResolveCollisions::class, ['record' => $fishery->getKey(), 'token' => $token])
        ->set("decisions.{$reservation->id}", 'keep')
        ->call('apply');

    $block = AvailabilityBlock::query()->sole();
    $exception = $reservation->fresh()->terms->exceptionsOf(TermsExceptionKind::SaleBlocked)[0];

    expect($reservation->fresh()->status)->toBe(ReservationStatus::Confirmed)
        ->and($exception['block_id'])->toBe($block->id)
        ->and($exception['by'])->toBe($owner->id);

    $grid = (new ReservationCalendar($fishery->fresh()))->grid(CarbonImmutable::parse('2026-05-18'), 7);
    $row = $grid->rows[0];

    expect($row->barOn(CarbonImmutable::parse('2026-05-20'))?->reservation->id)->toBe($reservation->id)
        ->and($row->cellOn(CarbonImmutable::parse('2026-05-20'))->blocked)->toBeTrue();

    // Edycja tej blokady nie pyta drugi raz o rezerwację już zostawioną.
    expect((new ConfigurationCollisionService)->affected(blockChange($fishery, [$position->id], '2026-05-19', '2026-05-21', ['block_id' => $block->id])))->toHaveCount(0);
});

test('a bulk decision and an individual one have the same effect on a reservation', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    $second = Position::factory()->create(['fishery_id' => $fishery->id, 'status' => 'available']);
    $a = ReservationFixtures::book($position, '2026-05-20', 1);
    $b = ReservationFixtures::book($second, '2026-05-20', 1, ['angler' => new AnglerDetails('B', 'b@example.com')]);
    $this->actingAs($owner);

    $tokenBulk = CollisionDrafts::store(blockChange($fishery, [$position->id], '2026-05-20', '2026-05-20', ['fields' => blockFields($fishery, '2026-05-20', '2026-05-20')]));
    Livewire::test(ResolveCollisions::class, ['record' => $fishery->getKey(), 'token' => $tokenBulk])->call('applyToAll', 'cancel')->call('apply');

    $tokenOne = CollisionDrafts::store(blockChange($fishery, [$second->id], '2026-05-20', '2026-05-20', ['fields' => blockFields($fishery, '2026-05-20', '2026-05-20')]));
    Livewire::test(ResolveCollisions::class, ['record' => $fishery->getKey(), 'token' => $tokenOne])->set("decisions.{$b->id}", 'cancel')->call('apply');

    expect([$a->fresh()->status, $a->fresh()->cancel_reason, $a->fresh()->payment_status])
        ->toEqual([$b->fresh()->status, $b->fresh()->cancel_reason, $b->fresh()->payment_status]);
});

test('without a decision for every reservation nothing is saved', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    $reservation = ReservationFixtures::book($position, '2026-05-20', 1);
    $this->actingAs($owner);
    $token = CollisionDrafts::store(blockChange($fishery, [$position->id], '2026-05-20', '2026-05-20', ['fields' => blockFields($fishery, '2026-05-20', '2026-05-20')]));

    Livewire::test(ResolveCollisions::class, ['record' => $fishery->getKey(), 'token' => $token])
        ->call('apply')
        ->assertNotified(__('Decide for every reservation'))
        ->assertNoRedirect();

    expect(AvailabilityBlock::query()->count())->toBe(0)
        ->and($reservation->fresh()->status)->toBe(ReservationStatus::Confirmed);
});

test('a reservation added after the screen was opened rolls the save back', function () {
    [$fishery, $position] = ReservationFixtures::bookableFishery();
    $first = ReservationFixtures::book($position, '2026-05-20', 1);
    $change = blockChange($fishery, [$position->id], '2026-05-20', '2026-05-22', ['fields' => blockFields($fishery, '2026-05-20', '2026-05-22')]);
    ReservationFixtures::book($position, '2026-05-22', 1, ['angler' => new AnglerDetails('N', 'n@example.com')]);

    expect(fn () => (new ConfigurationCollisionService)->apply($change, [$first->id => 'cancel'], fn () => AvailabilityBlock::query()->create(blockFields($fishery, '2026-05-20', '2026-05-22'))->id))
        ->toThrow(StaleCollisionDecision::class);

    expect(AvailabilityBlock::query()->count())->toBe(0)
        ->and($first->fresh()->status)->toBe(ReservationStatus::Confirmed);
});

test('withdrawing a position uses the same service with its own decision set', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    $reservation = ReservationFixtures::book($position, '2026-05-25', 1);
    $this->actingAs($owner);

    expect(ConfigurationChangeKind::PositionWithdrawal->decisions())->toBe([CollisionDecision::Cancel, CollisionDecision::Keep])
        ->and(ConfigurationChangeKind::AttributeSuspension->decisions())->toBe([]);

    $url = (new CollisionGate)->forWithdrawal($position->fresh());
    expect($url)->not->toBeNull();

    $token = basename((string) $url);
    Livewire::test(ResolveCollisions::class, ['record' => $fishery->getKey(), 'token' => $token])
        ->set("decisions.{$reservation->id}", 'keep')
        ->call('apply');

    expect($position->fresh()->status)->toBe(PositionStatus::Withdrawn)
        ->and($reservation->fresh()->terms->hasException(TermsExceptionKind::SaleRule))->toBeTrue()
        ->and($reservation->fresh()->status)->toBe(ReservationStatus::Confirmed);
});

test('editing a position to withdrawn keeps other changes and sends the withdrawal to the decision screen', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    ReservationFixtures::book($position, '2026-05-25', 1);
    $this->actingAs($owner);

    Livewire::test(EditPosition::class, ['record' => $position->getKey()])
        ->fillForm(['status' => PositionStatus::Withdrawn->value, 'description' => 'Nowy opis'])
        ->call('save')
        ->assertRedirectContains("/fisheries/{$fishery->id}/collisions/");

    expect($position->fresh()->status)->toBe(PositionStatus::Available)
        ->and($position->fresh()->description)->toContain('Nowy opis');
});

test('an attribute suspension only informs, it does not ask for decisions', function () {
    [$fishery, $position] = ReservationFixtures::bookableFishery();
    ReservationFixtures::book($position, '2026-05-20', 1);
    $fields = blockFields($fishery, '2026-05-20', '2026-05-20');
    $fields['effect'] = BlockEffect::AttributeSuspended->value;

    $gate = (new CollisionGate)->forBlock($fields, [$position->id]);

    expect($gate['redirect'])->toBeNull()
        ->and($gate['informational'])->toBe(1);
});

test('mails go one by one without a limit and a failing transport does not stop the cancellations', function () {
    Mail::swap(app('mail.manager')->mailer('array'));
    config(['mail.mailers.broken' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 1], 'mail.default' => 'broken']);
    app()->forgetInstance('mail.manager');
    Mail::clearResolvedInstances();

    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    $ids = [];

    foreach (range(20, 24) as $day) {
        $ids[] = ReservationFixtures::book($position, "2026-05-{$day}", 1, ['angler' => new AnglerDetails("W{$day}", "w{$day}@example.com")])->id;
    }

    $result = (new ConfigurationCollisionService)->apply(
        blockChange($fishery, [$position->id], '2026-05-20', '2026-05-25'),
        array_fill_keys($ids, 'cancel'),
        fn () => AvailabilityBlock::query()->create(blockFields($fishery, '2026-05-20', '2026-05-25'))->id,
        $owner,
    );

    expect($result->cancelled)->toBe(5)
        ->and($result->mailed)->toBe(0)
        ->and($result->notMailed)->toBe(5)
        ->and(Reservation::query()->whereIn('id', $ids)->toContact()->count())->toBe(5)
        ->and(AvailabilityBlock::query()->count())->toBe(1);
});
