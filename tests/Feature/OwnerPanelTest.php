<?php

namespace Tests\Feature;

use App\Filament\Resources\AdditionalServiceResource;
use App\Filament\Resources\AdditionalServiceResource\Pages\CreateAdditionalService;
use App\Filament\Resources\AdditionalServiceResource\Pages\EditAdditionalService;
use App\Filament\Resources\AdditionalServiceResource\Pages\ListAdditionalServices;
use App\Filament\Resources\FisheryResource;
use App\Filament\Resources\FisheryResource\Pages\ManageFishery;
use App\Filament\Resources\FisheryResource\RelationManagers\AdditionalServicesRelationManager;
use App\Filament\Resources\FisheryResource\RelationManagers\LongTermPermitsRelationManager;
use App\Filament\Resources\FisheryResource\RelationManagers\PositionsRelationManager;
use App\Filament\Resources\LongTermPermitResource;
use App\Filament\Resources\LongTermPermitResource\Pages\CreateLongTermPermit;
use App\Filament\Resources\LongTermPermitResource\Pages\EditLongTermPermit;
use App\Filament\Resources\LongTermPermitResource\Pages\ListLongTermPermits;
use App\Filament\Resources\PositionResource;
use App\Filament\Resources\PositionResource\Pages\CreatePosition;
use App\Filament\Resources\PositionResource\Pages\EditPosition;
use App\Filament\Resources\PositionResource\Pages\ListPositions;
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
    // ⚠️ Nazwa użytkownika USTAWIONA WPROST, nie z fabryki. `faker_locale` to `pl_PL`,
    // a nazwisko trafia do paska Filamenta — „Krajewski"/„Krajewska" zawiera podciąg
    // „Kraje", czyli tłumaczenie `__('Countries')`, przez co `assertDontSee` niżej
    // czerwieniło się losowo w ~1 na 100 przebiegów pakietu. Zmierzone: 45 kolizji
    // na 5000 losowań. Ten sam wzorzec dotyczy każdej asercji „nie widać" na krótkim
    // polskim słowie (zadanie 012).
    $owner = User::factory()->create(['name' => 'Wlasciciel Testowy']);
    Helper::addOwnerRole($owner);

    // ⚠️ Zadanie 009: usunięto `assertSee(__('Panel'))` — patrz komentarz
    // w tests/Feature/AdminPanelTest.php.
    //
    // ⚠️ Zadanie 012: asercje „czego nie widać" idą po ADRESACH TRAS, nie po polskich
    // etykietach. Powód: `faker_locale` to `pl_PL`, a losowe nazwy trafiają na stronę —
    // „Krajewski" zawiera podciąg „Kraje", czyli `__('Countries')`, więc `assertDontSee`
    // czerwieniło się mniej więcej raz na sto przebiegów pakietu i wyglądało na losową
    // awarię środowiska. Adres trasy nie jest podciągiem niczyjego nazwiska.
    // Przy okazji asercja stała się mocniejsza: sprawdza brak WEJŚCIA do zasobu,
    // a nie brak słowa, które i tak nie miało prawa się pojawić.
    $response = $this->actingAs($owner)
        ->get('/owner')
        ->assertStatus(200)
        ->assertSee('/owner/companies')
        ->assertSee('/owner/fisheries');

    // ⚠️ Adres domknięty cudzysłowem zamykającym atrybut `href`, bo `/owner/fish`
    // jest PRZEDROSTKIEM `/owner/fisheries` — bez tego asercja padała na własnym
    // podciągu, czyli dokładnie na pułapce, którą ta zmiana usuwa.
    foreach ([
        '/owner/countries',
        '/owner/states',
        '/owner/fish',
        '/owner/conveniences',
        '/owner/fishery-types',
        '/owner/fishing-methods',
        '/owner/users',
    ] as $adminOnlyPath) {
        $response->assertDontSee($adminOnlyPath.'"', escape: false);
    }
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
    // Nazwy wprost — losowe bywają swoimi podciągami, a asercja „nie widać" jest
    // wtedy zielona albo czerwona zależnie od losowania.
    $company = Company::factory()->forUser($owner)->create(['name' => 'Firma Wlasna XYZ']);
    $otherUser = User::factory()->create();
    $otherCompany = Company::factory()->forUser($otherUser)->create(['name' => 'Firma Obca QWE']);

    $this->actingAs($owner)
        ->get('/owner/companies')
        ->assertStatus(200)
        ->assertSee($company->name)
        ->assertDontSee($otherCompany->name);
});

test('Owner can view only his fishery.', function () {
    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);
    $fishery = Fishery::factory()->forUser($owner)->create(['name' => 'Lowisko Wlasne XYZ']);
    $otherUser = User::factory()->create();
    $otherFishery = Fishery::factory()->forUser($otherUser)->create(['name' => 'Lowisko Obce QWE']);

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

test('Manage fishery tabs render sub-resource records inline.', function (
    string $relationManager,
    string $model,
    string $createPath,
    array $attributes,
    string $label,
) {
    // ⚠️ RelationManagery trzeba testować przez `Livewire::test`, nie przez GET strony huba:
    // przy zakładkach połączonych z treścią pierwsze żądanie renderuje tylko zakładkę
    // z danymi łowiska, a listy dociąga Livewire po kliknięciu (zadanie 012).
    Filament::setCurrentPanel('owner');

    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);
    $this->actingAs($owner);
    $fishery = Fishery::factory()->forUser($owner)->create();
    // ⚠️ Etykieta jest KRÓTKA i ustawiana wprost. `LongTermPermit` nie ma kolumny
    // `name` (dawne `assertSee($record->name)` przekazywało `null`, a `assertSee(null)`
    // nie asertuje niczego), a kolumny opisowe są ucinane przez `limit(20)` — losowa
    // treść z fabryki nie trafiłaby do HTML-a w całości.
    $record = $model::factory()->create([
        ...$attributes,
        'is_active' => true,
        'fishery_id' => $fishery->id,
    ]);

    Livewire::test($relationManager, [
        'ownerRecord' => $fishery,
        'pageClass' => ManageFishery::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$record])
        ->assertSee($label)
        // ⚠️ Przyciski dodawania i edycji muszą być zwykłymi `Action`, nie
        // `CreateAction`/`EditAction`: `RelationManager::isReadOnly()` jest prawdą na
        // stronie `ViewRecord` (czyli w hubie) i odmawia PO KLASIE akcji, przez co
        // znikają z HTML-a bez błędu. Te dwie asercje są jedynym, co to wychwytuje.
        ->assertSee("/owner/{$createPath}/create?fishery={$fishery->id}")
        ->call('loadTable')
        ->assertSee("/owner/{$createPath}/{$record->getKey()}/edit");
})->with([
    [PositionsRelationManager::class, Position::class, 'positions', ['name' => 'Stanowisko ABC'], 'Stanowisko ABC'],
    [AdditionalServicesRelationManager::class, AdditionalService::class, 'additional-services', ['name' => 'Usluga ABC'], 'Usluga ABC'],
    [LongTermPermitsRelationManager::class, LongTermPermit::class, 'long-term-permits', ['description' => 'Pozwolenie ABC'], 'Pozwolenie ABC'],
]);

test('Hub tab URL built by the helper actually activates that tab.', function () {
    // ⚠️ Test przekierowań niżej liczy oczekiwany adres tą samą konwencją co kod,
    // więc pilnuje wyłącznie INDEKSU. Ten test sprawdza rzecz, której tamten nie
    // widzi: że parametr zapytania, którego używa `Helper::fisheryHubUrl()`, jest
    // faktycznie tym, po którym Filament aktywuje zakładkę.
    Filament::setCurrentPanel('owner');

    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);
    $this->actingAs($owner);
    $fishery = Fishery::factory()->forUser($owner)->create();

    $url = Helper::fisheryHubUrl($fishery->id, PositionsRelationManager::class);
    parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

    Livewire::withQueryParams($query)
        ->test(ManageFishery::class, ['record' => $fishery->getKey()])
        ->assertSet('activeRelationManager', (string) array_search(
            PositionsRelationManager::class,
            FisheryResource::getRelations(),
            true,
        ));
});

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
    // ⚠️ Adres MUSI być prawdziwy. Wcześniej test szedł na
    // `/owner/fisheries/{id}/positions/create`, czyli na trasę, która nie istnieje —
    // 404 przychodziło z routingu i test przeszedłby tak samo dla WŁASNEGO łowiska.
    // Stąd para przypadków: cudze → 404, własne → 200.
    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);
    $otherUser = User::factory()->create();
    $foreignFishery = Fishery::factory()->forUser($otherUser)->create();
    $ownFishery = Fishery::factory()->forUser($owner)->create();

    $this->actingAs($owner)
        ->get('/owner/positions/create?fishery='.$foreignFishery->id)
        ->assertStatus(404);

    $this->actingAs($owner)
        ->get('/owner/positions/create?fishery='.$ownFishery->id)
        ->assertStatus(200);
});

test('Sub-resource queries exclude fisheries the owner does not own.', function (
    string $resource,
    string $model,
) {
    // ⚠️ Ten test izoluje WARSTWĘ 1 (`Helper::scopeToOwnedFisheries()` w `getEloquentQuery()`).
    // Testy „przez podmianę właściwości" niżej kończą się na warstwie 2 (bramka w
    // `getTableQuery()`), więc przechodziłyby także wtedy, gdyby warstwa 1 w ogóle nie
    // istniała — a to ona jako jedyna chroni ODCZYT POJEDYNCZEGO REKORDU
    // (`resolveRecordRouteBinding()` na stronie edycji). Patrz autoryzacja.md §4.
    Filament::setCurrentPanel('owner');

    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);
    $otherUser = User::factory()->create();
    $this->actingAs($owner);

    $ownRecord = $model::factory()->create([
        'fishery_id' => Fishery::factory()->forUser($owner)->create()->id,
    ]);
    $foreignRecord = $model::factory()->create([
        'fishery_id' => Fishery::factory()->forUser($otherUser)->create()->id,
    ]);

    expect($resource::getEloquentQuery()->find($foreignRecord->getKey()))->toBeNull()
        ->and($resource::getEloquentQuery()->find($ownRecord->getKey()))->not->toBeNull();
})->with([
    [PositionResource::class, Position::class],
    [AdditionalServiceResource::class, AdditionalService::class],
    [LongTermPermitResource::class, LongTermPermit::class],
]);

test('Owner can not open the edit page of a sub-resource he does not own.', function (
    string $path,
    string $model,
) {
    // Skutek warstwy 1 widziany od strony HTTP: cudzy rekord nie rozwiązuje się z trasy.
    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);
    $otherUser = User::factory()->create();
    $foreignRecord = $model::factory()->create([
        'fishery_id' => Fishery::factory()->forUser($otherUser)->create()->id,
    ]);

    $this->actingAs($owner)
        ->get("/owner/{$path}/{$foreignRecord->getKey()}/edit")
        ->assertStatus(404);
})->with([
    ['positions', Position::class],
    ['additional-services', AdditionalService::class],
    ['long-term-permits', LongTermPermit::class],
]);

test('Non-numeric fishery parameter gives 404, not a server error.', function (string $path) {
    // ⚠️ `$fisheryId` jest typowane `?int`, a parametr przychodzi jako string —
    // przed poprawką `?fishery=abc` wywalało TypeError (500) zanim bramka zdążyła
    // odpowiedzieć 404.
    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);

    $this->actingAs($owner)
        ->get("/owner/{$path}?fishery=abc")
        ->assertStatus(404);
})->with([['positions'], ['additional-services'], ['long-term-permits']]);

test('Owner can not list sub-resources of a fishery he does not own by tampering with the component property.', function (
    string $listPage,
    string $model,
    array $attributes,
    string $label,
) {
    // ⚠️ `$fisheryId` to publiczna właściwość komponentu wiązana z query stringiem,
    // czyli dane od klienta. Sprawdzenie w `mount()` nie chroni kolejnych żądań
    // Livewire — dlatego bramka biegnie w `getTableQuery()`, a zasoby dokładają
    // zawężenie do łowisk właściciela w `getEloquentQuery()`.
    Filament::setCurrentPanel('owner');

    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);
    $otherUser = User::factory()->create();
    $ownFishery = Fishery::factory()->forUser($owner)->create();
    $foreignFishery = Fishery::factory()->forUser($otherUser)->create();
    // ⚠️ Etykieta krótka i ustawiana wprost — kolumny opisowe są ucinane przez
    // `limit(20)`, więc losowa treść z fabryki dałaby FAŁSZYWY sukces asercji
    // „nie zawiera": brak w HTML-u wynikałby z ucięcia, nie z bramki.
    $model::factory()->create([
        ...$attributes,
        'is_active' => true,
        'fishery_id' => $foreignFishery->id,
    ]);

    $this->actingAs($owner);

    // ⚠️ `abort()` w żądaniu Livewire NIE rzuca wyjątku — daje odpowiedź, więc
    // asercja musi dotyczyć zachowania: cudzy rekord ma się nie pojawić w HTML-u.
    $tampered = Livewire::withQueryParams(['fishery' => $ownFishery->id])
        ->test($listPage)
        ->set('fisheryId', $foreignFishery->id);

    expect($tampered->html())->not->toContain($label);

    // Wyzerowanie właściwości też nie może odsłonić cudzych rekordów — dawny filtr
    // był warunkowy, więc przy `null` nie dokładał żadnego ograniczenia i lista
    // pokazywała wszystko.
    $nulled = Livewire::withQueryParams(['fishery' => $ownFishery->id])
        ->test($listPage)
        ->set('fisheryId', null);

    expect($nulled->html())->not->toContain($label);
})->with([
    [ListPositions::class, Position::class, ['name' => 'Cudze stanowisko'], 'Cudze stanowisko'],
    [ListAdditionalServices::class, AdditionalService::class, ['name' => 'Cudza usluga'], 'Cudza usluga'],
    [ListLongTermPermits::class, LongTermPermit::class, ['description' => 'Cudze pozwolenie'], 'Cudze pozwolenie'],
]);

test('Owner can not move a sub-resource under a fishery he does not own by editing it.', function (
    string $editPage,
    string $model,
    array $attributes,
) {
    // ⚠️ `mount()` strony edycji sprawdza łowisko rekordu SPRZED zmiany, a `fishery_id`
    // jest polem `Hidden` — bez bramki w `mutateFormDataBeforeSave()` dało się przenieść
    // własny rekord pod cudze łowisko. Ta ścieżka umknęła pierwszemu przeglądowi.
    Filament::setCurrentPanel('owner');

    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);
    $otherUser = User::factory()->create();
    $ownFishery = Fishery::factory()->forUser($owner)->create();
    $foreignFishery = Fishery::factory()->forUser($otherUser)->create();
    // ⚠️ Atrybuty ustawiane wprost: fabryka pozwoleń losuje `valid_from`/`valid_to`,
    // które przy ponownym zapisie łamią regułę `valid_to >= valid_from` i test padał
    // na walidacji, a nie na tym, co miał sprawdzać.
    $record = $model::factory()->create([
        ...$attributes,
        'is_active' => true,
        'fishery_id' => $ownFishery->id,
    ]);

    $this->actingAs($owner);

    Livewire::test($editPage, ['record' => $record->getKey()])
        ->fillForm(['fishery_id' => $foreignFishery->id])
        ->call('save');

    expect($record->fresh()->fishery_id)->toBe($ownFishery->id);

    // ⚠️ Kontrola POZYTYWNA w OSOBNYM wywołaniu — przy podmianie bramka słusznie
    // przerywa żądanie, więc nie da się tam nic asertować o formularzu. Bez tej
    // kontroli asercja wyżej przechodzi także wtedy, gdy zapis nie działa w ogóle
    // (np. ktoś dodał do formularza pole `required()`, którego test nie wypełnia).
    Livewire::test($editPage, ['record' => $record->getKey()])
        ->fillForm(['fishery_id' => $ownFishery->id, 'is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($record->fresh()->is_active)->toBeFalsy();
})->with([
    [EditPosition::class, Position::class, []],
    [EditAdditionalService::class, AdditionalService::class, []],
    [EditLongTermPermit::class, LongTermPermit::class, [
        'valid_from' => '2030-01-01',
        'valid_to' => '2030-12-31',
    ]],
]);

test('Owner can not create a sub-resource under a fishery he does not own.', function (
    string $createPage,
    string $model,
    array $formData,
) {
    // ⚠️ `fishery_id` jest w formularzu polem `Hidden`, czyli danymi od klienta,
    // a polityka przy tworzeniu nie widzi rekordu nadrzędnego i przepuszcza każdego
    // właściciela. Bramką jest `Helper::forceVerifiedFishery()` w
    // `mutateFormDataBeforeCreate()` — bezstanowa, bo żądanie zapisu leci na
    // `/livewire/update` i nie niesie ani `?fishery`, ani niczego z `mount()`.
    Filament::setCurrentPanel('owner');

    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);
    $otherUser = User::factory()->create();
    $ownFishery = Fishery::factory()->forUser($owner)->create();
    $foreignFishery = Fishery::factory()->forUser($otherUser)->create();

    $this->actingAs($owner);

    Livewire::withQueryParams(['fishery' => $ownFishery->id])
        ->test($createPage)
        ->fillForm([...$formData, 'fishery_id' => $foreignFishery->id])
        ->call('create');

    expect($model::query()->where('fishery_id', $foreignFishery->id)->exists())->toBeFalse();

    // ⚠️ Kontrola POZYTYWNA: ten sam formularz z WŁASNYM łowiskiem musi się zapisać.
    // Bez niej asercja wyżej przechodzi także wtedy, gdy zapis nie działa w ogóle.
    Livewire::withQueryParams(['fishery' => $ownFishery->id])
        ->test($createPage)
        ->fillForm([...$formData, 'fishery_id' => $ownFishery->id])
        ->call('create')
        ->assertHasNoFormErrors();

    expect($model::query()->where('fishery_id', $ownFishery->id)->exists())->toBeTrue();
})->with([
    [CreatePosition::class, Position::class, ['name' => 'Podmienione stanowisko', 'is_active' => true]],
    [CreateAdditionalService::class, AdditionalService::class, ['name' => 'Podmieniona usługa', 'is_active' => true, 'price' => '10']],
    [CreateLongTermPermit::class, LongTermPermit::class, ['description' => 'Podmienione pozwolenie', 'is_active' => true, 'price' => '10']],
]);
