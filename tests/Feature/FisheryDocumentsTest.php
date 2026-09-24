<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Filament\Resources\FisheryResource;
use App\Filament\Resources\FisheryResource\Pages\ManageDocuments;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\Fishery;
use App\Rules\DocumentEffectiveDateIsAhead;
use App\Rules\DocumentEffectiveDateIsFree;
use App\Services\FisheryDocuments;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\StayFixtures;

/**
 * Dokumenty łowiska — wersje regulaminu i polityki prywatności (zadanie 021, ADR-017).
 *
 * ⚠️ Czas jest zamrożony: „dziś" to 01.06.2026 09:00 w Warszawie. Stan wersji i nienaruszalność
 * liczą się od daty, więc bez zamrożenia pakiet zmieniałby wynik z dnia na dzień.
 */
beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-06-01 09:00', 'Europe/Warsaw'));
});

afterEach(function () {
    Date::setTestNow();
});

function documentOf(Fishery $fishery, string $effectiveFrom, array $state = []): Document
{
    return Document::factory()->create(array_merge([
        'fishery_id' => $fishery->id,
        'effective_from' => $effectiveFrom,
    ], $state));
}

test('the version in force is the latest one taking effect on or before today', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    $old = documentOf($fishery, '2026-01-01');
    $current = documentOf($fishery, '2026-06-01');
    $scheduled = documentOf($fishery, '2026-07-01');
    $privacy = documentOf($fishery, '2026-02-01', ['type' => DocumentType::PrivacyPolicy]);

    $documents = new FisheryDocuments($fishery->fresh());

    expect($documents->current(DocumentType::Terms)?->id)->toBe($current->id)
        ->and($documents->current(DocumentType::PrivacyPolicy)?->id)->toBe($privacy->id)
        ->and($documents->statusOf($old))->toBe(DocumentStatus::Archived)
        ->and($documents->statusOf($current))->toBe(DocumentStatus::Current)
        ->and($documents->statusOf($scheduled))->toBe(DocumentStatus::Scheduled);
});

test('today is counted in the fishery time zone', function () {
    // 01.06 03:00 w Warszawie to jeszcze 31.05 w Nowym Jorku (21:00).
    Date::setTestNow(CarbonImmutable::parse('2026-06-01 03:00', 'Europe/Warsaw'));
    [$fishery] = StayFixtures::fisheryWithPosition(['timezone' => 'America/New_York']);
    $june = documentOf($fishery, '2026-06-01');

    expect((new FisheryDocuments($fishery->fresh()))->current(DocumentType::Terms))->toBeNull()
        ->and((new FisheryDocuments($fishery->fresh()))->statusOf($june->fresh()))->toBe(DocumentStatus::Scheduled);
});

test('a version in force can not change its title, content or date — only its flags', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    $current = documentOf($fishery, '2026-05-01');

    $current->update(['required_at_registration' => true, 'required_at_purchase' => false]);

    expect($current->fresh()->required_at_registration)->toBeTrue();

    foreach (['title' => 'Nowy', 'content' => '<p>Nowa</p>', 'effective_from' => '2026-12-01'] as $field => $value) {
        expect(fn () => $current->fresh()->update([$field => $value]))->toThrow(ValidationException::class);
    }

    expect($current->fresh()->effective_from->toDateString())->toBe('2026-05-01');
});

test('a scheduled version can be edited and deleted, a version in force or archived can not be deleted', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    $archived = documentOf($fishery, '2026-01-01');
    $current = documentOf($fishery, '2026-05-01');
    $scheduled = documentOf($fishery, '2026-07-01');

    $scheduled->update(['title' => 'Poprawiony', 'effective_from' => '2026-07-15']);
    $scheduled->delete();

    expect($scheduled->fresh()->trashed())->toBeTrue()
        ->and(fn () => $current->fresh()->delete())->toThrow(ValidationException::class)
        ->and(fn () => $archived->fresh()->delete())->toThrow(ValidationException::class);
});

test('the effective date has to be tomorrow at the earliest, in the fishery time zone', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    $fails = fn (string $date): bool => Validator::make(['d' => $date], ['d' => [new DocumentEffectiveDateIsAhead($fishery)]])->fails();

    expect($fails('2026-06-01'))->toBeTrue()
        ->and($fails('2026-05-20'))->toBeTrue()
        ->and($fails('2026-06-02'))->toBeFalse();
});

test('two versions of one document can not take effect on the same day, a deleted one frees the day', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    $taken = documentOf($fishery, '2026-07-01');
    documentOf($fishery, '2026-07-10', ['type' => DocumentType::PrivacyPolicy]);

    $fails = fn (string $date, DocumentType $type, ?int $ignore = null): bool => Validator::make(
        ['d' => $date],
        ['d' => [new DocumentEffectiveDateIsFree($fishery->id, $type, $ignore)]],
    )->fails();

    expect($fails('2026-07-01', DocumentType::Terms))->toBeTrue()
        ->and($fails('2026-07-01', DocumentType::Terms, $taken->id))->toBeFalse()
        ->and($fails('2026-07-10', DocumentType::Terms))->toBeFalse()
        ->and($fails('2026-07-02', DocumentType::Terms))->toBeFalse();

    $taken->delete();

    expect($fails('2026-07-01', DocumentType::Terms))->toBeFalse();
});

/*
 * Ekran „Dokumenty".
 */

test('a new version starts as a copy of the version in force, dated today plus 14 days', function () {
    Filament::setCurrentPanel('owner');
    [$fishery, , $owner] = StayFixtures::fisheryWithPosition();
    $current = documentOf($fishery, '2026-05-01', ['title' => 'Regulamin 2026', 'content' => '<p>Tresc obowiazujaca</p>', 'required_at_registration' => true]);
    $this->actingAs($owner);

    Livewire::test(ManageDocuments::class, ['record' => $fishery->getRouteKey()])
        ->mountAction(TestAction::make('createVersion')->table())
        ->assertSchemaStateSet([
            'source' => 'copy:'.$current->id,
            'title' => 'Regulamin 2026',
            'content' => '<p>Tresc obowiazujaca</p>',
            'effective_from' => '2026-06-15',
            'required_at_registration' => true,
        ])
        ->setActionData(['title' => 'Regulamin 2026 — lato'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $new = $fishery->documents()->where('title', 'Regulamin 2026 — lato')->firstOrFail();

    expect($new->effective_from->toDateString())->toBe('2026-06-15')
        ->and($new->content)->toBe('<p>Tresc obowiazujaca</p>')
        ->and($new->required_at_registration)->toBeTrue()
        ->and($current->fresh()->title)->toBe('Regulamin 2026');
});

test('a new version can start from any template, also one of another document type', function () {
    Filament::setCurrentPanel('owner');
    [$fishery, , $owner] = StayFixtures::fisheryWithPosition();
    $privacyTemplate = DocumentTemplate::factory()->create([
        'type' => DocumentType::PrivacyPolicy, 'name' => 'Polityka wzor', 'content' => '<p>Z szablonu</p>',
    ]);
    $this->actingAs($owner);

    Livewire::test(ManageDocuments::class, ['record' => $fishery->getRouteKey()])
        ->callAction(TestAction::make('createVersion')->table(), data: [
            'source' => 'template:'.$privacyTemplate->id,
            'type' => DocumentType::Terms->value,
            'title' => 'Regulamin z szablonu',
            'content' => '<p>Z szablonu</p>',
            'effective_from' => '2026-06-20',
        ])
        ->assertHasNoActionErrors();

    $privacyTemplate->update(['content' => '<p>Zmieniony szablon</p>']);
    $privacyTemplate->delete();

    expect($fishery->documents()->where('title', 'Regulamin z szablonu')->value('content'))->toBe('<p>Z szablonu</p>');
});

test('the form refuses a date of today and a date already taken', function () {
    Filament::setCurrentPanel('owner');
    [$fishery, , $owner] = StayFixtures::fisheryWithPosition();
    documentOf($fishery, '2026-07-01');
    $this->actingAs($owner);

    foreach (['2026-06-01', '2026-07-01'] as $date) {
        Livewire::test(ManageDocuments::class, ['record' => $fishery->getRouteKey()])->callAction(TestAction::make('createVersion')->table(), data: [
            'type' => DocumentType::Terms->value,
            'title' => 'Proba',
            'content' => '<p>Proba</p>',
            'effective_from' => $date,
        ])->assertHasActionErrors(['effective_from']);
    }

    expect($fishery->documents()->count())->toBe(1);
});

test('editing a version in force from the page saves the flags and leaves the content untouched', function () {
    Filament::setCurrentPanel('owner');
    [$fishery, , $owner] = StayFixtures::fisheryWithPosition();
    $current = documentOf($fishery, '2026-05-01', ['content' => '<p>Nienaruszalna</p>', 'required_at_registration' => false]);
    $this->actingAs($owner);

    Livewire::test(ManageDocuments::class, ['record' => $fishery->getRouteKey()])
        ->callAction(TestAction::make('editVersion')->table($current), data: [
            'required_at_registration' => true,
            'content' => '<p>Podmieniona</p>',
            'effective_from' => '2026-12-01',
        ])
        ->assertHasNoActionErrors();

    expect($current->fresh()->required_at_registration)->toBeTrue()
        ->and($current->fresh()->content)->toBe('<p>Nienaruszalna</p>')
        ->and($current->fresh()->effective_from->toDateString())->toBe('2026-05-01');
});

test('the delete action is offered only for a scheduled version', function () {
    Filament::setCurrentPanel('owner');
    [$fishery, , $owner] = StayFixtures::fisheryWithPosition();
    $current = documentOf($fishery, '2026-05-01');
    $scheduled = documentOf($fishery, '2026-07-01');
    $this->actingAs($owner);

    Livewire::test(ManageDocuments::class, ['record' => $fishery->getRouteKey()])
        ->assertActionHidden(TestAction::make('deleteVersion')->table($current))
        ->assertActionVisible(TestAction::make('deleteVersion')->table($scheduled))
        ->callAction(TestAction::make('deleteVersion')->table($scheduled));

    expect($scheduled->fresh()->trashed())->toBeTrue();
});

test('a fishery without terms in force says so, without judging anything', function () {
    Filament::setCurrentPanel('owner');
    [$fishery, , $owner] = StayFixtures::fisheryWithPosition();
    documentOf($fishery, '2026-07-01');
    $this->actingAs($owner);

    Livewire::test(ManageDocuments::class, ['record' => $fishery->getRouteKey()])
        ->assertSee(__('This fishery has no :document in force.', ['document' => mb_strtolower(DocumentType::Terms->label())]));
});

test('the admin works on fishery documents by the same rules, including the lock', function () {
    Filament::setCurrentPanel('admin');
    [$fishery] = StayFixtures::fisheryWithPosition();
    $current = documentOf($fishery, '2026-05-01', ['content' => '<p>Nienaruszalna</p>']);
    $this->actingAs($this->createSuperAdmin());

    Livewire::test(ManageDocuments::class, ['record' => $fishery->getRouteKey()])
        ->callAction(TestAction::make('createVersion')->table(), data: [
            'type' => DocumentType::Terms->value,
            'title' => 'Od admina',
            'content' => '<p>Od admina</p>',
            'effective_from' => '2026-06-01',
        ])
        ->assertHasActionErrors(['effective_from']);

    Livewire::test(ManageDocuments::class, ['record' => $fishery->getRouteKey()])
        ->callAction(TestAction::make('editVersion')->table($current), data: ['content' => '<p>Admin</p>'])
        ->assertHasNoActionErrors();

    expect($current->fresh()->content)->toBe('<p>Nienaruszalna</p>');
});

test('an owner can not open the documents of another fishery', function () {
    Filament::setCurrentPanel('owner');
    [, , $attacker] = StayFixtures::fisheryWithPosition();
    [$theirs] = StayFixtures::fisheryWithPosition();
    documentOf($theirs, '2026-05-01');
    $this->actingAs($attacker);

    $this->get(FisheryResource::getUrl('documents', ['record' => $theirs], panel: 'owner'))
        ->assertNotFound();
});

test('saving a version is logged with its author', function () {
    [$fishery, , $owner] = StayFixtures::fisheryWithPosition();
    $this->actingAs($owner);

    $document = documentOf($fishery, '2026-07-01');

    $entry = Activity::query()->where('subject_type', $document->getMorphClass())->where('subject_id', $document->id)->first();

    expect($entry?->causer_id)->toBe($owner->id)
        ->and($entry?->attribute_changes['attributes']['title'] ?? null)->toBe($document->title);
});

/**
 * ⚠️ `Document` nie ma zasobu Filamenta, więc nie dostaje polityki — autoryzuje się przez łowisko
 * (`autoryzacja.md` §5). Polityka pytająca o `view_any:document` wywróciłaby `ShieldPermissionNamesTest`.
 */
test('the document model does not get a policy of its own', function () {
    expect(file_exists(base_path('app/Policies/DocumentPolicy.php')))->toBeFalse();
});
