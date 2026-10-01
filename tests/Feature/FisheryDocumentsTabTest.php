<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\PublicationIssue;
use App\Models\Company;
use App\Models\Document;
use App\Models\Fishery;
use App\Models\State;
use App\Services\FisheryPublicationReadiness;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Tests\Support\StayFixtures;

/**
 * Zakładka „Dokumenty" strony łowiska (zadanie 034, portal-v3 „Łowisko — Dokumenty").
 *
 * ⚠️ Czas zamrożony: pon 04.05.2026. Dla każdego rodzaju portal pokazuje WYŁĄCZNIE wersję obowiązującą
 * dziś — zaplanowanych i archiwalnych nie. Rodzaj bez takiej wersji nie ma sekcji (R1, R7, R9).
 */
beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-05-04 09:00', 'Europe/Warsaw'));
});

afterEach(function () {
    Date::setTestNow();
});

function documentedFishery(): Fishery
{
    [$fishery] = StayFixtures::fisheryWithPosition([
        'name' => 'Klasztorne',
        'state_id' => State::factory()->create(['name' => 'Greater Poland'])->id,
        'company_id' => Company::factory()->create()->id,
        'published_at' => now(),
    ]);

    return $fishery->fresh();
}

function documentVersion(Fishery $fishery, DocumentType $type, string $title, string $effectiveFrom, string $content = '<p>Treść.</p>'): Document
{
    return Document::factory()->create([
        'fishery_id' => $fishery->id,
        'type' => $type,
        'title' => $title,
        'effective_from' => $effectiveFrom,
        'content' => $content,
    ]);
}

function documentsPage(string $locale = 'pl'): string
{
    return test()->get("/{$locale}/wielkopolskie/klasztorne")->assertOk()->getContent();
}

test('each kind shows only its version in force today, with title, date and text', function () {
    $fishery = documentedFishery();
    documentVersion($fishery, DocumentType::Terms, 'Regulamin archiwalny', '2025-01-01', '<p>Stara treść regulaminu.</p>');
    documentVersion($fishery, DocumentType::Terms, 'Regulamin obowiązujący', '2026-03-01', '<p>Nowa treść regulaminu.</p>');
    documentVersion($fishery, DocumentType::Terms, 'Regulamin zaplanowany', '2026-09-01', '<p>Przyszła treść regulaminu.</p>');

    $html = documentsPage();

    expect($html)
        ->toContain('Regulamin obowiązujący')
        ->toContain('obowiązuje od 01.03.2026')
        ->toContain('Nowa treść regulaminu.')
        ->not->toContain('Regulamin archiwalny')
        ->not->toContain('Stara treść regulaminu.')
        ->not->toContain('Regulamin zaplanowany')
        ->not->toContain('Przyszła treść regulaminu.');
});

test('a version that enters into force today is the one in force', function () {
    $fishery = documentedFishery();
    documentVersion($fishery, DocumentType::Terms, 'Regulamin od wczoraj', '2026-05-03');
    documentVersion($fishery, DocumentType::Terms, 'Regulamin od dziś', '2026-05-04');

    expect(documentsPage())->toContain('Regulamin od dziś')->not->toContain('Regulamin od wczoraj');
});

test('the kinds come in order: terms, privacy policy, other', function () {
    $fishery = documentedFishery();
    documentVersion($fishery, DocumentType::Other, 'Zasady biwakowania', '2026-04-15');
    documentVersion($fishery, DocumentType::PrivacyPolicy, 'Polityka łowiska', '2026-03-01');
    documentVersion($fishery, DocumentType::Terms, 'Regulamin łowiska', '2026-03-01');

    $html = documentsPage();

    expect(strpos($html, 'Regulamin łowiska'))->toBeLessThan(strpos($html, 'Polityka łowiska'))
        ->and(strpos($html, 'Polityka łowiska'))->toBeLessThan(strpos($html, 'Zasady biwakowania'))
        ->and($html)->toContain('semibold">Regulamin</h2>')
        ->toContain('semibold">Polityka prywatności</h2>')
        ->toContain('semibold">Inne</h2>');
});

test('a kind without a version in force has no section', function () {
    $fishery = documentedFishery();
    documentVersion($fishery, DocumentType::Terms, 'Regulamin łowiska', '2026-03-01');
    // jedyna wersja polityki jest zaplanowana — w sensie portalu rodzaj nie ma nic obowiązującego
    documentVersion($fishery, DocumentType::PrivacyPolicy, 'Polityka przyszła', '2026-09-01');

    $html = documentsPage();

    expect($html)
        ->toContain('semibold">Regulamin</h2>')
        ->not->toContain('semibold">Polityka prywatności</h2>')
        ->not->toContain('semibold">Inne</h2>')
        ->not->toContain('Polityka przyszła');
});

test('a fishery with no document in force says so in the tab', function () {
    $fishery = documentedFishery();
    documentVersion($fishery, DocumentType::Terms, 'Regulamin przyszły', '2026-09-01');

    $html = documentsPage();

    expect($html)->toContain('Łowisko nie opublikowało dokumentów.')->not->toContain('Regulamin przyszły');
});

test('a fishery with no documents at all says so too', function () {
    documentedFishery();

    expect(documentsPage())->toContain('Łowisko nie opublikowało dokumentów.');
});

test('the document text is in the HTML but sanitised', function () {
    $fishery = documentedFishery();
    documentVersion($fishery, DocumentType::Terms, 'Regulamin łowiska', '2026-03-01', '<p>Zasady <strong>no-kill</strong>.</p><script>alert(1)</script>');

    $html = documentsPage();

    expect($html)->toContain('<strong>no-kill</strong>')->not->toContain('<script>alert(1)</script>');
});

test('documents of another fishery never appear', function () {
    $fishery = documentedFishery();
    documentVersion($fishery, DocumentType::Terms, 'Regulamin łowiska', '2026-03-01');
    $other = Fishery::factory()->create();
    documentVersion($other, DocumentType::Other, 'Cudzy dokument', '2026-03-01');

    expect(documentsPage())->not->toContain('Cudzy dokument');
});

test('the tab has a language dependent anchor and English headings', function () {
    $fishery = documentedFishery();
    documentVersion($fishery, DocumentType::Other, 'Camping rules', '2026-04-15');

    $pl = documentsPage('pl');
    $en = documentsPage('en');

    expect($pl)->toContain('id="dokumenty"')->toContain('href="#dokumenty"')
        ->and($en)->toContain('id="documents"')->toContain('href="#documents"')
        ->toContain('semibold">Other</h2>')
        ->toContain('in force from 15.04.2026');
});

test('the other kind alone does not make the terms requirement pass', function () {
    $fishery = documentedFishery();
    documentVersion($fishery, DocumentType::Other, 'Zasady biwakowania', '2026-04-15');

    $issues = (new FisheryPublicationReadiness($fishery->fresh()))->issues();

    expect($issues)->toContain(PublicationIssue::TermsMissing);
});
