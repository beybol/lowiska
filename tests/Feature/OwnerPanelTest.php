<?php

namespace Tests\Feature;

use App\Filament\Resources\AdditionalServiceResource\Pages\CreateAdditionalService;
use App\Filament\Resources\AdditionalServiceResource\Pages\EditAdditionalService;
use App\Filament\Resources\FisheryResource;
use App\Filament\Resources\FisheryResource\Pages\ManageFishery;
use App\Filament\Resources\FisheryResource\RelationManagers\AdditionalServicesRelationManager;
use App\Filament\Resources\FisheryResource\RelationManagers\LongTermPermitsRelationManager;
use App\Filament\Resources\FisheryResource\RelationManagers\PositionsRelationManager;
use App\Filament\Resources\LongTermPermitResource\Pages\CreateLongTermPermit;
use App\Filament\Resources\LongTermPermitResource\Pages\EditLongTermPermit;
use App\Filament\Resources\PositionResource\Pages\CreatePosition;
use App\Filament\Resources\PositionResource\Pages\EditPosition;
use App\Helpers\Helper;
use App\Models\AdditionalService;
use App\Models\Company;
use App\Models\Fishery;
use App\Models\LongTermPermit;
use App\Models\Position;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

test('Owner panel is accessible.', function () {
    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);

    // ⚠️ Zadanie 009: usunięto `assertSee(__('Panel'))` — patrz komentarz
    // w tests/Feature/AdminPanelTest.php. Realnym dowodem, że panel się
    // wyrenderował, są etykiety nawigacji sprawdzane niżej.
    $this->actingAs($owner)
        ->get('/owner')
        ->assertStatus(200)
        ->assertSee(__('Companies'))
        ->assertDontSee(__('Fish'))
        ->assertDontSee(__('Conveniences'))
        ->assertDontSee(__('Countries'))
        ->assertDontSee(__('Fishery types'))
        ->assertDontSee(__('Fishing methods'))
        ->assertDontSee(__('States'))
        ->assertSee(__('Fisheries'))
        ->assertDontSee(__('Users'));
});

test('Admin has access to owner panel.', function () {
    $admin = $this->createSuperAdmin();

    $this->actingAs($admin)
        ->get('/owner')
        ->assertStatus(200)
        ->assertSee(__('Companies'));
});

test('Owner can view only his company.', function () {
    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);
    $company = Company::factory()->forUser($owner)->create();
    $otherUser = User::factory()->create();
    $otherCompany = Company::factory()->forUser($otherUser)->create();

    $this->actingAs($owner)
        ->get('/owner/companies')
        ->assertStatus(200)
        ->assertSee($company->name)
        ->assertDontSee($otherCompany->name);
});

test('Owner can view only his fishery.', function () {
    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);
    $fishery = Fishery::factory()->forUser($owner)->create();
    $otherUser = User::factory()->create();
    $otherFishery = Fishery::factory()->forUser($otherUser)->create();

    $this->actingAs($owner)
        ->get('/owner/fisheries')
        ->assertStatus(200)
        ->assertSee($fishery->name)
        ->assertDontSee($otherFishery->name);
});

test('Owner see management fishery button on fisheries list.', function () {
    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);
    $fishery = Fishery::factory()->forUser($owner)->create();

    $this->actingAs($owner)
        ->get('/owner/fisheries')
        ->assertStatus(200)
        ->assertSee(__('Manage'));
});

test('Owner can view manage fishery page.', function () {
    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);
    $fishery = Fishery::factory()->forUser($owner)->create();

    $this->actingAs($owner)
        ->get("/owner/fisheries/{$fishery->id}/manage")
        ->assertSee(__('Manage fishery').' '.$fishery->name)
        // Zakładka z danymi łowiska jest PIERWSZA — wejście w hub nie ma wrzucać
        // od razu w jedną z trzech list (zadanie 012).
        ->assertSee(__('Fishery data'))
        ->assertSee(__('Long term permits'))
        ->assertSee(__('Additional services'))
        ->assertSee(__('Positions'))
        // ⚠️ Listy renderują RelationManagery wprost w zakładce. Przycisk „Lista",
        // za którym były wcześniej schowane, ma już nie istnieć.
        ->assertDontSee(__('List'))
        ->assertStatus(200);
});

test('Manage fishery tabs render sub-resource records inline.', function (string $relationManager, string $model, string $createPath) {
    // ⚠️ RelationManagery trzeba testować przez `Livewire::test`, nie przez GET strony huba:
    // przy zakładkach połączonych z treścią pierwsze żądanie renderuje tylko zakładkę
    // z danymi łowiska, a listy dociąga Livewire po kliknięciu (zadanie 012).
    Filament::setCurrentPanel('owner');

    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);
    $this->actingAs($owner);
    $fishery = Fishery::factory()->forUser($owner)->create();
    $record = $model::factory()->create([
        'is_active' => true,
        'fishery_id' => $fishery->id,
    ]);

    Livewire::test($relationManager, [
        'ownerRecord' => $fishery,
        'pageClass' => ManageFishery::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$record])
        ->assertSee($record->name)
        // ⚠️ Przycisk dodawania musi być zwykłą `Action`, nie `CreateAction` — ta druga
        // w RelationManagerze przepada na autoryzacji relacji i znika bez śladu.
        // Ten test jest jedynym miejscem, które to wychwytuje (zadanie 012).
        ->assertSee("/owner/{$createPath}/create?fishery={$fishery->id}");
})->with([
    [PositionsRelationManager::class, Position::class, 'positions'],
    [AdditionalServicesRelationManager::class, AdditionalService::class, 'additional-services'],
    [LongTermPermitsRelationManager::class, LongTermPermit::class, 'long-term-permits'],
]);

test('Manage fishery data tab links to the fishery edit form.', function () {
    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);
    $fishery = Fishery::factory()->forUser($owner)->create();

    // Zakładka z danymi łowiska jest podglądem, więc bez tego przycisku z huba
    // nie da się przejść do edycji łowiska (zadanie 012).
    $this->actingAs($owner)
        ->get("/owner/fisheries/{$fishery->id}/manage")
        ->assertStatus(200)
        ->assertSee(__('Edit'))
        ->assertSee("/owner/fisheries/{$fishery->id}/edit");
});

test('Saving a sub-resource returns to its tab in the fishery hub.', function (
    string $createPage,
    string $editPage,
    string $model,
    string $relationManager,
) {
    Filament::setCurrentPanel('owner');

    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);
    $this->actingAs($owner);
    $fishery = Fishery::factory()->forUser($owner)->create();
    $record = $model::factory()->create([
        'is_active' => true,
        'fishery_id' => $fishery->id,
    ]);

    // ⚠️ Filament identyfikuje zakładkę POZYCJĄ w `FisheryResource::getRelations()`,
    // nie nazwą klasy. Oczekiwany adres liczymy tu z tej samej tablicy — inaczej test
    // przyklepałby zaszyty numer i przestawienie zakładek przeszłoby niezauważone.
    $relation = array_search($relationManager, FisheryResource::getRelations(), true);
    $expected = FisheryResource::getUrl('manage', [
        'record' => $fishery,
        'relation' => $relation,
    ]);

    $afterCreate = Livewire::withQueryParams(['fishery' => $fishery->id])
        ->test($createPage)
        ->instance()
        ->getRedirectUrl();

    $afterEdit = Livewire::test($editPage, ['record' => $record->getKey()])
        ->instance()
        ->getRedirectUrl();

    expect($afterCreate)->toBe($expected)
        ->and($afterEdit)->toBe($expected);
})->with([
    [CreatePosition::class, EditPosition::class, Position::class, PositionsRelationManager::class],
    [CreateAdditionalService::class, EditAdditionalService::class, AdditionalService::class, AdditionalServicesRelationManager::class],
    [CreateLongTermPermit::class, EditLongTermPermit::class, LongTermPermit::class, LongTermPermitsRelationManager::class],
]);

test('Fishery edit page does not render sub-resource tabs.', function () {
    // ⚠️ `FisheryResource::getRelations()` obowiązuje wszystkie strony zasobu. Bez
    // `canViewForRecord()` w RelationManagerach listy doklejają się do formularza
    // edycji łowiska — ten test pilnuje, żeby należały wyłącznie do huba.
    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);
    $fishery = Fishery::factory()->forUser($owner)->create();

    $this->actingAs($owner)
        ->get("/owner/fisheries/{$fishery->id}/edit")
        ->assertStatus(200)
        ->assertDontSee(__('Long term permits'))
        ->assertDontSee(__('Positions'));
});

test('Owner can see only active long term permits count on manage fishery page.', function () {
    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);
    $fishery = Fishery::factory()->forUser($owner)->create();
    LongTermPermit::factory()
        ->create([
            'is_active' => true,
            'fishery_id' => $fishery->id,
        ]);
    LongTermPermit::factory()
        ->create([
            'is_active' => false,
            'fishery_id' => $fishery->id,
        ]);

    $this->actingAs($owner)
        ->get("/owner/fisheries/{$fishery->id}/manage")
        ->assertStatus(200);

    // ⚠️ Licznik sprawdzamy u źródła, nie przez `assertSee('1')` na całej stronie —
    // jedynka trafia się w losowym miejscu HTML-a i taka asercja przechodziła
    // nawet wtedy, gdy plakietki w ogóle nie było (zadanie 012).
    expect(LongTermPermitsRelationManager::getBadge($fishery, ManageFishery::class))
        ->toBe('1');
});

test('Owner can see only active additional services count on manage fishery page.', function () {
    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);
    $fishery = Fishery::factory()->forUser($owner)->create();
    AdditionalService::factory()
        ->create([
            'is_active' => true,
            'fishery_id' => $fishery->id,
        ]);
    AdditionalService::factory()
        ->create([
            'is_active' => false,
            'fishery_id' => $fishery->id,
        ]);

    $this->actingAs($owner)
        ->get("/owner/fisheries/{$fishery->id}/manage")
        ->assertStatus(200);

    expect(AdditionalServicesRelationManager::getBadge($fishery, ManageFishery::class))
        ->toBe('1');
});

test('Owner can see only active positions count on manage fishery page.', function () {
    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);
    $fishery = Fishery::factory()->forUser($owner)->create();
    Position::factory()
        ->create([
            'is_active' => true,
            'fishery_id' => $fishery->id,
        ]);
    Position::factory()
        ->create([
            'is_active' => false,
            'fishery_id' => $fishery->id,
        ]);

    $this->actingAs($owner)
        ->get("/owner/fisheries/{$fishery->id}/manage")
        ->assertStatus(200);

    expect(PositionsRelationManager::getBadge($fishery, ManageFishery::class))
        ->toBe('1');
});

test('Owner can not view position create page for fishery he does not own.', function () {
    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);
    $otherUser = User::factory()->create();
    $fishery = Fishery::factory()->forUser($otherUser)->create();

    $this->actingAs($owner)
        ->get("/owner/fisheries/{$fishery->id}/positions/create")
        ->assertStatus(404);
});
