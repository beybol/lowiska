<?php

namespace Tests\Feature;

use App\Filament\Resources\FisheryResource;
use App\Filament\Resources\FisheryResource\RelationManagers\AdditionalServicesRelationManager;
use App\Filament\Resources\FisheryResource\RelationManagers\PositionsRelationManager;
use App\Filament\Resources\PositionResource;
use App\Helpers\Helper;
use App\Models\Company;
use App\Models\Fishery;
use App\Models\Position;
use App\Models\User;
use Filament\Facades\Filament;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Testy **bezpośrednie** metod bramkujących i nawigacyjnych `Helper`.
 *
 * ⚠️ Powód istnienia tego pliku: te metody niosą niezmiennik bezpieczeństwa opisany
 * w `docs/conventions/autoryzacja.md` §4, a jedynym ich pokryciem były testy `Feature`
 * renderujące całe strony Filamenta. To wystarcza do wykrycia regresji, ale jest zbyt
 * wolne, żeby dało się na tym uruchomić testy mutacyjne — a właśnie tu mutacje mają
 * sens, bo ocalały mutant w bramce to realna luka dostępu (zadanie 012).
 */
beforeEach(function () {
    Filament::setCurrentPanel('owner');
});

test('gate returns the verified id for a fishery the user owns', function () {
    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);
    $this->actingAs($owner);
    $fishery = Fishery::factory()->forUser($owner)->create();

    expect(Helper::assertFisheryAccessOrAbort($fishery->id))->toBe($fishery->id)
        // string z `request()->get()` ma dać ten sam wynik co int
        ->and(Helper::assertFisheryAccessOrAbort((string) $fishery->id))->toBe($fishery->id);
});

test('gate aborts for a fishery owned by somebody else', function () {
    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);
    $this->actingAs($owner);
    $foreign = Fishery::factory()->forUser(User::factory()->create())->create();

    expect(fn () => Helper::assertFisheryAccessOrAbort($foreign->id))
        ->toThrow(NotFoundHttpException::class);
});

test('gate aborts for missing and non-numeric identifiers', function (mixed $value) {
    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);
    $this->actingAs($owner);

    expect(fn () => Helper::assertFisheryAccessOrAbort($value))
        ->toThrow(NotFoundHttpException::class);
})->with([[null], [''], ['abc'], [0], ['999999']]);

test('forceVerifiedFishery rewrites the submitted fishery id through the gate', function () {
    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);
    $this->actingAs($owner);
    $own = Fishery::factory()->forUser($owner)->create();
    $foreign = Fishery::factory()->forUser(User::factory()->create())->create();

    expect(Helper::forceVerifiedFishery(['fishery_id' => (string) $own->id]))
        ->toBe(['fishery_id' => $own->id]);

    expect(fn () => Helper::forceVerifiedFishery(['fishery_id' => $foreign->id]))
        ->toThrow(NotFoundHttpException::class);
});

test('scopeToOwnedFisheries hides records of other owners', function () {
    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);
    $this->actingAs($owner);

    $own = Position::factory()->create([
        'fishery_id' => Fishery::factory()->forUser($owner)->create()->id,
    ]);
    $foreign = Position::factory()->create([
        'fishery_id' => Fishery::factory()->forUser(User::factory()->create())->create()->id,
    ]);

    $query = Position::query();
    Helper::scopeToOwnedFisheries($query);
    $visible = $query->pluck('id')->all();

    expect($visible)->toContain($own->id)
        ->and($visible)->not->toContain($foreign->id);
});

test('scopeToOwnedFisheries does not narrow anything in the admin panel', function () {
    Filament::setCurrentPanel('admin');

    $admin = $this->createSuperAdmin();
    $this->actingAs($admin);

    $foreign = Position::factory()->create([
        'fishery_id' => Fishery::factory()->forUser(User::factory()->create())->create()->id,
    ]);

    $query = Position::query();
    Helper::scopeToOwnedFisheries($query);

    expect($query->pluck('id')->all())->toContain($foreign->id);
});

test('hub url carries the tab index matching the relation manager position', function () {
    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);
    $this->actingAs($owner);
    $fishery = Fishery::factory()->forUser($owner)->create();

    $expectedIndex = array_search(PositionsRelationManager::class, FisheryResource::getRelations(), true);

    expect(Helper::fisheryHubUrl($fishery->id, PositionsRelationManager::class))
        ->toBe(FisheryResource::getUrl('manage', ['record' => $fishery, 'relation' => $expectedIndex]))
        // różne managery muszą dawać różne zakładki — inaczej „liczony indeks"
        // byłby tylko pozorem i zapis wracałby zawsze w to samo miejsce
        ->not->toBe(Helper::fisheryHubUrl($fishery->id, AdditionalServicesRelationManager::class));
});

test('hub url is null when the fishery cannot be resolved', function (mixed $value) {
    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);
    $this->actingAs($owner);

    expect(Helper::fisheryHubUrl($value, PositionsRelationManager::class))->toBeNull();
})->with([[null], ['abc'], ['999999']]);

test('section url falls back to the standalone list when there is no fishery', function () {
    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);
    $this->actingAs($owner);

    expect(Helper::fisherySectionUrl(PositionResource::class, PositionsRelationManager::class, null))
        ->toBe(PositionResource::getUrl('index', ['fishery' => null]));
});

test('breadcrumbs link back to the fisheries list and to the fishery hub', function () {
    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);
    $this->actingAs($owner);
    $fishery = Fishery::factory()->forUser($owner)->create(['name' => 'Lowisko Okruszkowe']);

    $crumbs = Helper::fisheryBreadcrumbs($fishery->id, 'Stanowiska');

    expect($crumbs)->toHaveKey(FisheryResource::getUrl('index'))
        ->and($crumbs)->toHaveKey(FisheryResource::getUrl('manage', ['record' => $fishery]))
        ->and($crumbs[FisheryResource::getUrl('manage', ['record' => $fishery])])->toBe('Lowisko Okruszkowe')
        ->and(array_values($crumbs))->toContain('Stanowiska');
});

test('breadcrumbs drop the fishery level when there is no fishery', function () {
    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);
    $this->actingAs($owner);

    expect(Helper::fisheryBreadcrumbs(null, 'Stanowiska'))
        ->toBe([FisheryResource::getUrl('index') => __('Fisheries'), 0 => 'Stanowiska']);
});

test('breadcrumbs never reveal the name of a fishery owned by somebody else', function () {
    // ⚠️ To była JEDYNA ścieżka odczytu omijająca wszystkie trzy warstwy bramki:
    // strony `Create*` czytają `?fishery` wprost z żądania, a `mount()` biegnie tylko
    // przy pierwszym GET-cie. `POST /livewire/update?fishery=<cudze>` przeliczał
    // okruszki i zwracał nazwę cudzego łowiska wraz z linkiem do jego huba,
    // pozwalając enumerować katalog po ID (audyt bezpieczeństwa, zadanie 012).
    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);
    $this->actingAs($owner);

    $foreign = Fishery::factory()
        ->forUser(User::factory()->create())
        ->create(['name' => 'Cudze Lowisko Sekretne']);

    $crumbs = Helper::fisheryBreadcrumbs($foreign->id, 'Stanowiska');

    expect($crumbs)->not->toContain('Cudze Lowisko Sekretne')
        ->and(Helper::getFisheryTitle($foreign->id, 'Positions'))
        ->not->toContain('Cudze Lowisko Sekretne');
});

test('owner policies deny records belonging to another owner', function () {
    // ⚠️ Rola `owner` ma PEŁNY zestaw `*:fishery` i `*:company`, więc samo `can()`
    // przepuszczało cudzy rekord — polityka musi sprawdzić właściciela na rekordzie.
    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);
    $other = User::factory()->create();
    Helper::addOwnerRole($other);
    $this->actingAs($owner);

    $ownFishery = Fishery::factory()->forUser($owner)->create();
    $foreignFishery = Fishery::factory()->forUser($other)->create();
    $ownCompany = Company::factory()->forUser($owner)->create();
    $foreignCompany = Company::factory()->forUser($other)->create();

    foreach (['view', 'update', 'delete'] as $ability) {
        expect($owner->can($ability, $ownFishery))->toBeTrue()
            ->and($owner->can($ability, $foreignFishery))->toBeFalse()
            ->and($owner->can($ability, $ownCompany))->toBeTrue()
            ->and($owner->can($ability, $foreignCompany))->toBeFalse();
    }
});

test('admin keeps full access even when he also holds the owner role', function () {
    // Administrator bywa jednocześnie właścicielem — zawężenie nie może go dotyczyć.
    $admin = $this->createSuperAdmin();
    Helper::addOwnerRole($admin);
    $this->actingAs($admin);

    $foreignFishery = Fishery::factory()->forUser(User::factory()->create())->create();

    expect($admin->can('view', $foreignFishery))->toBeTrue()
        ->and($admin->can('update', $foreignFishery))->toBeTrue();
});
