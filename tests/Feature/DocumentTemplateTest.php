<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Filament\Resources\DocumentTemplateResource;
use App\Filament\Resources\DocumentTemplateResource\Pages\CreateDocumentTemplate;
use App\Filament\Resources\FisheryResource;
use App\Filament\Resources\FisheryResource\Pages\PreviewDocumentTemplate;
use App\Models\DocumentTemplate;
use App\Models\User;
use App\Services\OwnerRoleProvisioner;
use Database\Seeders\DocumentTemplateSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\StayFixtures;

/**
 * Szablony dokumentów — admin tworzy, właściciel tylko czyta (zadanie 021).
 */
test('the admin creates a template and goes back to the list', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->createSuperAdmin());

    Livewire::test(CreateDocumentTemplate::class)
        ->fillForm([
            'type' => DocumentType::Terms->value,
            'name' => 'Regulamin — lowisko karpiowe',
            'content' => '<p>Tresc</p>',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(DocumentTemplateResource::getUrl('index'));

    expect(DocumentTemplate::query()->where('name', 'Regulamin — lowisko karpiowe')->exists())->toBeTrue();
});

test('the owner can not manage templates', function () {
    [, , $owner] = StayFixtures::fisheryWithPosition();
    $template = DocumentTemplate::factory()->create();

    expect($owner->can('viewAny', DocumentTemplate::class))->toBeTrue()
        ->and($owner->can('view', $template))->toBeTrue()
        ->and($owner->can('create', DocumentTemplate::class))->toBeFalse()
        ->and($owner->can('update', $template))->toBeFalse()
        ->and($owner->can('delete', $template))->toBeFalse();

    $this->actingAs($owner);

    $this->get(DocumentTemplateResource::getUrl('index', panel: 'admin'))->assertForbidden();
});

test('an existing owner role gets read access to templates without losing anything else', function () {
    [, , $owner] = StayFixtures::fisheryWithPosition();
    $role = $owner->roles()->where('name', 'owner')->firstOrFail();
    $role->revokePermissionTo(['view_any:document_template', 'view:document_template']);
    $before = $role->permissions()->count();

    OwnerRoleProvisioner::grantDocumentTemplateReading();

    expect($role->fresh()->permissions()->count())->toBe($before + 2)
        ->and($owner->fresh()->can('viewAny', DocumentTemplate::class))->toBeTrue();
});

test('the owner previews any template read-only, the matching type first', function () {
    Filament::setCurrentPanel('owner');
    [$fishery, , $owner] = StayFixtures::fisheryWithPosition();
    $privacy = DocumentTemplate::factory()->create(['type' => DocumentType::PrivacyPolicy, 'name' => 'A polityka']);
    $terms = DocumentTemplate::factory()->create(['type' => DocumentType::Terms, 'name' => 'Z regulamin', 'content' => '<p>Tresc regulaminu</p>']);
    $this->actingAs($owner);

    $page = Livewire::withQueryParams(['type' => DocumentType::Terms->value])
        ->test(PreviewDocumentTemplate::class, ['record' => $fishery->getRouteKey()])
        ->assertSet('template', $terms->id)
        ->assertSee('Tresc regulaminu');

    $page->set('template', $privacy->id)->assertSee($privacy->name);

    $this->get(FisheryResource::getUrl('document-template-preview', ['record' => $fishery], panel: 'owner'))->assertSuccessful();
});

test('the preview strips scripts from the template content', function () {
    Filament::setCurrentPanel('owner');
    [$fishery, , $owner] = StayFixtures::fisheryWithPosition();
    DocumentTemplate::factory()->create(['content' => '<p>Bezpieczna</p><script>alert(1)</script>']);
    $this->actingAs($owner);

    Livewire::test(PreviewDocumentTemplate::class, ['record' => $fishery->getRouteKey()])
        ->assertSee('Bezpieczna')
        ->assertDontSee('<script>alert(1)</script>', escape: false);
});

test('the preview of another fishery is not found', function () {
    Filament::setCurrentPanel('owner');
    [, , $attacker] = StayFixtures::fisheryWithPosition();
    [$theirs] = StayFixtures::fisheryWithPosition();
    $this->actingAs($attacker);

    $this->get(FisheryResource::getUrl('document-template-preview', ['record' => $theirs], panel: 'owner'))->assertNotFound();
});

test('a user without template access can not open the preview', function () {
    Filament::setCurrentPanel('owner');
    [$fishery, , $owner] = StayFixtures::fisheryWithPosition();
    $owner->roles()->firstOrFail()->revokePermissionTo('view_any:document_template');
    $this->actingAs(User::query()->findOrFail($owner->id));

    $this->get(FisheryResource::getUrl('document-template-preview', ['record' => $fishery], panel: 'owner'))->assertForbidden();
});

test('the working terms template is seeded once and marked for legal review', function () {
    $this->seed(DocumentTemplateSeeder::class);
    $this->seed(DocumentTemplateSeeder::class);

    $template = DocumentTemplate::query()->where('name', DocumentTemplateSeeder::WORKING_TERMS_NAME)->sole();

    expect($template->type)->toBe(DocumentType::Terms)
        ->and($template->content)->toContain('WERYFIKACJI PRAWNEJ')
        ->and(DocumentTemplate::query()->where('type', DocumentType::PrivacyPolicy->value)->exists())->toBeFalse();
});
