<?php

namespace Tests\Feature;

use App\Enums\AnglerLinkStatus;
use App\Enums\CancellationReason;
use App\Filament\Resources\FisheryResource;
use App\Filament\Resources\FisheryResource\Pages\ListFisheries;
use App\Filament\Resources\FisheryResource\Pages\ManageAnglers;
use App\Filament\Resources\FisheryResource\Pages\ManageCalendar;
use App\Filament\Resources\FisheryResource\Pages\ManageReservations;
use App\Filament\Resources\ReservationResource;
use App\Models\FisheryAngler;
use App\Models\Reservation;
use App\Models\User;
use App\Services\AnglerDetails;
use App\Services\CancellationRequest;
use App\Services\OwnerRoleProvisioner;
use App\Services\ReservationBooking;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Tables\Enums\RecordActionsPosition;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\Support\ReservationFixtures;

/**
 * Wędkarze łowiska — podgląd tylko do odczytu (zadanie 048; ADR-024 pkt 2).
 */
beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-04-01 12:00', 'Europe/Warsaw'));
    Filament::setCurrentPanel('owner');
});

afterEach(function () {
    Date::setTestNow();
});

/**
 * Wędkarz łowiska z jedną rezerwacją; stan relacji ustawiany jawnie, bo dane efektywne od niego zależą.
 *
 * @param  array<string, mixed>  $relation
 */
function anglerWithReservation($position, string $name, ?string $email, string $startsOn = '2026-05-13', array $relation = [], ?string $phone = null): FisheryAngler
{
    $reservation = ReservationFixtures::book($position, $startsOn, 1, ['angler' => new AnglerDetails($name, $email, $phone)]);
    $angler = $reservation->angler;

    if ($relation !== []) {
        $angler->update($relation);
    }

    return $angler->fresh();
}

function linkedAccount(array $attributes = []): User
{
    return User::factory()->create(array_merge(['name' => 'Konto', 'surname' => 'Wedkarza', 'phone' => '+48600600600'], $attributes));
}

/**
 * Treść podglądu wędkarza. Modale akcji nie wchodzą do `html()` komponentu w testach Livewire (renderuje je
 * częściowa aktualizacja), więc renderujemy schemat zamontowanej akcji wprost.
 */
function previewHtml(Testable $page, FisheryAngler $angler): string
{
    $page->mountTableAction('preview', $angler);
    $component = $page->instance();

    return (new ReflectionMethod($component, 'getMountedActionSchema'))->invoke($component)->toHtml();
}

test('the page sits right after reservations in both panels and is closed to foreign owners', function () {
    [$fishery, , $owner] = ReservationFixtures::bookableFishery();
    $this->actingAs($owner);
    $page = Livewire::test(ManageCalendar::class, ['record' => $fishery->getKey()])->instance();
    $labels = collect(FisheryResource::getRecordSubNavigation($page))->map(fn ($item) => $item->getLabel())->values()->all();

    expect(array_search(__('Fishery anglers'), $labels, true))->toBe(array_search(__('Reservations'), $labels, true) + 1);

    $this->get(route(ManageAnglers::getRouteName(), ['record' => $fishery]))->assertOk();

    $stranger = User::factory()->create();
    OwnerRoleProvisioner::addOwnerRole($stranger);
    $this->actingAs($stranger);
    $this->get(route(ManageAnglers::getRouteName(), ['record' => $fishery]))->assertNotFound();

    $this->actingAs($this->createSuperAdmin());
    Filament::setCurrentPanel('admin');
    $this->get(route(ManageAnglers::getRouteName(), ['record' => $fishery]))->assertOk();
});

test('the table shows this fishery only and the data each state entitles the fishery to', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    [, $otherPosition] = ReservationFixtures::bookableFishery();
    $account = linkedAccount(['email' => 'konto@example.com']);

    $unlinked = anglerWithReservation($position, 'Ula Niepowiazana', 'ula@example.com', '2026-05-13', [], '+48111');
    $pending = anglerWithReservation($position, 'Pawel Prosba', 'pawel@example.com', '2026-05-14', ['status' => AnglerLinkStatus::Pending->value]);
    $linked = anglerWithReservation($position, 'Stare Dane', 'stare@example.com', '2026-05-15', [
        'status' => AnglerLinkStatus::Linked->value, 'user_id' => $account->id, 'phone_override' => '+48999',
    ]);
    $revoked = anglerWithReservation($position, 'Roman Cofniety', 'roman@example.com', '2026-05-16', ['status' => AnglerLinkStatus::Revoked->value]);
    $foreign = anglerWithReservation($otherPosition, 'Obcy Wedkarz', 'obcy@example.com');
    $this->actingAs($owner);

    Livewire::test(ManageAnglers::class, ['record' => $fishery->getKey()])
        ->assertCanSeeTableRecords([$unlinked, $pending, $linked, $revoked])
        ->assertCanNotSeeTableRecords([$foreign])
        ->assertTableColumnStateSet('given_name', 'Ula Niepowiazana', $unlinked)
        ->assertTableColumnStateSet('given_email', 'ula@example.com', $unlinked)
        ->assertTableColumnStateSet('given_name', 'Konto Wedkarza', $linked)
        ->assertTableColumnStateSet('given_email', 'konto@example.com', $linked)
        ->assertTableColumnStateSet('given_name', null, $revoked)
        ->assertTableColumnStateSet('given_email', null, $revoked)
        ->assertSee(AnglerLinkStatus::Revoked->accountLabel())
        ->assertSee(AnglerLinkStatus::Linked->accountLabel())
        ->assertSee(AnglerLinkStatus::Unlinked->accountLabel())
        ->assertSee('+48999')
        ->assertDontSee('+48600600600')
        ->assertDontSee('Stare Dane')
        ->assertDontSee('roman@example.com');
});

test('the filters narrow the list as described', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    $upcoming = anglerWithReservation($position, 'Nadchodzacy', 'nad@example.com', '2026-05-13');
    $linked = anglerWithReservation($position, 'Powiazany', 'pow@example.com', '2026-05-15', ['status' => AnglerLinkStatus::Linked->value, 'user_id' => linkedAccount()->id]);
    $revoked = anglerWithReservation($position, 'Cofniety', 'cof@example.com', '2026-05-17', ['status' => AnglerLinkStatus::Revoked->value]);
    $pending = anglerWithReservation($position, 'Oczekujacy', 'ocz@example.com', '2026-05-19', ['status' => AnglerLinkStatus::Pending->value]);

    // Nikt z poniższych nie ma nadchodzącego pobytu: pierwszy już trwa, drugi jest anulowany.
    $started = anglerWithReservation($position, 'Trwajacy', 'trw@example.com', '2026-05-21');
    $started->reservations()->sole()->forceFill([
        'starts_at' => CarbonImmutable::parse('2026-03-30 15:00', 'Europe/Warsaw'),
        'ends_at' => CarbonImmutable::parse('2026-04-03 15:00', 'Europe/Warsaw'),
    ])->save();
    $cancelledAngler = anglerWithReservation($position, 'Anulowany', 'anul@example.com', '2026-05-23');
    (new ReservationBooking)->cancel($cancelledAngler->reservations()->sole(), new CancellationRequest(CancellationReason::FisheryDecision, $owner));

    $contactAngler = anglerWithReservation($position, 'Do Kontaktu', null, '2026-05-25', [], '+48700');
    $contactAngler->reservations()->sole()->update(['needs_contact_at' => now()]);
    $this->actingAs($owner);

    $page = Livewire::test(ManageAnglers::class, ['record' => $fishery->getKey()]);

    $page->filterTable('data_state', 'provided')
        ->assertCanSeeTableRecords([$upcoming, $pending, $started])
        ->assertCanNotSeeTableRecords([$linked, $revoked])
        ->filterTable('data_state', 'linked')
        ->assertCanSeeTableRecords([$linked])
        ->assertCanNotSeeTableRecords([$upcoming, $pending, $revoked])
        ->filterTable('data_state', 'revoked')
        ->assertCanSeeTableRecords([$revoked])
        ->assertCanNotSeeTableRecords([$upcoming, $linked])
        ->removeTableFilter('data_state');

    $page->filterTable('has_upcoming', true)
        ->assertCanSeeTableRecords([$upcoming, $linked, $revoked, $pending, $contactAngler])
        ->assertCanNotSeeTableRecords([$started, $cancelledAngler])
        ->removeTableFilter('has_upcoming')
        ->filterTable('to_contact', true)
        ->assertCanSeeTableRecords([$contactAngler])
        ->assertCanNotSeeTableRecords([$upcoming, $started]);
});

test('the search finds the effective data only, across states, and never another fishery', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    [, $otherPosition] = ReservationFixtures::bookableFishery();
    $account = linkedAccount(['name' => 'Waldemar', 'surname' => 'Konto', 'email' => 'waldemar@konto.example']);
    $unlinked = anglerWithReservation($position, 'Ula Zwykla', 'ula@example.com', '2026-05-13', [], '+48111222333');
    $linked = anglerWithReservation($position, 'Dawna Nazwa', 'dawny@example.com', '2026-05-14', [
        'status' => AnglerLinkStatus::Linked->value, 'user_id' => $account->id, 'phone_override' => '+48555',
    ]);
    $revoked = anglerWithReservation($position, 'Roman Cofniety', 'roman@example.com', '2026-05-15', ['status' => AnglerLinkStatus::Revoked->value]);
    $foreign = anglerWithReservation($otherPosition, 'Ula Zwykla', 'obcy@example.com');
    $unlinked->update(['notes' => 'tajna-notatka']);
    $this->actingAs($owner);

    $page = Livewire::test(ManageAnglers::class, ['record' => $fishery->getKey()]);

    $page->searchTable('Ula Zwykla')->assertCanSeeTableRecords([$unlinked])->assertCanNotSeeTableRecords([$foreign, $linked])
        ->searchTable('111222')->assertCanSeeTableRecords([$unlinked])->assertCanNotSeeTableRecords([$linked])
        ->searchTable('waldemar@konto')->assertCanSeeTableRecords([$linked])->assertCanNotSeeTableRecords([$unlinked])
        ->searchTable('Waldemar Konto')->assertCanSeeTableRecords([$linked])
        ->searchTable('+48555')->assertCanSeeTableRecords([$linked])
        // Powiązany pokazuje dane konta, więc dawne dane od łowiska nie są już „jego" danymi.
        ->searchTable('Dawna')->assertCanNotSeeTableRecords([$linked])
        ->searchTable('dawny@example')->assertCanNotSeeTableRecords([$linked])
        // Cofnięty dostęp nie ma danych, a notatki nie są przeszukiwane.
        ->searchTable('Roman')->assertCanNotSeeTableRecords([$revoked])
        ->searchTable('roman@example')->assertCanNotSeeTableRecords([$revoked])
        ->searchTable('tajna-notatka')->assertCanNotSeeTableRecords([$unlinked])
        // Znaki wieloznaczne LIKE są zwykłymi znakami.
        ->searchTable('%')->assertCanNotSeeTableRecords([$unlinked, $linked, $revoked]);
});

test('the SQL rule for effective data agrees with effectiveContact() in every state', function () {
    [, $position] = ReservationFixtures::bookableFishery();
    $account = linkedAccount(['name' => 'Konto', 'surname' => 'Pelne', 'email' => 'pelne@konto.example', 'phone' => '+48600']);
    $bare = linkedAccount(['name' => 'Goly', 'surname' => '', 'email' => 'goly@konto.example', 'phone' => null]);

    $anglers = [
        anglerWithReservation($position, 'Unlinked Un', 'un@example.com', '2026-05-10', [], '+48101'),
        anglerWithReservation($position, 'Pending Pe', 'pe@example.com', '2026-05-11', ['status' => AnglerLinkStatus::Pending->value], '+48102'),
        anglerWithReservation($position, 'Linked Li', 'li@example.com', '2026-05-12', ['status' => AnglerLinkStatus::Linked->value, 'user_id' => $account->id], '+48103'),
        anglerWithReservation($position, 'Override Ov', 'ov@example.com', '2026-05-13', ['status' => AnglerLinkStatus::Linked->value, 'user_id' => $bare->id, 'phone_override' => '+48104'], '+48105'),
        anglerWithReservation($position, 'Fallback Fa', 'fa@example.com', '2026-05-14', ['status' => AnglerLinkStatus::Linked->value, 'user_id' => linkedAccount(['phone' => null])->id], '+48106'),
        anglerWithReservation($position, 'Revoked Re', 'rev@example.com', '2026-05-15', ['status' => AnglerLinkStatus::Revoked->value], '+48107'),
    ];

    $rows = FisheryAngler::query()
        ->selectRaw('fishery_anglers.id, '.FisheryAngler::effectiveSql('name').' as sql_name, '.FisheryAngler::effectiveSql('email').' as sql_email, '.FisheryAngler::effectiveSql('phone').' as sql_phone')
        ->get()->keyBy('id');

    foreach ($anglers as $angler) {
        $effective = $angler->load('user')->effectiveContact();
        $row = $rows[$angler->id];

        expect([$row->sql_name, $row->sql_email, $row->sql_phone])
            ->toBe([$effective['name'], $effective['email'], $effective['phone']], "state {$angler->status->value} of {$angler->given_name}");
    }
});

test('the preview lists reservations of this fishery only, newest first, each linking to its details', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    [, $otherPosition] = ReservationFixtures::bookableFishery();
    $details = ['angler' => new AnglerDetails('Jan Powracajacy', 'jan@example.com')];
    $first = ReservationFixtures::book($position, '2026-05-13', 1, $details);
    $second = ReservationFixtures::book($position, '2026-06-13', 1, $details);
    $cancelled = ReservationFixtures::book($position, '2026-07-13', 1, $details);
    (new ReservationBooking)->cancel($cancelled, new CancellationRequest(CancellationReason::FisheryDecision, $owner));
    $elsewhere = ReservationFixtures::book($otherPosition, '2026-05-13', 1, $details);
    $angler = $first->angler;
    $this->actingAs($owner);

    expect($angler->is($second->angler))->toBeTrue()
        ->and($angler->is($elsewhere->angler))->toBeFalse();

    $page = Livewire::test(ManageAnglers::class, ['record' => $fishery->getKey()])
        ->assertTableColumnStateSet('active_reservations_count', 2, $angler);
    $html = previewHtml($page, $angler);
    $at = fn (Reservation $reservation): int|false => preg_match('/#'.$reservation->id.'\b/', $html, $match, PREG_OFFSET_CAPTURE) ? $match[0][1] : false;

    expect($at($first))->not->toBeFalse()
        ->and($at($second))->not->toBeFalse()
        ->and($at($cancelled))->not->toBeFalse()
        ->and($at($elsewhere))->toBeFalse()
        ->and($at($cancelled))->toBeLessThan($at($second))
        ->and($at($second))->toBeLessThan($at($first))
        ->and($html)->toContain(ReservationResource::getUrl('view', ['record' => $second]));
});

test('the preview of a revoked relation says so and shows no personal data', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    $revoked = anglerWithReservation($position, 'Roman Cofniety', 'roman@example.com', '2026-05-16', ['status' => AnglerLinkStatus::Revoked->value, 'notes' => 'notatka']);
    $this->actingAs($owner);

    $html = previewHtml(Livewire::test(ManageAnglers::class, ['record' => $fishery->getKey()]), $revoked);

    expect($html)->toContain(__('Access revoked'))
        ->not->toContain('Roman Cofniety')
        ->not->toContain('roman@example.com');
});

test('the preview puts no relation data into the client-side action state', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    $revoked = anglerWithReservation($position, 'Roman Cofniety', 'roman@example.com', '2026-05-16', ['status' => AnglerLinkStatus::Revoked->value, 'notes' => 'tajna-notatka'], '+48123');
    $this->actingAs($owner);

    $page = Livewire::test(ManageAnglers::class, ['record' => $fishery->getKey()])->mountTableAction('preview', $revoked);
    $data = $page->instance()->mountedActions[0]['data'] ?? [];

    expect($data)->not->toHaveKeys(['given_name', 'given_email', 'given_phone', 'phone_override', 'notes'])
        ->and(json_encode($data))->not->toContain('Roman')->not->toContain('roman@example.com')->not->toContain('tajna-notatka');
});

test('the preview of a pending relation shows the link validity and of a linked one the link date', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    $pending = anglerWithReservation($position, 'Pawel Prosba', 'pawel@example.com', '2026-05-14', ['status' => AnglerLinkStatus::Pending->value]);
    $pending->forceFill(['link_expires_at' => CarbonImmutable::parse('2026-04-10 10:00', 'Europe/Warsaw')])->save();
    $linked = anglerWithReservation($position, 'Dawna Nazwa', 'dawny@example.com', '2026-05-15', [
        'status' => AnglerLinkStatus::Linked->value, 'user_id' => linkedAccount()->id,
    ]);
    $linked->forceFill(['linked_at' => CarbonImmutable::parse('2026-03-20 08:30', 'Europe/Warsaw')])->save();
    $this->actingAs($owner);

    expect(previewHtml(Livewire::test(ManageAnglers::class, ['record' => $fishery->getKey()]), $pending))->toContain('10.04.2026 10:00')
        ->and(previewHtml(Livewire::test(ManageAnglers::class, ['record' => $fishery->getKey()]), $linked))->toContain('20.03.2026 08:30');
});

test('the page is read-only and the table query stays flat as anglers are added', function () {
    [$fishery, $position, $owner] = ReservationFixtures::bookableFishery();
    $this->actingAs($owner);
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });
    $render = function () use ($fishery, &$queries): void {
        $queries = 0;
        Livewire::test(ManageAnglers::class, ['record' => $fishery->getKey()]);
    };

    foreach ([1, 2] as $i) {
        anglerWithReservation($position, "Wedkarz {$i}", "w{$i}@example.com", '2026-05-0'.$i, $i === 2
            ? ['status' => AnglerLinkStatus::Linked->value, 'user_id' => linkedAccount(['email' => 'konto2@example.com'])->id]
            : []);
    }
    $render(); // rozgrzewka: pierwsze wejście ładuje uprawnienia i konfigurację panelu
    $render();
    $few = $queries;

    foreach ([3, 4, 5, 6, 7, 8] as $i) {
        anglerWithReservation($position, "Wedkarz {$i}", "w{$i}@example.com", '2026-05-0'.$i, $i % 2 === 0
            ? ['status' => AnglerLinkStatus::Linked->value, 'user_id' => linkedAccount(['email' => "konto{$i}@example.com"])->id]
            : []);
    }
    $render();

    expect($queries)->toBe($few);

    $angler = FisheryAngler::query()->firstOrFail();

    Livewire::test(ManageAnglers::class, ['record' => $fishery->getKey()])
        ->assertTableActionExists('preview', record: $angler)
        ->assertTableActionDoesNotExist('edit', record: $angler)
        ->assertTableActionDoesNotExist('delete', record: $angler);
});

test('row actions stand before the columns in every panel table, not per table', function () {
    [$fishery, , $owner] = ReservationFixtures::bookableFishery();
    $this->actingAs($owner);

    $tables = [
        Livewire::test(ManageAnglers::class, ['record' => $fishery->getKey()]),
        Livewire::test(ManageReservations::class, ['record' => $fishery->getKey()]),
        Livewire::test(ListFisheries::class),
    ];

    foreach ($tables as $page) {
        expect($page->instance()->getTable()->getRecordActionsPosition())->toBe(RecordActionsPosition::BeforeCells);
    }
});
