<?php

namespace Tests\Feature;

use App\Filament\Resources\FisheryResource;
use App\Filament\Resources\FisheryResource\Pages\ManageAdditionalServices;
use App\Filament\Resources\FisheryResource\Pages\ManagePositions;
use App\Filament\Resources\PositionResource;
use App\Models\Company;
use App\Models\Fishery;
use App\Models\Position;
use App\Models\User;
use App\Services\FisheryAccess;
use App\Services\FisheryNavigation;
use App\Services\OwnerRoleProvisioner;
use Filament\Facades\Filament;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Testy **bezpośrednie** metod bramkujących i nawigacyjnych.
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
    OwnerRoleProvisioner::addOwnerRole($owner);
    $this->actingAs($owner);
    $fishery = Fishery::factory()->forUser($owner)->create();

    expect(FisheryAccess::assertFisheryAccessOrAbort($fishery->id))->toBe($fishery->id)
        // string z `request()->get()` ma dać ten sam wynik co int
        ->and(FisheryAccess::assertFisheryAccessOrAbort((string) $fishery->id))->toBe($fishery->id);
});

test('gate aborts for a fishery owned by somebody else', function () {
    $owner = User::factory()->create();
    OwnerRoleProvisioner::addOwnerRole($owner);
    $this->actingAs($owner);
    $foreign = Fishery::factory()->forUser(User::factory()->create())->create();

    expect(fn () => FisheryAccess::assertFisheryAccessOrAbort($foreign->id))
        ->toThrow(NotFoundHttpException::class);
});

test('gate aborts for missing and non-numeric identifiers', function (mixed $value) {
    $owner = User::factory()->create();
    OwnerRoleProvisioner::addOwnerRole($owner);
    $this->actingAs($owner);

    expect(fn () => FisheryAccess::assertFisheryAccessOrAbort($value))
        ->toThrow(NotFoundHttpException::class);
})->with([[null], [''], ['abc'], [0], ['999999']]);

test('forceVerifiedFishery rewrites the submitted fishery id through the gate', function () {
    $owner = User::factory()->create();
    OwnerRoleProvisioner::addOwnerRole($owner);
    $this->actingAs($owner);
    $own = Fishery::factory()->forUser($owner)->create();
    $foreign = Fishery::factory()->forUser(User::factory()->create())->create();

    expect(FisheryAccess::forceVerifiedFishery(['fishery_id' => (string) $own->id]))
        ->toBe(['fishery_id' => $own->id]);

    expect(fn () => FisheryAccess::forceVerifiedFishery(['fishery_id' => $foreign->id]))
        ->toThrow(NotFoundHttpException::class);
});

test('scopeToOwnedFisheries hides records of other owners', function () {
    $owner = User::factory()->create();
    OwnerRoleProvisioner::addOwnerRole($owner);
    $this->actingAs($owner);

    $own = Position::factory()->create([
        'fishery_id' => Fishery::factory()->forUser($owner)->create()->id,
    ]);
    $foreign = Position::factory()->create([
        'fishery_id' => Fishery::factory()->forUser(User::factory()->create())->create()->id,
    ]);

    $query = Position::query();
    FisheryAccess::scopeToOwnedFisheries($query);
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
    FisheryAccess::scopeToOwnedFisheries($query);

    expect($query->pluck('id')->all())->toContain($foreign->id);
});

test('section url points at the page of that section', function () {
    $owner = User::factory()->create();
    OwnerRoleProvisioner::addOwnerRole($owner);
    $this->actingAs($owner);
    $fishery = Fishery::factory()->forUser($owner)->create();

    // ⚠️ Adres składa się po KLASIE STRONY. Do zadania 016 wskazywał go parametr
    // `?relation=N` liczony z pozycji w `getRelations()`, więc przestawienie zakładek
    // cicho przekierowywało zapis na cudzą listę. Sub-nawigacja zniosła tę pułapkę.
    expect(FisheryNavigation::fisheryHubUrl($fishery->id, ManagePositions::class))
        ->toBe(FisheryResource::getUrl('positions', ['record' => $fishery]))
        // różne sekcje muszą dawać różne adresy — inaczej zapis wracałby zawsze
        // w to samo miejsce, a test przechodziłby z niewłaściwego powodu
        ->not->toBe(FisheryNavigation::fisheryHubUrl($fishery->id, ManageAdditionalServices::class));
});

test('hub url is null when the fishery cannot be resolved', function (mixed $value) {
    $owner = User::factory()->create();
    OwnerRoleProvisioner::addOwnerRole($owner);
    $this->actingAs($owner);

    expect(FisheryNavigation::fisheryHubUrl($value, ManagePositions::class))->toBeNull();
})->with([[null], ['abc'], ['999999']]);

test('section url falls back to the standalone list when there is no fishery', function () {
    $owner = User::factory()->create();
    OwnerRoleProvisioner::addOwnerRole($owner);
    $this->actingAs($owner);

    expect(FisheryNavigation::fisherySectionUrl(PositionResource::class, ManagePositions::class, null))
        ->toBe(PositionResource::getUrl('index', ['fishery' => null]));
});

test('breadcrumbs link back to the fisheries list and to the fishery hub', function () {
    $owner = User::factory()->create();
    OwnerRoleProvisioner::addOwnerRole($owner);
    $this->actingAs($owner);
    $fishery = Fishery::factory()->forUser($owner)->create(['name' => 'Lowisko Okruszkowe']);

    $crumbs = FisheryNavigation::fisheryBreadcrumbs($fishery->id, 'Stanowiska');

    expect($crumbs)->toHaveKey(FisheryResource::getUrl('index'))
        ->and($crumbs)->toHaveKey(FisheryResource::getUrl('manage', ['record' => $fishery]))
        ->and($crumbs[FisheryResource::getUrl('manage', ['record' => $fishery])])->toBe('Lowisko Okruszkowe')
        ->and(array_values($crumbs))->toContain('Stanowiska');
});

test('breadcrumbs drop the fishery level when there is no fishery', function () {
    $owner = User::factory()->create();
    OwnerRoleProvisioner::addOwnerRole($owner);
    $this->actingAs($owner);

    expect(FisheryNavigation::fisheryBreadcrumbs(null, 'Stanowiska'))
        ->toBe([FisheryResource::getUrl('index') => __('Fisheries'), 0 => 'Stanowiska']);
});

test('breadcrumbs never reveal the name of a fishery owned by somebody else', function () {
    // ⚠️ To była JEDYNA ścieżka odczytu omijająca wszystkie trzy warstwy bramki:
    // strony `Create*` czytają `?fishery` wprost z żądania, a `mount()` biegnie tylko
    // przy pierwszym GET-cie. `POST /livewire/update?fishery=<cudze>` przeliczał
    // okruszki i zwracał nazwę cudzego łowiska wraz z linkiem do jego huba,
    // pozwalając enumerować katalog po ID (audyt bezpieczeństwa, zadanie 012).
    $owner = User::factory()->create();
    OwnerRoleProvisioner::addOwnerRole($owner);
    $this->actingAs($owner);

    $foreign = Fishery::factory()
        ->forUser(User::factory()->create())
        ->create(['name' => 'Cudze Lowisko Sekretne']);

    $crumbs = FisheryNavigation::fisheryBreadcrumbs($foreign->id, 'Stanowiska');

    expect($crumbs)->not->toContain('Cudze Lowisko Sekretne')
        ->and(FisheryNavigation::getFisheryTitle($foreign->id, 'Positions'))
        ->not->toContain('Cudze Lowisko Sekretne');
});

test('owner policies deny records belonging to another owner', function () {
    // ⚠️ Rola `owner` ma PEŁNY zestaw `*:fishery` i `*:company`, więc samo `can()`
    // przepuszczało cudzy rekord — polityka musi sprawdzić właściciela na rekordzie.
    $owner = User::factory()->create();
    OwnerRoleProvisioner::addOwnerRole($owner);
    $other = User::factory()->create();
    OwnerRoleProvisioner::addOwnerRole($other);
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
    OwnerRoleProvisioner::addOwnerRole($admin);
    $this->actingAs($admin);

    $foreignFishery = Fishery::factory()->forUser(User::factory()->create())->create();

    expect($admin->can('view', $foreignFishery))->toBeTrue()
        ->and($admin->can('update', $foreignFishery))->toBeTrue();
});

/**
 * ⚠️ Ten test pilnuje KLUCZA PAMIĘCI PODRĘCZNEJ `findFishery()`, a nie samego wyniku.
 *
 * Klucz musi nieść ID użytkownika, bo kontener przeżywa wiele żądań HTTP w obrębie
 * jednego testu. Bez tego drugi użytkownik dostaje z cache'u wpis pierwszego i bramka
 * przepuszcza go na cudze łowisko — a pakiet świeci na zielono, bo wynik „jakiś jest".
 *
 * Testy mutacyjne zadania 013 pokazały, że siedem mutantów w budowie tego klucza
 * przeżywało bez tego przypadku.
 */
test('the fishery cache never serves one owner the record of another', function () {
    $first = User::factory()->create();
    OwnerRoleProvisioner::addOwnerRole($first);
    $second = User::factory()->create();
    OwnerRoleProvisioner::addOwnerRole($second);

    $fishery = Fishery::factory()->forUser($first)->create();

    $this->actingAs($first);
    expect(FisheryAccess::findFishery($fishery->id)?->id)->toBe($fishery->id);

    // Ten sam kontener, to samo łowisko, inny użytkownik — musi wyjść pusto.
    $this->actingAs($second);
    expect(FisheryAccess::findFishery($fishery->id))->toBeNull();

    // I z powrotem: właściciel nadal je widzi, więc wpis drugiego go nie zatruł.
    $this->actingAs($first);
    expect(FisheryAccess::findFishery($fishery->id)?->id)->toBe($fishery->id);
});

/**
 * ⚠️ Wariant NIEOGRANICZONY jest świadomym wyborem wywołującego i ma własny klucz
 * w pamięci podręcznej — inaczej odpowiedź zawężona podmieniałaby niezawężoną
 * i odwrotnie (`docs/conventions/autoryzacja.md` §4).
 */
test('the unscoped variant is deliberate and does not share a cache entry with the scoped one', function () {
    $owner = User::factory()->create();
    OwnerRoleProvisioner::addOwnerRole($owner);
    $foreign = Fishery::factory()->forUser(User::factory()->create())->create();

    $this->actingAs($owner);

    // Domyślnie zawężone: cudze łowisko nie istnieje.
    expect(FisheryAccess::findFishery($foreign->id))->toBeNull()
        // Wariant wybrany jawnie: widzi je, mimo że poprzednie wywołanie dało null.
        ->and(FisheryAccess::findFishery($foreign->id, false)?->id)->toBe($foreign->id)
        // I nie zatruwa wariantu zawężonego.
        ->and(FisheryAccess::findFishery($foreign->id))->toBeNull();
});

/**
 * ⚠️ Druga połowa niezmiennika klucza: musi nieść także ID ŁOWISKA. Bez niego ten sam
 * użytkownik pytający kolejno o dwa swoje łowiska dostaje z pamięci podręcznej
 * pierwsze z nich — a wszystkie asercje „coś wróciło" przechodzą.
 */
test('the fishery cache never serves one fishery in place of another', function () {
    $owner = User::factory()->create();
    OwnerRoleProvisioner::addOwnerRole($owner);
    $first = Fishery::factory()->forUser($owner)->create();
    $second = Fishery::factory()->forUser($owner)->create();

    $this->actingAs($owner);

    expect(FisheryAccess::findFishery($first->id)?->id)->toBe($first->id)
        ->and(FisheryAccess::findFishery($second->id)?->id)->toBe($second->id)
        ->and(FisheryAccess::findFishery($first->id)?->id)->toBe($first->id);
});
