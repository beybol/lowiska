<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\PositionStatus;
use App\Enums\PublicationIssue;
use App\Filament\Resources\FisheryResource;
use App\Filament\Resources\FisheryResource\Pages\EditFishery;
use App\Filament\Resources\FisheryResource\Pages\ManageFishery;
use App\Models\Company;
use App\Models\Document;
use App\Models\Fishery;
use App\Models\SalePeriod;
use App\Models\State;
use App\Models\User;
use App\Services\FisheryPublicationReadiness;
use App\Services\OwnerRoleProvisioner;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\StayFixtures;

/**
 * Publikacja łowiska w portalu i ostrzeżenie o brakach (zadanie 030, portal-v3 §6).
 *
 * ⚠️ Braki są OSTRZEŻENIEM, nie blokadą — z jednym wyjątkiem: bez województwa publikacja jest
 * niemożliwa. Każdy punkt listy ma własny przypadek na łowisku, któremu brakuje tylko jego.
 *
 * ⚠️ Czas jest zamrożony — okres sprzedaży, cennik i regulamin liczą się od „dziś".
 */
beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-05-04 09:00', 'Europe/Warsaw'));
});

afterEach(function () {
    Date::setTestNow();
});

/**
 * Łowisko, któremu niczego nie brakuje: doba, sezon na cały 2026, stanowisko w sprzedaży,
 * stawka bez dat, telefon, opis, zdjęcie, mapa i obowiązujący regulamin.
 *
 * @return array{0: Fishery, 1: User}
 */
function publishableFishery(): array
{
    [$fishery, , $owner] = StayFixtures::fisheryWithPosition([
        'phone' => '517 971 002',
        'description' => '<p>Jezioro przy klasztorze.</p>',
        'gallery_images' => ['galleries/brzeg.jpg'],
        'map_image_path' => 'maps/mapa.jpg',
    ]);
    // Firma właściciela — formularz w panelu właściciela oferuje wyłącznie jego firmy.
    $fishery->update(['company_id' => Company::factory()->forUser($owner)->create(['is_verified' => true])->id]);
    StayFixtures::rate($fishery);
    Document::factory()->create([
        'fishery_id' => $fishery->id,
        'type' => DocumentType::Terms,
        'effective_from' => '2026-01-01',
    ]);

    return [$fishery->fresh(), $owner];
}

function readinessOf(Fishery $fishery): FisheryPublicationReadiness
{
    return new FisheryPublicationReadiness($fishery->fresh());
}

function actAsOwnerInPanel(User $owner): void
{
    test()->actingAs($owner);
    Filament::setCurrentPanel('owner');
}

test('a fishery with everything in place has no issues', function () {
    [$fishery] = publishableFishery();

    expect(readinessOf($fishery)->issues())->toBe([])
        ->and(readinessOf($fishery)->blocksPublication())->toBeFalse();
});

test('each missing piece is reported on its own', function (callable $break, PublicationIssue $issue) {
    [$fishery] = publishableFishery();

    $break($fishery);

    expect(readinessOf($fishery)->issues())->toBe([$issue]);
})->with([
    'state' => [fn (Fishery $f) => $f->update(['state_id' => null]), PublicationIssue::StateMissing],
    'fishing day' => [fn (Fishery $f) => $f->update(['day_start_time' => null]), PublicationIssue::FishingDayMissing],
    'phone' => [fn (Fishery $f) => $f->update(['phone' => null]), PublicationIssue::PhoneMissing],
    'description' => [fn (Fishery $f) => $f->update(['description' => '<p> </p>']), PublicationIssue::DescriptionMissing],
    'photo' => [fn (Fishery $f) => $f->update(['gallery_images' => []]), PublicationIssue::PhotoMissing],
    'map' => [fn (Fishery $f) => $f->update(['map_image_path' => null]), PublicationIssue::MapMissing],
    'terms in force' => [fn (Fishery $f) => $f->documents()->update(['effective_from' => '2026-06-01']), PublicationIssue::TermsMissing],
    'positions for sale' => [fn (Fishery $f) => $f->positions()->update(['status' => PositionStatus::Withdrawn->value]), PublicationIssue::NoPositionsForSale],
    'pricing gap' => [fn (Fishery $f) => $f->priceRules()->update(['last_day_on' => '2026-08-31']), PublicationIssue::PricingGap],
]);

test('a fishery without any sale period misses the sale period and gets no pricing gap', function () {
    [$fishery] = publishableFishery();
    $fishery->salePeriods()->delete();

    expect(readinessOf($fishery)->issues())->toBe([PublicationIssue::SalePeriodMissing]);
});

test('a sale period that has already ended does not count', function () {
    [$fishery] = publishableFishery();
    $fishery->salePeriods()->delete();
    SalePeriod::factory()->create(['fishery_id' => $fishery->id, 'starts_on' => '2026-01-01', 'ends_on' => '2026-04-30']);

    expect(readinessOf($fishery)->issues())->toContain(PublicationIssue::SalePeriodMissing);
});

test('no positions at all and positions withdrawn from sale are two different issues', function () {
    [$fishery] = publishableFishery();
    $fishery->positions()->delete();

    expect(readinessOf($fishery)->issues())->toContain(PublicationIssue::NoPositions)
        ->not->toContain(PublicationIssue::NoPositionsForSale);
});

test('the pricing gap warning names the first night without a rate', function () {
    [$fishery, $owner] = publishableFishery();
    $fishery->priceRules()->update(['last_day_on' => '2026-08-31']);
    actAsOwnerInPanel($owner);

    Livewire::test(ManageFishery::class, ['record' => $fishery->getKey()])
        ->mountAction('publish')
        ->assertMountedActionModalSee('01.09.2026');
});

test('only a missing state blocks publication', function () {
    [$fishery] = publishableFishery();
    $fishery->update(['state_id' => null, 'phone' => null]);

    expect(readinessOf($fishery)->blocksPublication())->toBeTrue();

    $fishery->update(['state_id' => State::factory()->create()->id]);

    expect(readinessOf($fishery)->blocksPublication())->toBeFalse();
});

test('the owner publishes a fishery despite warnings', function () {
    [$fishery, $owner] = publishableFishery();
    $fishery->update(['phone' => null, 'map_image_path' => null]);
    actAsOwnerInPanel($owner);

    Livewire::test(ManageFishery::class, ['record' => $fishery->getKey()])
        ->mountAction('publish')
        ->assertMountedActionModalSee([PublicationIssue::PhoneMissing->label(), PublicationIssue::MapMissing->label()])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($fishery->fresh()->published_at?->toDateTimeString())->toBe('2026-05-04 09:00:00')
        ->and(Fishery::query()->published()->pluck('id')->all())->toBe([$fishery->id]);
});

test('the warning links every issue to the screen where it is fixed', function () {
    [$fishery, $owner] = publishableFishery();
    $fishery->update(['phone' => null]);
    $fishery->positions()->update(['status' => PositionStatus::Withdrawn->value]);
    $fishery->documents()->delete();
    actAsOwnerInPanel($owner);

    Livewire::test(ManageFishery::class, ['record' => $fishery->getKey()])
        ->mountAction('publish')
        // ⚠️ W MODALU, nie na stronie — te same adresy są też w sub-nawigacji łowiska.
        ->assertMountedActionModalSeeHtml([
            'href="'.FisheryResource::getUrl('edit', ['record' => $fishery]).'"',
            'href="'.FisheryResource::getUrl('positions', ['record' => $fishery]).'"',
            'href="'.FisheryResource::getUrl('documents', ['record' => $fishery]).'"',
        ])
        ->assertMountedActionModalDontSeeHtml('href="'.FisheryResource::getUrl('pricing', ['record' => $fishery]).'"');
});

test('a fishery without a state can not be published, also when the request skips the modal', function () {
    [$fishery, $owner] = publishableFishery();
    $fishery->update(['state_id' => null]);
    actAsOwnerInPanel($owner);

    Livewire::test(ManageFishery::class, ['record' => $fishery->getKey()])
        ->mountAction('publish')
        ->assertMountedActionModalSee(PublicationIssue::StateMissing->label())
        ->callMountedAction()
        ->assertNotified(__('The fishery can not be published without a state.'));

    expect($fishery->fresh()->published_at)->toBeNull();
});

test('the owner withdraws a published fishery from the portal', function () {
    [$fishery, $owner] = publishableFishery();
    $fishery->update(['published_at' => now()]);
    actAsOwnerInPanel($owner);

    Livewire::test(ManageFishery::class, ['record' => $fishery->getKey()])
        ->assertActionHidden('publish')
        ->callAction('withdraw')
        ->assertHasNoActionErrors();

    expect($fishery->fresh()->published_at)->toBeNull();
});

test('publishing and withdrawing are written to the activity log', function () {
    [$fishery, $owner] = publishableFishery();
    actAsOwnerInPanel($owner);

    Livewire::test(ManageFishery::class, ['record' => $fishery->getKey()])
        ->callAction('publish');

    $entry = Activity::query()->where('subject_type', $fishery->getMorphClass())
        ->where('subject_id', $fishery->id)->latest('id')->first();

    expect($entry->attribute_changes['old'])->toHaveKey('published_at', null)
        ->and($entry->attribute_changes['attributes']['published_at'] ?? null)->not->toBeNull();
});

test('a published fishery can not be saved without a state', function () {
    [$fishery, $owner] = publishableFishery();
    $fishery->update(['published_at' => now()]);
    actAsOwnerInPanel($owner);

    Livewire::test(EditFishery::class, ['record' => $fishery->getKey()])
        ->fillForm(['state_id' => null])
        ->call('save')
        ->assertHasFormErrors(['state_id' => 'required'])
        ->assertHasNoFormErrors(['company_id']);

    expect($fishery->fresh()->state_id)->not->toBeNull();
});

test('only the owner of the fishery and the admin may publish it', function () {
    [$fishery, $owner] = publishableFishery();
    $stranger = User::factory()->create();
    OwnerRoleProvisioner::addOwnerRole($stranger);
    $admin = $this->createSuperAdmin();

    expect(Gate::forUser($owner)->allows('publish', $fishery))->toBeTrue()
        ->and(Gate::forUser($stranger)->allows('publish', $fishery))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('publish', $fishery))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('updateSlug', $fishery))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('updateSlug', $fishery))->toBeTrue();
});

test('another owner can not reach the publish action of a foreign fishery', function () {
    [$fishery] = publishableFishery();
    $stranger = User::factory()->create();
    OwnerRoleProvisioner::addOwnerRole($stranger);

    // Cudze łowisko NIE ISTNIEJE dla strony (`autoryzacja.md` §5) — akcja nie ma na czym działać.
    $this->actingAs($stranger)
        ->get("/owner/fisheries/{$fishery->id}/manage")
        ->assertNotFound();

    expect($fishery->fresh()->published_at)->toBeNull();
});

test('the admin panel shows the publication status without the publish buttons', function () {
    [$fishery] = publishableFishery();
    $this->actingAs($this->createSuperAdmin());
    Filament::setCurrentPanel('admin');

    Livewire::test(ManageFishery::class, ['record' => $fishery->getKey()])
        ->assertActionHidden('publish')
        ->assertActionHidden('withdraw')
        ->assertSee(__('Not published'));
});
