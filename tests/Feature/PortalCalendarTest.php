<?php

namespace Tests\Feature;

use App\Enums\CalendarWindow;
use App\Enums\PositionAttributeType;
use App\Enums\PositionStatus;
use App\Models\Company;
use App\Models\Fishery;
use App\Models\Position;
use App\Models\PositionAttribute;
use App\Models\PositionAttributeOption;
use App\Models\PositionAttributeValue;
use App\Models\PositionGroup;
use App\Models\PriceRule;
use App\Models\State;
use App\Services\AmountFormatter;
use App\Services\FisheryRulesSummary;
use App\Services\PortalCalendar;
use App\Services\PortalFisheryPage;
use App\Services\SaleCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Tests\Support\StayFixtures;

/**
 * Kalendarz portalu — płaska siatka stanowisk (zadanie 033, portal-v3 §3, ADR-022).
 *
 * ⚠️ Czas zamrożony: pon 04.05.2026. Tydzień odniesienia: 01.06–07.06.2026 — Boże Ciało sprzedawane
 * w całości od śr 3.06 (4 doby) i weekend pt–nd w całości.
 */
beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-05-04 09:00', 'Europe/Warsaw'));
});

afterEach(function () {
    Date::setTestNow();
});

/**
 * „Klasztorne": doba 15–15, sezon 2026, weekend pt+sob w całości, Boże Ciało 3.06 na 4 doby,
 * stawka 130 zł, stanowisko „1" (do 2 łowiących).
 */
function klasztorne(array $attributes = []): Fishery
{
    [$fishery, $position] = StayFixtures::fisheryWithPosition(array_merge([
        'name' => 'Klasztorne',
        'state_id' => State::factory()->create(['name' => 'Greater Poland'])->id,
        'company_id' => Company::factory()->create(['name' => 'Firma Klasztorna'])->id,
        'published_at' => now(),
        'weekend_days' => [5, 6],
    ], $attributes));
    $position->update(['name' => '1', 'max_anglers' => 2]);
    StayFixtures::rate($fishery, 130.00);
    StayFixtures::wholeTerm($fishery, '2026-06-03', 4);

    return $fishery->fresh();
}

function portalCalendar(Fishery $fishery, array $query = [], string $locale = 'pl'): PortalCalendar
{
    app()->setLocale($locale);

    return new PortalCalendar(PortalFisheryPage::load($fishery->fresh()), $query, $locale);
}

function addPosition(Fishery $fishery, string $label, array $attributes = []): Position
{
    return Position::factory()->create(array_merge([
        'fishery_id' => $fishery->id,
        'name' => $label,
        'max_anglers' => 2,
        'status' => PositionStatus::Available,
    ], $attributes));
}

test('the cells match the panel preview calendar for the same data — Boże Ciało week', function () {
    $fishery = klasztorne();
    addPosition($fishery, '2');

    $portal = portalCalendar($fishery, ['tydzien' => '2026-06-01'])->rows();
    $panel = (new SaleCalendar($fishery->fresh()))->grid(CarbonImmutable::parse('2026-06-01'), CalendarWindow::Week);

    $panelByLabel = collect($panel->rows)->keyBy(fn ($row) => $row->position->name);

    foreach ($portal as $row) {
        foreach ($row['cells'] as $i => $cell) {
            $expected = $panelByLabel[$row['header']['label']]->cells[$i];

            if ($expected->sellable) {
                expect($cell['state'])->toBe('sellable')
                    ->and($cell['price'])->toBe(AmountFormatter::forVisitor($expected->totalInCents, $fishery->currency?->name));
            } elseif ($expected->startEarlierOn !== null) {
                expect($cell['state'])->toBe('bundle');
            } else {
                expect($cell['state'])->toBe('refused');
            }
        }
    }

    // Boże Ciało: śr 3.06 sprzedaje 4 doby, czw–sob mówią „zacznij wcześniej".
    $cells = $portal[0]['cells'];
    expect($cells[2]['state'])->toBe('sellable')
        ->and($cells[2]['nights'])->toBe('4 doby')
        ->and($cells[3]['state'])->toBe('bundle')
        ->and($cells[5]['state'])->toBe('bundle');
});

test('a surcharge is marked in the cell and itemised in the breakdown — Łopienno Thursday to Sunday', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition([
        'name' => 'Łopienno',
        'state_id' => State::factory()->create(['name' => 'Greater Poland'])->id,
        'published_at' => now(),
    ]);
    $position->update(['name' => '1', 'max_anglers' => 2]);
    StayFixtures::rate($fishery, 70.00);
    PriceRule::factory()->surcharge(20.00, 'Stanowisko tylko dla Ciebie')->onWeekdays([4, 5, 6, 7])->forAnglers(1)
        ->create(['fishery_id' => $fishery->id]);

    $cells = portalCalendar($fishery, ['tydzien' => '2026-06-01'])->rows()[0]['cells'];

    expect($cells[0]['surcharge'])->toBeFalse()
        ->and($cells[3]['surcharge'])->toBeTrue()
        ->and($cells[3]['price'])->toContain('90')
        ->and(collect($cells[3]['tooltip']['lines'])->pluck('label')->implode('|'))->toContain('Stanowisko tylko dla Ciebie');
});

test('the bundles row comes from the start-earlier verdicts, with the start date', function () {
    $fishery = klasztorne();

    $bands = portalCalendar($fishery, ['tydzien' => '2026-06-01'])->packages();

    expect($bands)->toHaveCount(1)
        ->and($bands[0]['from'])->toBe(2)
        ->and($bands[0]['to'])->toBe(5)
        ->and($bands[0]['label'])->toContain('3.06');
});

test('a bundle crossing into the next week shows its start date there', function () {
    $fishery = klasztorne();
    // Sob 13.06 na 3 doby spina się z weekendem pt–sob, więc pakiet zaczyna się w pt 12.06 (spoiwo przechodnie).
    StayFixtures::wholeTerm($fishery, '2026-06-13', 3);

    $bands = portalCalendar($fishery, ['tydzien' => '2026-06-15'])->packages();

    expect($bands[0]['from'])->toBe(0)
        ->and($bands[0]['label'])->toContain('pakiet od')
        ->and($bands[0]['label'])->toContain('12.06');
});

test('the group switch shows one group at a time, and a position in two groups appears in each', function () {
    $fishery = klasztorne();
    $west = PositionGroup::factory()->create(['fishery_id' => $fishery->id, 'name' => 'Brzeg zachodni']);
    $east = PositionGroup::factory()->create(['fishery_id' => $fishery->id, 'name' => 'Brzeg wschodni']);
    $both = addPosition($fishery, '8');
    $both->groups()->attach([$west->id, $east->id]);
    addPosition($fishery, '22')->groups()->attach($east);

    $labels = fn (array $query) => collect(portalCalendar($fishery, $query)->rows())->pluck('header.label')->all();

    expect($labels([]))->toBe(['1', '8', '22'])
        ->and($labels(['grupa' => 'brzeg-zachodni']))->toBe(['8'])
        ->and($labels(['grupa' => 'brzeg-wschodni']))->toBe(['8', '22'])
        ->and($labels(['grupa' => 'nie-ma-takiej']))->toBe(['1', '8', '22']);

    $options = collect(portalCalendar($fishery)->groupOptions())->pluck('count', 'label')->all();
    expect($options)->toBe(['Wszystkie' => 3, 'Brzeg wschodni' => 2, 'Brzeg zachodni' => 1]);
});

test('"All" always counts every position for sale and resets both the group and the features', function () {
    $fishery = klasztorne();
    $west = PositionGroup::factory()->create(['fishery_id' => $fishery->id, 'name' => 'Brzeg zachodni']);
    $jetty = PositionAttribute::factory()->create(['name' => 'Pomost', 'is_filterable' => true]);
    $a = addPosition($fishery, '2');
    $a->groups()->attach($west);
    PositionAttributeValue::factory()->create(['position_id' => $a->id, 'position_attribute_id' => $jetty->id, 'value_flag' => true]);
    addPosition($fishery, '3');

    $all = fn (array $query) => collect(portalCalendar($fishery, $query)->groupOptions())->firstWhere('label', 'Wszystkie');

    foreach ([[], ['grupa' => 'brzeg-zachodni'], ['cecha' => 'pomost'], ['grupa' => 'brzeg-zachodni', 'cecha' => 'pomost']] as $query) {
        expect($all($query)['count'])->toBe(3)
            ->and($all($query)['url'])->not->toContain('grupa=')
            ->and($all($query)['url'])->not->toContain('cecha=');
    }

    expect($all([])['active'])->toBeTrue()
        ->and($all(['cecha' => 'pomost'])['active'])->toBeFalse()
        ->and($all(['grupa' => 'brzeg-zachodni'])['active'])->toBeFalse();
});

test('clicking the active group again switches it off, like a feature, and keeps the features', function () {
    $fishery = klasztorne();
    $west = PositionGroup::factory()->create(['fishery_id' => $fishery->id, 'name' => 'Brzeg zachodni']);
    $jetty = PositionAttribute::factory()->create(['name' => 'Pomost', 'is_filterable' => true]);
    $a = addPosition($fishery, '2');
    $a->groups()->attach($west);
    PositionAttributeValue::factory()->create(['position_id' => $a->id, 'position_attribute_id' => $jetty->id, 'value_flag' => true]);

    $group = fn (array $query) => collect(portalCalendar($fishery, $query)->groupOptions())->firstWhere('label', 'Brzeg zachodni');

    expect($group([])['url'])->toContain('grupa=brzeg-zachodni')
        ->and($group(['grupa' => 'brzeg-zachodni'])['active'])->toBeTrue()
        ->and($group(['grupa' => 'brzeg-zachodni'])['url'])->not->toContain('grupa=')
        ->and($group(['grupa' => 'brzeg-zachodni', 'cecha' => 'pomost'])['url'])->not->toContain('grupa=')
        ->and($group(['grupa' => 'brzeg-zachodni', 'cecha' => 'pomost'])['url'])->toContain('cecha=pomost');
});

test('a fishery with features but no groups still gets the "All" reset', function () {
    $fishery = klasztorne();
    $jetty = PositionAttribute::factory()->create(['name' => 'Pomost', 'is_filterable' => true]);
    PositionAttributeValue::factory()->create(['position_id' => $fishery->positions()->first()->id, 'position_attribute_id' => $jetty->id, 'value_flag' => true]);

    expect(collect(portalCalendar($fishery)->groupOptions())->pluck('label')->all())->toBe(['Wszystkie']);
});

test('feature switches combine with AND, and a missing value does not match', function () {
    $fishery = klasztorne();
    $jetty = PositionAttribute::factory()->create(['name' => 'Pomost', 'is_filterable' => true]);
    $shore = PositionAttribute::factory()->create(['name' => 'Brzeg', 'type' => PositionAttributeType::Choice, 'is_filterable' => true]);
    $sandy = PositionAttributeOption::factory()->create(['position_attribute_id' => $shore->id, 'name' => 'Piaszczysty']);

    $a = addPosition($fishery, '2');
    $b = addPosition($fishery, '3');
    PositionAttributeValue::factory()->create(['position_id' => $a->id, 'position_attribute_id' => $jetty->id, 'value_flag' => true]);
    PositionAttributeValue::factory()->create(['position_id' => $a->id, 'position_attribute_id' => $shore->id, 'value_flag' => null, 'position_attribute_option_id' => $sandy->id]);
    PositionAttributeValue::factory()->create(['position_id' => $b->id, 'position_attribute_id' => $jetty->id, 'value_flag' => true]);

    $labels = fn (array $query) => collect(portalCalendar($fishery, $query)->rows())->pluck('header.label')->all();

    expect($labels(['cecha' => 'pomost']))->toBe(['2', '3'])
        ->and($labels(['cecha' => 'pomost,brzeg:piaszczysty']))->toBe(['2'])
        ->and($labels(['cecha' => 'nieznana']))->toBe(['1', '2', '3']);
});

test('the anglers switch changes the price and turns a too small position into one row message', function () {
    $fishery = klasztorne();
    addPosition($fishery, '31', ['max_anglers' => 1]);

    $rows = collect(portalCalendar($fishery, ['tydzien' => '2026-06-08', 'lowiacych' => '2'])->rows())->keyBy('header.label');

    expect($rows['1']['cells'][0]['price'])->toContain('260')
        ->and($rows['31']['cells'])->toBe([])
        ->and($rows['31']['message'])->toContain('niedostępne przy 2 łowiących');

    $options = collect(portalCalendar($fishery)->anglerOptions())->pluck('count')->all();
    expect($options)->toBe([1, 2]);
});

test('invalid parameters fall back to the defaults', function () {
    $fishery = klasztorne();

    $calendar = portalCalendar($fishery, ['tydzien' => '2026-13-45', 'lowiacych' => '99', 'grupa' => '<script>']);

    expect($calendar->week()->toDateString())->toBe('2026-05-04')
        ->and($calendar->anglers())->toBe(1);
});

test('a withdrawn position has no row', function () {
    $fishery = klasztorne();
    addPosition($fishery, '9', ['status' => PositionStatus::Withdrawn]);

    expect(collect(portalCalendar($fishery)->rows())->pluck('header.label')->all())->toBe(['1']);
});

test('the state is in the address under language-dependent names, and the canonical has no parameters', function () {
    klasztorne();

    $pl = $this->get('/pl/wielkopolskie/klasztorne?tydzien=2026-06-01&lowiacych=2')->assertOk();
    $pl->assertSee('<link rel="canonical" href="'.url('pl/wielkopolskie/klasztorne').'">', escape: false)
        // przełącznik języka niesie stan pod nazwami drugiego języka
        ->assertSee('href="'.url('en/wielkopolskie/klasztorne').'?week=2026-06-01&amp;anglers=2"', escape: false)
        ->assertSee('rel="nofollow" data-calendar-link', escape: false)
        ->assertSee('tydzien=2026-06-08', escape: false);

    $this->get('/en/wielkopolskie/klasztorne?week=2026-06-01')
        ->assertOk()
        ->assertSee('?week=2026-06-08', escape: false)
        // „tydzien=" wolno wyłącznie w linku przełącznika na PL — linki kalendarza mają nazwy EN.
        ->assertDontSee('?tydzien=2026-06-08', escape: false)
        ->assertSee('href="'.url('pl/wielkopolskie/klasztorne').'?tydzien=2026-06-01"', escape: false);
});

test('the fragment request returns only the calendar', function () {
    klasztorne();

    $html = $this->withHeader('X-Portal-Fragment', 'calendar')
        ->get('/pl/wielkopolskie/klasztorne?tydzien=2026-06-01')
        ->assertOk()
        ->getContent();

    expect(trim($html))->toStartWith('<section id="kalendarz" data-calendar')
        ->and($html)->not->toContain('<html');
});

test('the full page shows the grid, the rules summary and the breakdown tooltip', function () {
    klasztorne(['fishing_license_required' => true, 'no_kill' => true]);

    $this->get('/pl/wielkopolskie/klasztorne?tydzien=2026-06-01')
        ->assertOk()
        ->assertSee('Terminy i ceny')
        ->assertSee('Doba 15:00–15:00')
        ->assertSee('03.06–07.06 tylko w całości')
        ->assertSee('karta wędkarska wymagana')
        ->assertSee('ryby wypuszczamy (no-kill)')
        ->assertSee('Pakiety')
        ->assertSee('Łowiący × 4 doby')
        ->assertSee('Razem');
});

test('the home page card line comes from the same rules summary', function () {
    $fishery = klasztorne();

    $line = (new FisheryRulesSummary($fishery->fresh()))->cardLine();

    expect($line)->toStartWith('Doba 15:00–15:00');
    $this->get('/pl')->assertOk()->assertSee($line);
});

test('the portal calendar adds no queries per row on top of the sale calendar', function () {
    $overhead = function (int $positions): int {
        $fishery = klasztorne();
        foreach (range(2, $positions) as $i) {
            addPosition($fishery, (string) $i);
        }
        $loaded = PortalFisheryPage::load($fishery->fresh());

        $count = function (callable $work): int {
            $queries = 0;
            DB::listen(function () use (&$queries): void {
                $queries++;
            });
            $work();

            return $queries;
        };

        $portal = $count(fn () => (new PortalCalendar($loaded, ['tydzien' => '2026-06-01'], 'pl'))->rows());
        $grid = $count(fn () => (new SaleCalendar($loaded))->grid(CarbonImmutable::parse('2026-06-01'), CalendarWindow::Week));

        return $portal - $grid;
    };

    expect($overhead(6))->toBe($overhead(2));
});

/**
 * ⚠️ Mierzy RENDER fragmentu, nie same wiersze: każdy link siatki (tygodnie, łowiący, grupy, doby, stanowiska)
 * woła `PortalCalendar::url()`, a ten liczył domyślny tydzień od nowa — zapytanie na link, więc N+1 na stanowisko
 * (038). Test `rows()` tego nie widział.
 */
test('rendering the calendar fragment adds no queries per position', function () {
    $queriesFor = function (int $positions): int {
        $fishery = klasztorne();
        foreach (range(2, $positions) as $i) {
            addPosition($fishery, (string) $i);
        }
        $loaded = PortalFisheryPage::load($fishery->fresh());
        $calendar = new PortalCalendar($loaded, ['tydzien' => '2026-06-01'], 'pl');
        $calendar->rows();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        view('portal.partials.calendar', ['calendar' => $calendar, 'fishery' => $loaded])->render();

        return $queries;
    };

    expect($queriesFor(6))->toBe($queriesFor(2));
});

test('a week outside the reachable window falls back to the default week', function () {
    $fishery = klasztorne();
    $default = portalCalendar($fishery)->week()->toDateString();

    expect(portalCalendar($fishery, ['tydzien' => '1999-01-04'])->week()->toDateString())->toBe($default)
        ->and(portalCalendar($fishery, ['tydzien' => '2099-06-01'])->week()->toDateString())->toBe($default)
        ->and(portalCalendar($fishery, ['tydzien' => '2026-06-03'])->week()->toDateString())->toBe('2026-06-01');
});
