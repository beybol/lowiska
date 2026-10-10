<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Fishery;
use App\Models\Position;
use App\Models\Reservation;
use App\Models\State;
use App\Services\AnglerDetails;
use App\Services\PortalCalendar;
use App\Services\PortalFisheryPage;
use App\Services\PortalRoutes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReservationFixtures;

/**
 * Publiczna dostępność w portalu — stan „Zajęte" (zadanie 046, ekran I, W10).
 *
 * „Dziś" = pon 04.05.2026; tydzień odniesienia 11–17.05.2026; doba 15:00 → 15:00.
 */
beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-05-04 09:00', 'Europe/Warsaw'));
});

afterEach(function () {
    Date::setTestNow();
});

/**
 * @return array{0: Fishery, 1: Position}
 */
function occupancyFishery(bool $public): array
{
    [$fishery, $position] = ReservationFixtures::bookableFishery([
        'name' => 'Zalew Testowy',
        'state_id' => State::factory()->create(['name' => 'Mazowsze'])->id,
        'company_id' => Company::factory()->create()->id,
        'published_at' => now(),
        'public_availability_enabled' => $public,
    ]);
    $position->update(['name' => 'S1', 'max_anglers' => 2]);
    ReservationFixtures::book($position, '2026-05-13', 1, [
        'angler' => new AnglerDetails('Zbigniew Tajemniczy', 'tajny@example.com', '+48777888999'),
    ]);

    return [$fishery->fresh(), $position->fresh()];
}

function weekUrl(Fishery $fishery): string
{
    return PortalRoutes::fisheryUrl($fishery, 'pl').'?tydzien=2026-05-11';
}

test('with public availability on, a booked night shows "Booked" and the legend has it', function () {
    [$fishery] = occupancyFishery(true);

    $this->get(weekUrl($fishery))
        ->assertOk()
        ->assertSee(__('Booked'))
        ->assertSeeHtml('data-occupied')
        ->assertSee(__('Booked nights are marked in the calendar.'))
        ->assertDontSee(__('The fishery will confirm free dates by phone.'));
});

test('with public availability off, the calendar behaves as before — the booked night is priced', function () {
    [$fishery] = occupancyFishery(false);

    $calendar = new PortalCalendar(PortalFisheryPage::load($fishery), ['tydzien' => '2026-05-11'], 'pl');
    $cells = $calendar->rows()[0]['cells'];

    expect(collect($cells)->pluck('state')->unique()->values()->all())->not->toContain('occupied')
        ->and($cells[2]['state'])->toBe('sellable');

    $this->get(weekUrl($fishery))
        ->assertOk()
        ->assertDontSeeHtml('data-occupied')
        ->assertSee(__('The fishery will confirm free dates by phone.'));
});

test('the page and the calendar fragment never reveal who booked', function () {
    [$fishery] = occupancyFishery(true);
    $reservationId = Reservation::query()->value('id');

    foreach ([[], ['X-Portal-Fragment' => 'calendar']] as $headers) {
        $html = $this->withHeaders($headers)->get(weekUrl($fishery))->assertOk()->getContent();

        expect($html)->toContain('data-occupied')
            ->not->toContain('Tajemniczy')
            ->not->toContain('tajny@example.com')
            ->not->toContain('777888999')
            ->not->toContain('reservation/'.$reservationId);
    }
});

test('the legend shows "Booked" only when the visible week has a booked night', function () {
    [$fishery] = occupancyFishery(true);

    $this->get(PortalRoutes::fisheryUrl($fishery, 'pl').'?tydzien=2026-06-01')
        ->assertOk()
        ->assertDontSeeHtml('data-occupied');
});

test('the mobile day list shows the booked state too', function () {
    [$fishery] = occupancyFishery(true);

    $this->get(PortalRoutes::fisheryUrl($fishery, 'pl').'?tydzien=2026-05-11&doba=2026-05-13')
        ->assertOk()
        ->assertSeeHtmlInOrder(['data-occupied', 'data-occupied']);
});

test('an unconfirmed reservation counts as booked', function () {
    [$fishery, $position] = occupancyFishery(true);
    ReservationFixtures::book($position, '2026-05-15', 1, [
        'angler' => new AnglerDetails('Inny', 'inny@example.com'),
        'confirmBy' => CarbonImmutable::now()->addDays(2),
    ]);

    $calendar = new PortalCalendar(PortalFisheryPage::load($fishery->fresh()), ['tydzien' => '2026-05-11'], 'pl');

    expect($calendar->rows()[0]['cells'][4]['state'])->toBe('occupied');
});

test('showing occupancy adds no queries and they do not grow with reservations', function () {
    [$fishery, $position] = occupancyFishery(true);
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });
    $count = function () use ($fishery, &$queries): int {
        $queries = 0;
        (new PortalCalendar(PortalFisheryPage::load($fishery->fresh()), ['tydzien' => '2026-05-11'], 'pl'))->rows();

        return $queries;
    };

    $withOccupancy = $count();
    $fishery->update(['public_availability_enabled' => false]);
    $withoutOccupancy = $count();
    $fishery->update(['public_availability_enabled' => true]);

    foreach (['2026-05-11', '2026-05-15', '2026-05-16'] as $day) {
        ReservationFixtures::book($position, $day, 1, ['angler' => new AnglerDetails('X'.$day, "x{$day}@example.com")]);
    }

    expect($withOccupancy)->toBe($withoutOccupancy)
        ->and($count())->toBe($withOccupancy);
});
