<?php

namespace Tests\Feature;

use App\Enums\CalendarWindow;
use App\Filament\Resources\FisheryResource;
use App\Filament\Resources\FisheryResource\Pages\ManageCalendar;
use App\Models\AvailabilityBlock;
use App\Models\Position;
use App\Models\Reservation;
use App\Services\AnglerDetails;
use App\Services\ReservationCalendar;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\ReservationFixtures;
use Tests\Support\StayFixtures;

/**
 * Kalendarz — warstwa „Rezerwacje" (zadanie 042, G4).
 *
 * „Dziś" = środa 13.05.2026, 12:00; okno dwóch tygodni zaczyna się w poniedziałek 11.05.
 */
beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-05-13 12:00', 'Europe/Warsaw'));
    Filament::setCurrentPanel('owner');
});

afterEach(function () {
    Date::setTestNow();
});

test('the reservations layer is the default and starts this week in a two-week window', function () {
    [$fishery, , $owner] = ReservationFixtures::bookableFishery();
    $this->actingAs($owner);

    Livewire::test(ManageCalendar::class, ['record' => $fishery->getKey()])
        ->assertSet('layer', 'reservations')
        ->assertSet('window', 'two_weeks')
        ->assertSet('windowStart', '2026-05-11')
        ->assertSee(__('Arrivals today'));

    // Wartość spoza enuma wraca do domyślnej, a miesiąc jest tylko w „Ofercie".
    Livewire::withQueryParams(['layer' => 'x'])->test(ManageCalendar::class, ['record' => $fishery->getKey()])
        ->assertSet('layer', 'reservations')
        ->set('window', 'month')
        ->assertSet('window', 'month')
        ->tap(fn ($component) => expect($component->instance()->unit())->toBe(CalendarWindow::TwoWeeks));
});

test('the offer layer keeps the 019 preview and shows an occupied night as booked', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    ReservationFixtures::book($position, '2026-05-20', 1);
    $this->actingAs($owner);

    Livewire::withQueryParams(['layer' => 'offer'])
        ->test(ManageCalendar::class, ['record' => $fishery->getKey()])
        ->assertSet('window', 'month')
        ->assertSee(__('booked'))
        ->assertDontSee(__('Arrivals today'));
});

test('bars are clipped to the window and mark a stay that started earlier', function () {
    [$fishery, $position] = ReservationFixtures::bookableFishery();
    $before = ReservationFixtures::book($position, '2026-05-09', 4, ['exceptionReason' => 'test']); // 9–12.05
    $inside = ReservationFixtures::book($position, '2026-05-14', 2, ['angler' => new AnglerDetails('B', 'b@example.com')]);
    $after = ReservationFixtures::book($position, '2026-05-23', 3, ['angler' => new AnglerDetails('C', 'c@example.com')]); // 23–25.05

    $grid = (new ReservationCalendar($fishery->fresh()))->grid(CarbonImmutable::parse('2026-05-11'), 14);
    $bars = collect($grid->rows[0]->bars)->keyBy(fn ($bar) => $bar->reservation->id);

    expect($bars[$before->id]->column)->toBe(0)
        ->and($bars[$before->id]->span)->toBe(2) // 11 i 12.05
        ->and($bars[$before->id]->startsBefore)->toBeTrue()
        ->and($bars[$inside->id]->column)->toBe(3)
        ->and($bars[$inside->id]->span)->toBe(2)
        ->and($bars[$inside->id]->startsBefore)->toBeFalse()
        ->and($bars[$after->id]->column)->toBe(12)
        ->and($bars[$after->id]->span)->toBe(2)
        ->and($bars[$after->id]->endsAfter)->toBeTrue()
        // Pobyt w toku dziś (13.05 nie jest w żadnym — 9–12 skończył się 13.05 15:00, więc trwa).
        ->and($bars[$before->id]->kind)->toBe('in_progress');
});

test('blocked nights are marked and past nights are not selectable', function () {
    [$fishery, $position] = ReservationFixtures::bookableFishery();
    StayFixtures::blockSale($fishery, $position, '2026-05-18', '2026-05-18');

    $grid = (new ReservationCalendar($fishery->fresh()))->grid(CarbonImmutable::parse('2026-05-11'), 14);
    $cells = collect($grid->rows[0]->cells)->keyBy(fn ($cell) => $cell->day->toDateString());

    expect($cells['2026-05-12']->past)->toBeTrue()
        ->and($cells['2026-05-12']->isSelectable())->toBeFalse()
        ->and($cells['2026-05-13']->isSelectable())->toBeTrue()
        // Doba 17.05 kończy się 18.05 15:00, więc przecina okno blokady od północy 18.05.
        ->and($cells['2026-05-17']->blocked)->toBeTrue()
        ->and($cells['2026-05-18']->blocked)->toBeTrue()
        ->and($cells['2026-05-19']->blocked)->toBeFalse();
});

test('a second click extends the selection but never across an occupied night', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    ReservationFixtures::book($position, '2026-05-17', 1);
    $this->actingAs($owner);

    Livewire::test(ManageCalendar::class, ['record' => $fishery->getKey()])
        ->call('selectCell', $position->id, '2026-05-12') // przeszłość — nic
        ->assertSet('selStart', null)
        ->call('selectCell', $position->id, '2026-05-14')
        ->assertSet('selStart', '2026-05-14')
        ->call('selectCell', $position->id, '2026-05-20') // przez zajęte 17.05 → stop na 16.05
        ->assertSet('selEnd', '2026-05-16')
        ->assertSeeHtml('data-selection-popover')
        ->call('selectCell', $position->id, '2026-05-17') // zajęte — nic
        ->assertSet('selEnd', '2026-05-16')
        ->call('clearSelection')
        ->assertSet('selStart', null);
});

test('quick booking from the popover saves through the booking service', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    $this->actingAs($owner);

    Livewire::test(ManageCalendar::class, ['record' => $fishery->getKey()])
        ->call('selectCell', $position->id, '2026-05-14')
        ->call('selectCell', $position->id, '2026-05-15')
        ->callAction(
            TestAction::make('quickBook')->arguments(['position' => $position->id, 'date' => '2026-05-14', 'nights' => 2]),
            data: ['angler_name' => 'Telefoniczny Klient', 'angler_phone' => '+48600', 'nights' => 2, 'anglers' => 1, 'companions' => 0],
        )
        ->assertHasNoActionErrors();

    $reservation = Reservation::query()->sole();

    expect($reservation->nights)->toBe(2)
        ->and($reservation->first_night_on->toDateString())->toBe('2026-05-14')
        ->and($reservation->slots()->count())->toBe(2);
});

test('quick booking can not exceed the free nights', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    ReservationFixtures::book($position, '2026-05-17', 1);
    $this->actingAs($owner);

    Livewire::test(ManageCalendar::class, ['record' => $fishery->getKey()])
        ->callAction(
            TestAction::make('quickBook')->arguments(['position' => $position->id, 'date' => '2026-05-14', 'nights' => 1]),
            data: ['angler_name' => 'X', 'nights' => 5, 'anglers' => 1],
        )
        ->assertHasActionErrors(['nights']);

    expect(Reservation::query()->count())->toBe(1);
});

test('the quick block saves a sale block for the selected nights and refuses over a reservation', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    ReservationFixtures::book($position, '2026-05-20', 1);
    $this->actingAs($owner);

    Livewire::test(ManageCalendar::class, ['record' => $fishery->getKey()])
        ->callAction(TestAction::make('quickBlock')->arguments(['position' => $position->id, 'from' => '2026-05-14', 'to' => '2026-05-15']), data: ['reason' => 'Koszenie'])
        ->assertHasNoActionErrors();

    expect(AvailabilityBlock::query()->sole()->positions()->pluck('positions.id')->all())->toBe([$position->id]);

    // 21.05 przecina dobę 20.05 (do 21.05 15:00) — skrót odmawia i odsyła do ekranu blokad.
    Livewire::test(ManageCalendar::class, ['record' => $fishery->getKey()])
        ->callAction(TestAction::make('quickBlock')->arguments(['position' => $position->id, 'from' => '2026-05-21', 'to' => '2026-05-21']), data: ['reason' => 'X'])
        ->assertNotified();

    expect(AvailabilityBlock::query()->count())->toBe(1);
});

test('reservation actions work from the calendar without leaving it, also cancelling an ongoing stay', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    $ongoing = ReservationFixtures::book($position, '2026-05-12', 3, ['exceptionReason' => 'test']);
    $future = ReservationFixtures::book($position, '2026-05-20', 1, ['angler' => new AnglerDetails('F', 'f@example.com')]);
    $this->actingAs($owner);

    Livewire::test(ManageCalendar::class, ['record' => $fishery->getKey()])
        ->call('selectReservation', $future->id)
        ->assertSeeHtml('data-reservation-popover')
        ->callAction(TestAction::make('markPaid')->arguments(['reservation' => $future->id]))
        ->callAction(TestAction::make('cancelReservation')->arguments(['reservation' => $ongoing->id]), data: ['refund' => 'terms'])
        ->assertHasActionErrors(['confirm_in_progress']);

    expect($future->fresh()->payment_status->value)->toBe('paid')
        ->and($ongoing->fresh()->status->value)->toBe('confirmed');
});

test('the calendar does not act on a reservation of another fishery', function () {
    [$fishery, , $owner] = ReservationFixtures::bookableFishery();
    [, $foreignPosition] = ReservationFixtures::bookableFishery();
    $foreign = ReservationFixtures::book($foreignPosition, '2026-05-20', 1);
    $this->actingAs($owner);

    Livewire::test(ManageCalendar::class, ['record' => $fishery->getKey()])
        ->call('selectReservation', $foreign->id)
        ->assertSet('selReservation', null)
        ->call('selectCell', $foreignPosition->id, '2026-05-20')
        ->assertSet('selPosition', null);
});

test('the phone view counts free nights up to the next reservation, at most thirty', function () {
    [$fishery, $position] = ReservationFixtures::bookableFishery();
    $second = Position::factory()->create(['fishery_id' => $fishery->id, 'status' => 'available']);
    ReservationFixtures::book($position, '2026-05-18', 1);
    $calendar = new ReservationCalendar($fishery->fresh());

    expect($calendar->freeNightsFrom($position->fresh(['fishery']), CarbonImmutable::parse('2026-05-14')))->toBe(4)
        ->and($calendar->freeNightsFrom($second->fresh(['fishery']), CarbonImmutable::parse('2026-05-14')))->toBe(ReservationCalendar::MAX_FREE_NIGHTS);
});

test('the reservations grid query count does not grow with nights or positions', function () {
    [$fishery, $position] = ReservationFixtures::bookableFishery();
    ReservationFixtures::book($position, '2026-05-14', 2);
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });
    $count = function (int $days) use ($fishery, &$queries): int {
        $model = $fishery->fresh();
        $queries = 0;
        (new ReservationCalendar($model))->grid(CarbonImmutable::parse('2026-05-11'), $days);

        return $queries;
    };

    $week = $count(7);
    $twoWeeks = $count(14);

    Position::factory()->count(5)->create(['fishery_id' => $fishery->id, 'status' => 'available']);
    $morePositions = $count(14);

    expect($week)->toBeGreaterThan(0)
        ->and($twoWeeks)->toBe($week)
        ->and($morePositions)->toBe($week);
});

test('the layer switch is in the address and both panels open the page', function () {
    [$fishery, , $owner] = ReservationFixtures::bookableFishery();
    $this->actingAs($owner);

    $this->get(FisheryResource::getUrl('calendar', ['record' => $fishery], panel: 'owner').'?layer=offer')
        ->assertOk()
        ->assertSee(__('Season'));
    $this->get(FisheryResource::getUrl('calendar', ['record' => $fishery], panel: 'owner'))
        ->assertOk()
        ->assertSee(__('Arrivals today'));
});
