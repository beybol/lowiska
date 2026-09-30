<?php

namespace Tests\Feature;

use App\Enums\PositionStatus;
use App\Models\Fishery;
use App\Models\FisheryType;
use App\Models\Position;
use App\Models\State;
use App\Models\User;
use App\Services\OwnerRoleProvisioner;

/**
 * Strona główna, landing, strony informacyjne, favicon i Umami portalu — zadanie 031.
 *
 * ⚠️ Nazwy łowisk ustawione WPROST (`panel-wlasciciela.md` §5): losowe nazwy z fabryki bywają
 * podciągami tekstów strony.
 */
test('the home page lists only published fisheries, alphabetically with Polish collation', function () {
    $state = State::factory()->create(['name' => 'Greater Poland']);

    foreach (['Łopienno', 'Zalew Testowy', 'Klasztorne', 'Lipno'] as $name) {
        Fishery::factory()->published()->create(['name' => $name, 'state_id' => $state->id]);
    }
    Fishery::factory()->create(['name' => 'Ukryte Nieopublikowane', 'state_id' => $state->id]);

    $this->get('/pl')
        ->assertOk()
        ->assertSeeInOrder(['Klasztorne', 'Lipno', 'Łopienno', 'Zalew Testowy'])
        ->assertDontSee('Ukryte Nieopublikowane')
        ->assertSee(url('pl/jak-ukladamy-liste'), escape: false);
});

test('a fishery card shows the water, the state, positions for sale and no-kill', function () {
    $state = State::factory()->create(['name' => 'Greater Poland']);
    $type = FisheryType::factory()->create(['name' => 'Jezioro rynnowe']);
    $fishery = Fishery::factory()->published()->create([
        'name' => 'Łopienno',
        'state_id' => $state->id,
        'area' => 16,
        'no_kill' => true,
    ]);
    $fishery->fisheryTypes()->attach($type);
    Position::factory()->count(2)->create(['fishery_id' => $fishery->id, 'status' => PositionStatus::Available]);
    Position::factory()->create(['fishery_id' => $fishery->id, 'status' => PositionStatus::Withdrawn]);

    $this->get('/pl')
        ->assertOk()
        ->assertSee('Jezioro rynnowe 16 ha · wielkopolskie · 2 stanowiska')
        ->assertSee('no-kill')
        ->assertSee(url('pl/wielkopolskie/lopienno'), escape: false);
});

test('no-kill not specified is not shown as a no-kill badge', function () {
    $state = State::factory()->create();
    Fishery::factory()->published()->create(['name' => 'Klasztorne', 'state_id' => $state->id, 'no_kill' => null]);

    $this->get('/pl')->assertOk()->assertDontSee('no-kill');
});

test('the landing for fisheries names the first fisheries and offers e-mail without a form', function () {
    $state = State::factory()->create();
    Fishery::factory()->published()->create(['name' => 'Klasztorne', 'state_id' => $state->id]);
    config(['portal.contact_email' => 'kontakt@fisherya.test', 'portal.contact_phone' => null]);

    $this->get('/pl/dla-lowisk')
        ->assertOk()
        ->assertSee('Klasztorne')
        ->assertSee('mailto:kontakt@fisherya.test', escape: false)
        ->assertDontSee('<form', escape: false)
        ->assertDontSee('albo zadzwoń');

    config(['portal.contact_phone' => '+48 600 100 200']);

    $this->get('/pl/dla-lowisk')->assertSee('tel:+48600100200', escape: false);
});

test('the header and footer link the portal pages and the fishery panel', function () {
    $this->get('/pl')
        ->assertOk()
        ->assertSee(url('pl/jak-to-dziala'), escape: false)
        ->assertSee(url('pl/dla-lowisk'), escape: false)
        ->assertSee(url('pl/dokumenty-prawne/zglos-nielegalna-tresc'), escape: false)
        ->assertSee(url('owner'), escape: false)
        ->assertSee('Stroną umowy jest łowisko, Fisherya pośredniczy.');
});

test('the cookies page lists the technical cookies the portal sets', function () {
    $this->get('/pl/dokumenty-prawne/pliki-cookie')
        ->assertOk()
        ->assertSee(config('session.cookie'))
        ->assertSee('XSRF-TOKEN')
        ->assertSee('locale');
});

test('the favicon is linked in the portal, both panels and the Breeze views', function () {
    $this->get('/pl')->assertSee(asset('favicon.svg'), escape: false);
    $this->get('/admin/login')->assertSee(asset('favicon.svg'), escape: false);
    $this->get('/owner/login')->assertSee(asset('favicon.svg'), escape: false);
    $this->get('/login')->assertSee(asset('favicon.svg'), escape: false);
});

test('umami loads in the portal only when configured, and never in the panels or Breeze views', function () {
    config(['services.umami.script_url' => null, 'services.umami.website_id' => null]);
    $this->get('/pl')->assertDontSee('data-website-id', escape: false);

    config([
        'services.umami.script_url' => 'https://umami.example.test/script.js',
        'services.umami.website_id' => 'site-123',
    ]);

    $this->get('/pl')
        ->assertSee('<script defer src="https://umami.example.test/script.js" data-website-id="site-123"></script>', escape: false);
    $this->get('/pl/dla-lowisk')->assertSee('data-website-id="site-123"', escape: false);
    $this->get('/nie/ma')->assertSee('data-website-id="site-123"', escape: false);

    $this->get('/admin/login')->assertDontSee('umami.example.test', escape: false);
    $this->get('/owner/login')->assertDontSee('umami.example.test', escape: false);
    $this->get('/login')->assertDontSee('umami.example.test', escape: false);

    $owner = User::factory()->create();
    OwnerRoleProvisioner::addOwnerRole($owner);
    $this->actingAs($owner)->get('/owner/fisheries')->assertDontSee('umami.example.test', escape: false);
});
