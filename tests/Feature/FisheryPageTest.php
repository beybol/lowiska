<?php

namespace Tests\Feature;

use App\Enums\PositionStatus;
use App\Models\Company;
use App\Models\Convenience;
use App\Models\Fishery;
use App\Models\Position;
use App\Models\PositionAttribute;
use App\Models\PositionAttributeValue;
use App\Models\PositionGroup;
use App\Models\State;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Testing\TestResponse;
use Tests\Support\StayFixtures;

/**
 * Strona łowiska w portalu — zakładki „Mapa i terminy" i „Szczegóły", box z ceną (zadanie 032).
 *
 * ⚠️ Nazwy ustawione WPROST — losowe z fabryki bywają podciągami tekstów strony.
 */
beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-05-04 09:00', 'Europe/Warsaw'));
});

afterEach(function () {
    Date::setTestNow();
});

/** Opublikowane łowisko „Klasztorne" w wielkopolskim z dobą, sezonem 2026 i jednym stanowiskiem „1". */
function pageFishery(array $attributes = []): Fishery
{
    [$fishery, $position] = StayFixtures::fisheryWithPosition(array_merge([
        'name' => 'Klasztorne',
        'state_id' => State::factory()->create(['name' => 'Greater Poland'])->id,
        'company_id' => Company::factory()->create(['name' => 'Firma Klasztorna'])->id,
        'published_at' => now(),
        'phone' => '+48 661 231 623',
        'contact_hours' => '10:00–20:00, także SMS',
        'description' => '<p>Polodowcowe Jezioro Dobre.</p><script>alert(1)</script>',
        'fishing_license_required' => null,
        'rods_included' => null,
        'no_kill' => null,
        'campfires_banned' => null,
    ], $attributes));
    $position->update(['name' => '1', 'max_anglers' => 2]);

    return $fishery->fresh();
}

function fisheryPage(string $locale = 'pl'): TestResponse
{
    return test()->get("/{$locale}/wielkopolskie/klasztorne");
}

test('both tabs are in one document, the first one active, with anchors in the page language', function () {
    pageFishery();

    fisheryPage()
        ->assertOk()
        ->assertSee('id="mapa-i-terminy"', escape: false)
        ->assertSee('id="szczegoly"', escape: false)
        ->assertSee('href="#szczegoly"', escape: false)
        ->assertSee('Mapa i terminy')
        ->assertSee('Szczegóły')
        ->assertSee('Łowisko w sieci');

    fisheryPage('en')
        ->assertOk()
        ->assertSee('id="details"', escape: false)
        ->assertSee('id="map-and-dates"', escape: false);
});

test('the header shows the name, the water, the state and the company running the fishery', function () {
    pageFishery();

    fisheryPage()->assertSee('Klasztorne')->assertSee('wielkopolskie')->assertSee('prowadzi: Firma Klasztorna');
});

test('the box shows the price from, the phone with a call link and the contact hours', function () {
    $fishery = pageFishery();
    StayFixtures::rate($fishery, 130.00);

    fisheryPage()
        ->assertSee('od 130')
        ->assertSee('/ os. / doba')
        ->assertSee('Rezerwacje telefonicznie')
        ->assertSee('href="tel:+48661231623"', escape: false)
        ->assertSee('10:00–20:00, także SMS');
});

test('without a rate the box says the price list is in preparation', function () {
    pageFishery();

    fisheryPage()->assertSee('Cennik w przygotowaniu')->assertDontSee('/ os. / doba');
});

test('without a phone the box shows the e-mail and no call button, or nothing at all', function () {
    pageFishery(['phone' => null, 'email' => 'kontakt@klasztorne.pl']);

    fisheryPage()
        ->assertSee('mailto:kontakt@klasztorne.pl', escape: false)
        ->assertDontSee('href="tel:', escape: false)
        ->assertDontSee('Rezerwacje telefonicznie');

    Fishery::query()->update(['email' => null]);

    // `mailto:` portalu Fisherya zostaje w stopce — sprawdzamy adres ŁOWISKA.
    fisheryPage()->assertDontSee('mailto:kontakt@klasztorne.pl', escape: false)->assertDontSee('href="tel:', escape: false);
});

test('website and facebook links open in a new tab with noopener, and only when filled', function () {
    pageFishery(['website_url' => 'https://lowiskoklasztorne.pl', 'facebook_url' => 'https://www.facebook.com/klasztorne']);

    // „Łowisko w sieci": adres w Fisherya, potem strona WWW, potem Facebook; box — te same dwa linki.
    fisheryPage()->assertSeeInOrder(['Adres w Fisherya', 'Strona łowiska', 'lowiskoklasztorne.pl ↗', 'Facebook', 'facebook.com/klasztorne ↗']);

    expect(substr_count(fisheryPage()->getContent(), 'href="https://lowiskoklasztorne.pl"'))->toBe(3)
        ->and(substr_count(fisheryPage()->getContent(), 'href="https://www.facebook.com/klasztorne"'))->toBe(3);

    fisheryPage()
        ->assertSee('href="https://lowiskoklasztorne.pl" target="_blank" rel="noopener"', escape: false)
        ->assertSee('href="https://www.facebook.com/klasztorne" target="_blank" rel="noopener"', escape: false)
        ->assertSee('lowiskoklasztorne.pl ↗');

    Fishery::query()->update(['website_url' => null, 'facebook_url' => null]);

    fisheryPage()->assertDontSee('target="_blank"', escape: false);
});

test('angler rules not specified are not shown, and a no is shown as a no', function () {
    pageFishery();

    fisheryPage()->assertDontSee('Zanim przyjedziesz')->assertDontSee('Karta wędkarska');

    Fishery::query()->update(['fishing_license_required' => true, 'no_kill' => false, 'rods_included' => 2]);

    fisheryPage()
        ->assertSee('Zanim przyjedziesz')
        ->assertSee('Karta wędkarska')
        ->assertSee('wymagana')
        ->assertSee('można zabrać')
        ->assertDontSee('Ogniska');
});

test('empty water fields and records are not shown as empty labels', function () {
    pageFishery(['avg_depth' => null, 'max_depth' => null, 'records' => null, 'dominant_fish_id' => null]);

    fisheryPage()
        ->assertSee('Powierzchnia')
        ->assertDontSee('Średnia głębokość')
        ->assertDontSee('Rekordy łowiska')
        ->assertDontSee('<dt class="text-[10.5px] font-semibold uppercase tracking-[.09em] text-faint">Ryby</dt>', escape: false);
});

test('the positions are only those for sale, in natural label order, with groups, capacity and filterable features', function () {
    $fishery = pageFishery();
    $west = PositionGroup::factory()->create(['fishery_id' => $fishery->id, 'name' => 'Brzeg zachodni', 'description' => '<p>dojazd od ul. Kostrzyńskiej</p>']);
    $hidden = PositionGroup::factory()->create(['fishery_id' => $fishery->id, 'name' => 'Grupa Wycofanych', 'description' => 'opis niewidoczny']);
    $jetty = PositionAttribute::factory()->create(['name' => 'Pomost', 'is_filterable' => true]);
    $secret = PositionAttribute::factory()->create(['name' => 'Cecha Wewnętrzna', 'is_filterable' => false]);

    $ten = Position::factory()->create(['fishery_id' => $fishery->id, 'name' => '10', 'max_anglers' => 1, 'status' => PositionStatus::Available]);
    $two = Position::factory()->create(['fishery_id' => $fishery->id, 'name' => '2', 'max_anglers' => 3, 'status' => PositionStatus::Available]);
    $withdrawn = Position::factory()->create(['fishery_id' => $fishery->id, 'name' => '99', 'status' => PositionStatus::Withdrawn]);
    $two->groups()->attach($west);
    $withdrawn->groups()->attach($hidden);
    PositionAttributeValue::factory()->create(['position_id' => $two->id, 'position_attribute_id' => $jetty->id, 'value_flag' => true]);
    PositionAttributeValue::factory()->create(['position_id' => $two->id, 'position_attribute_id' => $secret->id, 'value_flag' => true]);

    fisheryPage()
        ->assertSeeInOrder(['St. 1', 'St. 2', 'St. 10'])
        ->assertSee('Brzeg zachodni · do 3 łowiących · pomost')
        ->assertSee('do 1 łowiącego')
        ->assertDontSee('St. 99')
        ->assertDontSee('Cecha Wewnętrzna')
        ->assertSee('Brzeg zachodni</b> · st. 2', escape: false)
        ->assertSee('<p>dojazd od ul. Kostrzyńskiej</p>', escape: false)
        ->assertDontSee('&lt;p&gt;dojazd', escape: false)
        ->assertDontSee('opis niewidoczny');
});

test('amenities and directions are listed, the short address is right under the domain', function () {
    $fishery = pageFishery(['directions' => '<p>Od ul. Klasztornej</p>']);
    $fishery->conveniences()->attach(Convenience::factory()->create(['name' => 'Sanitariat Testowy']));

    fisheryPage()
        ->assertSee('Sanitariat Testowy')
        ->assertSee('Od ul. Klasztornej')
        ->assertSee(preg_replace('#^https?://#', '', url('klasztorne')));
});

test('the description is sanitised and feeds the meta description', function () {
    pageFishery();

    fisheryPage()
        ->assertSee('Polodowcowe Jezioro Dobre.')
        ->assertDontSee('<script>alert(1)</script>', escape: false)
        ->assertSee('<meta name="description" content="Polodowcowe Jezioro Dobre.', escape: false)
        ->assertSee('<link rel="canonical" href="'.url('pl/wielkopolskie/klasztorne').'">', escape: false)
        ->assertSee('hreflang="en" href="'.url('en/wielkopolskie/klasztorne').'"', escape: false);
});

test('the photo count label shows the number of gallery photos, and disappears at zero', function () {
    pageFishery(['gallery_images' => ['a.jpg', 'b.jpg', 'c.jpg']]);
    fisheryPage()->assertSee('Wszystkie zdjęcia · 3');

    Fishery::query()->update(['gallery_images' => null]);
    fisheryPage()->assertDontSee('Wszystkie zdjęcia');
});

test('the sticky call bar is there only with a phone', function () {
    pageFishery();
    fisheryPage()->assertSee('Zadzwoń · +48 661 231 623');

    Fishery::query()->update(['phone' => null]);
    fisheryPage()->assertDontSee('Zadzwoń ·');
});

test('the home page card shows the price from', function () {
    $fishery = pageFishery();
    StayFixtures::rate($fishery, 70.00);

    $this->get('/pl')->assertOk()->assertSee('od 70')->assertSee('/ os. / doba');
});
