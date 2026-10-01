<?php

namespace Tests\Feature;

use App\Models\Fishery;
use App\Models\State;
use App\Services\PortalRoutes;

/**
 * Schemat adresów portalu — zadanie 031, ADR-021 (opcja B).
 *
 * ⚠️ Krótki adres łowiska stoi wprost pod domeną i obsługuje go `Route::fallback()`. Testy panelu
 * i logowania w tym pliku pilnują, że fallback NIE przejmuje tras pakietów (Filament, Breeze).
 */
function portalFishery(array $attributes = []): Fishery
{
    $state = State::factory()->create(['name' => 'Greater Poland']);

    return Fishery::factory()->published()->create(array_merge([
        'name' => 'Klasztorne',
        'state_id' => $state->id,
    ], $attributes))->fresh(['state']);
}

test('the root redirects to Polish by default', function () {
    $this->get('/')->assertRedirect('/pl')->assertStatus(302);
});

// ⚠️ Osobne testy: nagłówki i cookie ustawione w teście przechodzą na KAŻDE kolejne żądanie.
test('the root follows the language cookie first', function () {
    $this->withCookie('locale', 'en')
        ->withHeader('Accept-Language', 'pl')
        ->get('/')
        ->assertRedirect('/en');
});

test('without a cookie the root follows the browser language', function () {
    $this->withHeader('Accept-Language', 'en-GB,en;q=0.9')->get('/')->assertRedirect('/en');
});

test('a browser language the portal does not have falls back to Polish', function () {
    $this->withHeader('Accept-Language', 'de-DE,de;q=0.9')->get('/')->assertRedirect('/pl');
});

test('every static page answers in both languages under its own address', function () {
    foreach (PortalRoutes::PAGES as $page => $slugs) {
        foreach ($slugs as $locale => $slug) {
            $this->get("/{$locale}/{$slug}")->assertOk();
            expect(PortalRoutes::pageUrl($page, $locale))->toBe(url("{$locale}/{$slug}"));
        }
    }
});

test('the language switch leads to the counterpart of the current page', function () {
    $this->get('/pl/dla-lowisk')
        ->assertOk()
        ->assertSee('href="'.url('en/for-fisheries').'"', escape: false);

    $this->get('/en/legal/cookies')
        ->assertOk()
        ->assertSee('href="'.url('pl/dokumenty-prawne/pliki-cookie').'"', escape: false);
});

test('a page sets the interface language from its prefix and remembers it in a cookie', function () {
    $this->get('/en/how-it-works')
        ->assertOk()
        ->assertSee('How it works')
        ->assertCookie('locale', 'en');

    $this->get('/pl/jak-to-dziala')->assertOk()->assertSee('Jak to działa');
});

test('a fishery answers under its canonical address in both languages', function () {
    $fishery = portalFishery();

    $this->get('/pl/wielkopolskie/klasztorne')->assertOk()->assertSee('Klasztorne');
    $this->get('/en/wielkopolskie/klasztorne')->assertOk();
    expect(PortalRoutes::fisheryUrl($fishery, 'pl'))->toBe(url('pl/wielkopolskie/klasztorne'));
});

test('a wrong state segment redirects permanently to the canonical address', function () {
    portalFishery();

    $this->get('/pl/slaskie/klasztorne')->assertStatus(301)->assertRedirect('/pl/wielkopolskie/klasztorne');
});

test('an unpublished or unknown fishery is a portal 404', function () {
    portalFishery(['published_at' => null]);

    $this->get('/pl/wielkopolskie/klasztorne')->assertNotFound()->assertSee('404');
    $this->get('/pl/wielkopolskie/nieistniejace')->assertNotFound();
    $this->get('/klasztorne')->assertNotFound();
});

test('a region address redirects to the home page for now', function () {
    $this->get('/pl/wielkopolskie')->assertStatus(302)->assertRedirect('/pl');
});

test('the short address right under the domain redirects to the canonical address in the visitor language', function () {
    portalFishery();

    $this->get('/klasztorne')->assertStatus(302)->assertRedirect('/pl/wielkopolskie/klasztorne');
    $this->withCookie('locale', 'en')->get('/klasztorne')->assertRedirect('/en/wielkopolskie/klasztorne');
    $this->withHeader('Accept-Language', 'en')->get('/klasztorne')->assertRedirect('/en/wielkopolskie/klasztorne');
});

test('a short address typed in capitals is normalised to lowercase', function () {
    $this->get('/Klasztorne')->assertStatus(301)->assertRedirect('/klasztorne');
});

test('the portal 404 lists the published fisheries', function () {
    portalFishery();

    $this->get('/nie/ma/takiej/strony')->assertNotFound()->assertSee('Klasztorne');
    $this->get('/wp-login.php')->assertNotFound();
});

test('the short-address fallback does not swallow the panels, login or Breeze routes', function () {
    portalFishery();

    $this->get('/admin/login')->assertOk();
    $this->get('/owner/login')->assertOk();
    $this->get('/login')->assertOk();
    $this->get('/up')->assertOk();
});

test('the Breeze dashboard and welcome page are gone', function () {
    $this->get('/dashboard')->assertNotFound();
});

/**
 * ⚠️ Ten sam adres niesie pełną stronę i fragment kalendarza (ADR-022) — bez `Vary` przeglądarka przy „wstecz"
 * potrafi podać z cache sam fragment jako stronę (038).
 */
test('the fishery page and its calendar fragment both vary on the fragment header', function () {
    portalFishery();

    $this->get('/pl/wielkopolskie/klasztorne')->assertOk()->assertHeader('Vary', 'X-Portal-Fragment');
    $this->get('/pl/wielkopolskie/klasztorne', ['X-Portal-Fragment' => 'calendar'])
        ->assertOk()
        ->assertHeader('Vary', 'X-Portal-Fragment')
        ->assertDontSee('<html', escape: false);
});

/**
 * Brakujący plik albo żądanie nie od przeglądarki nie renderuje listy łowisk — lista to zapytania i warianty
 * zdjęć na każde trafienie bota czy zepsuty adres obrazka (038).
 */
test('a missing file or a non-HTML request gets a light 404 without the fishery list', function () {
    portalFishery();

    $this->get('/storage/1/conversions/zdjecie-w960.webp')->assertNotFound()->assertDontSee('Klasztorne');
    $this->get('/brak.png')->assertNotFound()->assertDontSee('Klasztorne');
    $this->get('/nie/ma/takiej/strony', ['Accept' => 'application/json'])->assertNotFound()->assertDontSee('Klasztorne');
    $this->get('/nie/ma/takiej/strony', ['Accept' => 'text/html'])->assertNotFound()->assertSee('Klasztorne');
});
