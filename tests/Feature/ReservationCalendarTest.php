<?php

namespace Tests\Feature;

use App\Enums\CalendarWindow;
use App\Filament\Resources\FisheryResource;
use App\Filament\Resources\FisheryResource\Pages\ManageCalendar;
use App\Filament\Resources\ReservationResource;
use App\Filament\Resources\ReservationResource\Pages\CreateReservation;
use App\Models\AvailabilityBlock;
use App\Models\Position;
use App\Models\Reservation;
use App\Services\AnglerDetails;
use App\Services\ReservationCalendar;
use App\Services\SaleCalendar;
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

test('the reservations layer is the default and starts this week in a one-week window', function () {
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

    // Model połówek: pasek sięga dnia wyjazdu (jego pierwszej połowy).
    expect($bars[$before->id]->column)->toBe(0)
        ->and($bars[$before->id]->span)->toBe(3) // 11, 12 i rano 13.05
        ->and($bars[$before->id]->startsBefore)->toBeTrue()
        ->and($bars[$before->id]->endsAfter)->toBeFalse()
        ->and($bars[$inside->id]->column)->toBe(3)
        ->and($bars[$inside->id]->span)->toBe(3) // od popołudnia 14.05 do rana 16.05
        ->and($bars[$inside->id]->startsBefore)->toBeFalse()
        ->and($bars[$inside->id]->coversNight(5))->toBeFalse() // 16.05 to dzień wyjazdu, nie doba pobytu
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
        // Blokada zajmuje całe dni kalendarzowe: komórka 18.05, nie 17.05 (choć doba od 17.05 ją przecina
        // i jej sprzedaż odmawia — to rozstrzyga PositionAvailability, nie wygląd komórki).
        ->and($cells['2026-05-17']->blocked)->toBeFalse()
        ->and($cells['2026-05-18']->blocked)->toBeTrue()
        ->and($cells['2026-05-19']->blocked)->toBeFalse();
});

test('the second click picks the departure day and never runs across an occupied night', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    ReservationFixtures::book($position, '2026-05-17', 1); // 17.05 15:00 → 18.05 12:00
    $this->actingAs($owner);

    Livewire::test(ManageCalendar::class, ['record' => $fishery->getKey()])
        ->set('window', 'two_weeks')
        ->call('selectCell', $position->id, '2026-05-12') // przeszłość — nic
        ->assertSet('selStart', null)
        ->call('selectCell', $position->id, '2026-05-17') // doba od 17.05 zajęta — nie może być przyjazdem
        ->assertSet('selStart', null)
        ->call('selectCell', $position->id, '2026-05-14')
        ->assertSet('selStart', '2026-05-14')
        ->assertSet('selEnd', '2026-05-14') // jedna doba, wyjazd 15.05
        ->call('selectCell', $position->id, '2026-05-16') // wyjazd 16.05 → doby 14 i 15
        ->assertSet('selEnd', '2026-05-15')
        ->call('selectCell', $position->id, '2026-05-20') // przez zajętą dobę 17.05 → wyjazd 17.05
        ->assertSet('selEnd', '2026-05-16')
        ->assertSeeHtml('data-selection-popover')
        ->assertSeeHtml('data-selection-overlay')
        ->call('selectCell', $position->id, '2026-05-17') // klik w dzień wyjazdu przedłużyłby, ale doba 17.05 zajęta
        ->assertSet('selEnd', '2026-05-16')
        ->call('selectCell', $position->id, '2026-05-14') // klik w dzień przyjazdu zamyka
        ->assertSet('selStart', null)
        // Klik w dzień tuż po przyjeździe działa jak klik w następny: pobyt na dwie doby (wyjazd 16.05).
        ->call('selectCell', $position->id, '2026-05-14')
        ->call('selectCell', $position->id, '2026-05-15')
        ->assertSet('selStart', '2026-05-14')
        ->assertSet('selEnd', '2026-05-15')
        ->assertSeeHtml('data-selection-popover')
        ->call('selectCell', $position->id, '2026-05-16') // klik w bieżący dzień wyjazdu przedłuża o dobę
        ->assertSet('selEnd', '2026-05-16')
        ->call('selectCell', $position->id, '2026-05-15') // klik wewnątrz pobytu skraca (wyjazd 15.05)
        ->assertSet('selEnd', '2026-05-14')
        ->call('selectCell', $position->id, '2026-05-14') // zamyka
        // …ale nie przez zajętą dobę: przyjazd 16.05, klik 17.05 — doba od 17.05 zajęta, zostaje jedna doba.
        ->call('selectCell', $position->id, '2026-05-16')
        ->call('selectCell', $position->id, '2026-05-17')
        ->assertSet('selStart', '2026-05-16')
        ->assertSet('selEnd', '2026-05-16');

    // Ślad kliknięcia (widoczny tylko na czas żądania): kształt przyszłego zaznaczenia, liczony tak jak `selectCell()`.
    $pendingStyle = function (string $html, string $day) use ($position): ?string {
        preg_match('/data-cell="'.$position->id.'-'.$day.'"[^>]*>(?:\s|<!--(?:(?!-->).)*-->)*<span data-pending-selection style="([^"]*)"/s', $html, $match);

        return $match[1] ?? null;
    };
    $page = Livewire::test(ManageCalendar::class, ['record' => $fishery->getKey()])->set('window', 'two_weeks');

    expect($pendingStyle($page->html(), '2026-05-14'))->toStartWith('left:calc(0% - 0px);z-index:5;width:calc(200% + 1px)') // jedna doba
        ->and($pendingStyle($page->html(), '2026-05-17'))->toBeNull(); // doba zajęta — klik nic nie zaznaczy

    $page->call('selectCell', $position->id, '2026-05-14');

    expect($pendingStyle($page->html(), '2026-05-14'))->toBeNull() // klik w przyjazd zamyka
        ->and($pendingStyle($page->html(), '2026-05-16'))->toStartWith('left:calc(-200% - 2px);z-index:5;width:calc(300% + 2px)') // 14 → 16
        ->and($pendingStyle($page->html(), '2026-05-15'))->toStartWith('left:calc(-100% - 1px);z-index:5;width:calc(300% + 2px)'); // bieżący wyjazd: +1 doba

    // Dzień wyjazdu rezerwacji (18.05, rano zajęty) jest dniem przyjazdu następnej.
    Livewire::test(ManageCalendar::class, ['record' => $fishery->getKey()])
        ->call('selectCell', $position->id, '2026-05-18')
        ->assertSet('selStart', '2026-05-18');

    $grid = (new ReservationCalendar($fishery->fresh()))->grid(CarbonImmutable::parse('2026-05-11'), 14);
    $cells = collect($grid->rows[0]->cells)->keyBy(fn ($cell) => $cell->day->toDateString());

    expect($cells['2026-05-17']->occupied)->toBeTrue()
        ->and($cells['2026-05-17']->morningOccupied)->toBeFalse()
        ->and($cells['2026-05-18']->morningOccupied)->toBeTrue()
        ->and($cells['2026-05-18']->occupied)->toBeFalse()
        ->and($cells['2026-05-18']->isSelectable())->toBeTrue();
});

test('quick booking from the popover saves through the booking service', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    $this->actingAs($owner);

    Livewire::test(ManageCalendar::class, ['record' => $fishery->getKey()])
        ->call('selectCell', $position->id, '2026-05-14')
        ->call('selectCell', $position->id, '2026-05-16') // wyjazd 16.05 = 2 doby
        ->assertSet('selEnd', '2026-05-15')
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

test('the quick block saves a sale block for the selected nights and sends a collision to the decision screen', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    ReservationFixtures::book($position, '2026-05-20', 1);
    $this->actingAs($owner);

    Livewire::test(ManageCalendar::class, ['record' => $fishery->getKey()])
        ->callAction(TestAction::make('quickBlock')->arguments(['position' => $position->id, 'from' => '2026-05-14', 'to' => '2026-05-15']), data: ['reason' => 'Koszenie'])
        ->assertHasNoActionErrors();

    expect(AvailabilityBlock::query()->sole()->positions()->pluck('positions.id')->all())->toBe([$position->id]);

    // Blokada od 21.05 zaczyna się 21.05 o 15:00 — rezerwacja kończąca się tego dnia z nią nie koliduje
    // (przełom dnia jak w rezerwacji, `dostepnosc.md` §3).
    Livewire::test(ManageCalendar::class, ['record' => $fishery->getKey()])
        ->callAction(TestAction::make('quickBlock')->arguments(['position' => $position->id, 'from' => '2026-05-21', 'to' => '2026-05-22']), data: ['reason' => 'Po wyjeździe'])
        ->assertHasNoActionErrors()
        ->assertNoRedirect();

    expect(AvailabilityBlock::query()->count())->toBe(2);

    // 20.05 to doba rezerwacji — skrót przechodzi na ekran decyzji E (047, A9).
    Livewire::test(ManageCalendar::class, ['record' => $fishery->getKey()])
        ->callAction(TestAction::make('quickBlock')->arguments(['position' => $position->id, 'from' => '2026-05-19', 'to' => '2026-05-20']), data: ['reason' => 'X'])
        ->assertRedirectContains('/collisions/');

    expect(AvailabilityBlock::query()->count())->toBe(2);
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
    // Bez parametru wraca ostatnio oglądana warstwa (pamięć w sesji); parametr ją przełącza.
    $this->get(FisheryResource::getUrl('calendar', ['record' => $fishery], panel: 'owner'))
        ->assertOk()
        ->assertSee(__('Season'));
    $this->get(FisheryResource::getUrl('calendar', ['record' => $fishery], panel: 'owner').'?layer=reservations')
        ->assertOk()
        ->assertSee(__('Arrivals today'));
});

test('the calendar comes back to the last window and week after visiting another tab', function () {
    [$fishery, , $owner] = ReservationFixtures::bookableFishery();
    $this->actingAs($owner);

    Livewire::test(ManageCalendar::class, ['record' => $fishery->getKey()])
        ->set('window', 'week')
        ->call('nextWindow')
        ->call('nextWindow')
        ->assertSet('windowStart', '2026-05-25');

    // Powrót bez parametrów w adresie — to samo okno i ten sam (przyszły) zakres.
    Livewire::test(ManageCalendar::class, ['record' => $fishery->getKey()])
        ->assertSet('layer', 'reservations')
        ->assertSet('window', 'week')
        ->assertSet('windowStart', '2026-05-25')
        ->assertSet('phoneDay', '2026-05-25');

    // Stan z sesji to dane od klienta: śmieci wracają do domyślnych.
    session()->put('calendar.'.$fishery->getKey(), ['layer' => 'reservations', 'reservations' => ['window' => 'year', 'windowStart' => 'x']]);

    Livewire::test(ManageCalendar::class, ['record' => $fishery->getKey()])
        ->assertSet('window', 'two_weeks')
        ->assertSet('windowStart', '2026-05-11');
});

test('the two-week window moves by one week', function () {
    [$fishery, , $owner] = ReservationFixtures::bookableFishery();
    $this->actingAs($owner);

    Livewire::test(ManageCalendar::class, ['record' => $fishery->getKey()])
        ->assertSet('window', 'two_weeks')
        ->call('nextWindow')
        ->assertSet('windowStart', '2026-05-18')
        ->call('previousWindow')
        ->call('previousWindow')
        ->assertSet('windowStart', '2026-05-04');

    expect(CalendarWindow::TwoWeeks->endFor(CarbonImmutable::parse('2026-05-18'))->toDateString())->toBe('2026-05-31');
});

test('the quick form shows the stay period and "save and open full form" saves and opens the full edit', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    $this->actingAs($owner);

    // Ta sama informacja obok liczby dób stoi w pełnym formularzu (modal krótkiego nie renderuje się w teście).
    Livewire::withQueryParams(['fishery' => $fishery->id, 'position' => $position->id, 'from' => '2026-05-14', 'nights' => 2])
        ->test(CreateReservation::class)
        ->assertSeeHtml('data-stay-period')
        ->assertSee('14.05.2026 15:00')
        ->assertSee('16.05.2026 15:00');

    // Stanowisko stoi w nagłówku okna, nie gołą nazwą pod nim.
    Livewire::test(ManageCalendar::class, ['record' => $fishery->getKey()])
        ->mountAction(TestAction::make('quickBook')->arguments(['position' => $position->id, 'date' => '2026-05-14', 'nights' => 2]))
        ->assertActionMounted('quickBook')
        ->assertMountedActionModalSee(__('Position reservation: :position', ['position' => $position->name]));

    $component = Livewire::test(ManageCalendar::class, ['record' => $fishery->getKey()])
        ->callAction(
            TestAction::make('quickBook')->arguments(['position' => $position->id, 'date' => '2026-05-14', 'nights' => 2, 'full' => true]),
            data: ['angler_name' => 'Do pełnego', 'angler_phone' => '+48600', 'nights' => 2, 'anglers' => 2, 'companions' => 1],
        )
        ->assertHasNoActionErrors();

    $reservation = Reservation::query()->sole();
    $component->assertRedirect(ReservationResource::getUrl('edit', ['record' => $reservation]));

    expect($reservation->terms->anglers)->toBe(2)
        ->and($reservation->terms->companions)->toBe(1);
});

// ─── 051: koszt obliczeń i renderu ─────────────────────────────────────────────────

test('the reservations layer asks the offer layer only about free, upcoming nights', function () {
    [$fishery, $position] = ReservationFixtures::bookableFishery();
    ReservationFixtures::book($position, '2026-05-14', 2); // doby 14 i 15; dziś 13.05
    $model = $fishery->fresh();

    $grid = (new ReservationCalendar($model))->grid(CarbonImmutable::parse('2026-05-11'), 7, offer: new SaleCalendar($model));
    $cells = collect($grid->rows[0]->cells)->keyBy(fn ($cell) => $cell->day->toDateString());
    $full = collect((new SaleCalendar($model))->grid(CarbonImmutable::parse('2026-05-11'), CalendarWindow::Week)->rows[0]->cells)
        ->keyBy(fn ($cell) => $cell->night->toDateString());

    expect($cells['2026-05-11']->offer)->toBeNull() // miniona
        ->and($cells['2026-05-12']->offer)->toBeNull()
        ->and($cells['2026-05-14']->offer)->toBeNull() // zajęta
        ->and($cells['2026-05-15']->offer)->toBeNull()
        ->and($cells['2026-05-13']->offer?->sellable)->toBeTrue()
        ->and($cells['2026-05-16']->offer?->sellable)->toBeTrue() // dzień wyjazdu: doba od 16.05 wolna
        ->and($cells['2026-05-17']->offer?->totalInCents)->toBe(7000)
        // Te same komórki co pełna siatka „Oferta" — liczone tym samym kodem.
        ->and($cells['2026-05-17']->offer?->totalInCents)->toBe($full['2026-05-17']->totalInCents)
        ->and($cells['2026-05-16']->offer?->nights)->toBe($full['2026-05-16']->nights);
});

test('the reservation form prices the state once per request', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    $this->actingAs($owner);
    $priceRuleQueries = 0;
    DB::listen(function ($query) use (&$priceRuleQueries): void {
        if (str_contains($query->sql, 'price_rules')) {
            $priceRuleQueries++;
        }
    });

    Livewire::withQueryParams(['fishery' => $fishery->id, 'position' => $position->id, 'from' => '2026-05-14', 'nights' => 2])
        ->test(CreateReservation::class)
        ->assertSee('140');

    // Podgląd ceny, widoczność i wymagalność pól powodów pytają o wycenę kilka razy — cennik czyta się raz.
    expect($priceRuleQueries)->toBe(1);
});

test('clicking a bar re-renders only the popover island', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    $reservation = ReservationFixtures::book($position, '2026-05-14', 2);
    $this->actingAs($owner);
    $islandCall = fn (int $id): array => [['method' => 'selectReservation', 'params' => [$id], 'path' => '', 'metadata' => ['island' => ['name' => 'popover', 'mode' => 'morph']]]];
    $effectsOf = fn ($component): array => \Livewire\invade($component)->lastState->getEffects();

    $component = Livewire::test(ManageCalendar::class, ['record' => $fishery->getKey()])
        ->assertSeeHtml('wire:island="popover"')
        ->update(calls: $islandCall($reservation->id))
        ->assertSet('selReservation', $reservation->id);
    $effects = $effectsOf($component);
    $fragments = implode('', $effects['islandFragments'] ?? []);

    expect($effects)->not->toHaveKey('html')
        ->and($fragments)->toContain('data-reservation-popover')
        ->and($fragments)->toContain('#'.$reservation->id)
        ->and($fragments)->not->toContain('data-reservation-grid');

    // Klik w pasek przy zaznaczonych dobach zdejmuje nakładkę z siatki — wtedy pełny render.
    $component = Livewire::test(ManageCalendar::class, ['record' => $fishery->getKey()])
        ->call('selectCell', $position->id, '2026-05-17')
        ->assertSeeHtml('data-popover-anchor') // nakładka zaznaczenia (sam `data-selection-overlay` stoi też w kodzie Alpine)
        ->update(calls: $islandCall($reservation->id))
        ->assertSet('selPosition', null);
    $effects = $effectsOf($component);

    expect($effects['html'] ?? '')->toContain('data-reservation-grid')
        ->and($effects['html'] ?? '')->not->toContain('data-popover-anchor')
        ->and($effects['html'] ?? '')->toContain('data-reservation-popover');
});

test('the phone view is an island loaded on demand and refreshed when the window moves', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    $this->actingAs($owner);
    $effectsOf = fn ($component): array => \Livewire\invade($component)->lastState->getEffects();

    $component = Livewire::test(ManageCalendar::class, ['record' => $fishery->getKey()])
        ->assertSeeHtml('wire:intersect.once="loadPhoneView"')
        ->assertDontSeeHtml('data-free-until')
        ->call('selectCell', $position->id, '2026-05-14') // klik w dzień: pełny render bez widoku telefonu
        ->assertDontSeeHtml('data-free-until')
        ->assertSet('phoneLoaded', false)
        ->call('loadPhoneView')
        ->assertSet('phoneLoaded', true);
    $effects = $effectsOf($component);
    $fragments = implode('', $effects['islandFragments'] ?? []);

    expect($effects)->not->toHaveKey('html')
        ->and($fragments)->toContain('data-phone-view')
        ->and($fragments)->toContain('data-free-until')
        ->and($fragments)->toContain("selectPhoneDay('2026-05-11')");

    // Przesunięcie okna spoza wyspy odświeża ją jawnie; sama strona nie niesie treści telefonu.
    $component->call('nextWindow')->assertSet('windowStart', '2026-05-18');
    $effects = $effectsOf($component);

    expect(implode('', $effects['islandFragments'] ?? []))->toContain("selectPhoneDay('2026-05-18')")
        ->and($effects['html'] ?? '')->toContain('data-reservation-grid')
        ->and($effects['html'] ?? '')->not->toContain('data-free-until');
});
