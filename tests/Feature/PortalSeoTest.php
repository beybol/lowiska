<?php

namespace Tests\Feature;

use App\Models\Fishery;
use App\Models\State;

/**
 * SEO portalu — canonical, `hreflang`, `sitemap.xml`, `robots.txt` i `noindex` poza produkcją (zadanie 031).
 *
 * ⚠️ `robots.txt` to trasa Laravela, nie plik w `public/` — tylko tak zależy od środowiska.
 */
test('a page carries its canonical address and hreflang alternates', function () {
    $this->get('/pl/dla-lowisk')
        ->assertOk()
        ->assertSee('<link rel="canonical" href="'.url('pl/dla-lowisk').'">', escape: false)
        ->assertSee('<link rel="alternate" hreflang="en" href="'.url('en/for-fisheries').'">', escape: false)
        ->assertSee('<link rel="alternate" hreflang="x-default" href="'.url('pl/dla-lowisk').'">', escape: false);
});

test('the portal 404 has no canonical address', function () {
    $this->get('/nie/ma/takiej')->assertNotFound()->assertDontSee('rel="canonical"', escape: false);
});

test('outside production the portal is noindex and robots.txt disallows everything', function () {
    $this->get('/pl')->assertSee('<meta name="robots" content="noindex, nofollow">', escape: false);

    $this->get('/robots.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertSee('Disallow: /', escape: false)
        ->assertDontSee('Sitemap:');
});

test('in production robots.txt allows indexing and points at the sitemap, and pages are indexable', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->get('/robots.txt')
        ->assertOk()
        ->assertSee('Allow: /')
        ->assertSee('Sitemap: '.url('sitemap.xml'));

    $this->get('/pl')->assertDontSee('name="robots"', escape: false);
});

test('the sitemap lists the pages and the published fisheries in both languages with alternates', function () {
    $state = State::factory()->create(['name' => 'Greater Poland']);
    Fishery::factory()->published()->create(['name' => 'Klasztorne', 'state_id' => $state->id]);
    Fishery::factory()->create(['name' => 'Ukryte', 'state_id' => $state->id]);

    $xml = $this->get('/sitemap.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->getContent();

    expect($xml)->toStartWith('<?xml version="1.0" encoding="UTF-8"?>')
        ->toContain('<loc>'.url('pl').'</loc>')
        ->toContain('<loc>'.url('en/legal/report-content').'</loc>')
        ->toContain('<loc>'.url('pl/wielkopolskie/klasztorne').'</loc>')
        ->toContain('<loc>'.url('en/wielkopolskie/klasztorne').'</loc>')
        ->toContain('hreflang="en" href="'.url('en/wielkopolskie/klasztorne').'"')
        ->not->toContain('ukryte');

    expect(simplexml_load_string($xml))->not->toBeFalse();
});
