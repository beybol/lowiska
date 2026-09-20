<?php

namespace Tests\Feature;

use App\Enums\PositionAttributeType;
use App\Filament\Resources\PositionAttributeResource;
use App\Filament\Resources\PositionAttributeResource\Pages\CreatePositionAttribute;
use App\Filament\Resources\PositionAttributeResource\Pages\EditPositionAttribute;
use App\Filament\Resources\PositionAttributeResource\Pages\ListPositionAttributes;
use App\Filament\Resources\PositionResource;
use App\Filament\Resources\PositionResource\Pages\CreatePosition;
use App\Filament\Resources\PositionResource\Pages\EditPosition;
use App\Models\Fishery;
use App\Models\Position;
use App\Models\PositionAttribute;
use App\Models\PositionAttributeOption;
use App\Models\User;
use App\Services\OwnerRoleProvisioner;
use App\Services\PositionAttributeWriter;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

/**
 * Słownik cech stanowisk — wspólny dla portalu, wyłącznie w rękach administratora.
 */
test('an administrator can create an attribute with its options', function () {
    $admin = $this->createSuperAdmin();
    Filament::setCurrentPanel('admin');
    $this->actingAs($admin);

    Livewire::test(CreatePositionAttribute::class)
        ->fillForm([
            'name' => 'Rodzaj brzegu',
            'type' => PositionAttributeType::Choice->value,
            'options' => [
                ['name' => 'Trawa', 'sort_order' => 1],
                ['name' => 'Pomost', 'sort_order' => 2],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $attribute = PositionAttribute::where('name', 'Rodzaj brzegu')->firstOrFail();

    expect($attribute->type)->toBe(PositionAttributeType::Choice)
        ->and($attribute->options()->count())->toBe(2);
});

test('creating an attribute redirects to the list, not to the edit view', function () {
    $admin = $this->createSuperAdmin();
    Filament::setCurrentPanel('admin');
    $this->actingAs($admin);

    $page = Livewire::test(CreatePositionAttribute::class)->instance();

    // `docs/conventions/panel-admina.md` §4 — standardowy CRUD wraca na listę.
    expect($page->getRedirectUrl())->toBe(PositionAttributeResource::getUrl('index'));
});

test('an owner can not manage the shared dictionary', function () {
    $owner = User::factory()->create(['name' => 'Wlasciciel Testowy']);
    OwnerRoleProvisioner::addOwnerRole($owner);
    Filament::setCurrentPanel('admin');

    // ⚠️ Słownik jest wspólny dla portalu i to jest warunek, pod którym filtrowanie
    // przez wszystkie łowiska ma sens — właściciel nie może go edytować.
    expect(PositionAttributeResource::canViewAny())->toBeFalse()
        ->and(PositionAttributeResource::canCreate())->toBeFalse();
});

test('the dictionary has no per fishery column', function () {
    // Cechy własne łowiska są odrzucone CO DO ZASADY, nie odłożone — dlatego
    // w słowniku nie powstaje nawet kolumna przygotowawcza. Pusta furtka do czegoś,
    // czego świadomie nie chcemy, z czasem zostałaby użyta.
    expect(Schema::hasColumn('position_attributes', 'fishery_id'))->toBeFalse();
});

test('the unit belongs only to numeric attributes', function () {
    $number = PositionAttribute::factory()->number('m')->create();
    $flag = PositionAttribute::factory()->create();

    expect($number->unit)->toBe('m')
        ->and($flag->unit)->toBeNull();
});

test('options are ordered by their sort order', function () {
    $attribute = PositionAttribute::factory()->choice()->create();
    PositionAttributeOption::factory()->create([
        'position_attribute_id' => $attribute->id,
        'name' => 'Drugi',
        'sort_order' => 2,
    ]);
    PositionAttributeOption::factory()->create([
        'position_attribute_id' => $attribute->id,
        'name' => 'Pierwszy',
        'sort_order' => 1,
    ]);

    expect($attribute->options()->pluck('name')->all())->toBe(['Pierwszy', 'Drugi']);
});

test('deleting an attribute takes its options with it', function () {
    $attribute = PositionAttribute::factory()->choice()->create();
    PositionAttributeOption::factory()->create(['position_attribute_id' => $attribute->id]);

    $attribute->forceDelete();

    expect(PositionAttributeOption::withTrashed()->where('position_attribute_id', $attribute->id)->count())
        ->toBe(0);
});

test('a new dictionary entry shows up in the position form without any code change', function () {
    $owner = User::factory()->create(['name' => 'Wlasciciel Testowy']);
    OwnerRoleProvisioner::addOwnerRole($owner);
    $fishery = Fishery::factory()->forUser($owner)->create();
    $attribute = PositionAttribute::factory()->create(['name' => 'Pomost']);

    Filament::setCurrentPanel('owner');
    $this->actingAs($owner);

    // ⚠️ To jest sedno „formularz generuje się ze słownika": dopisanie cechy przez
    // administratora udostępnia ją we wszystkich łowiskach bez migracji i bez kodu.
    Livewire::withQueryParams(['fishery' => $fishery->id])
        ->test(CreatePosition::class)
        ->assertFormFieldExists('position_attributes.'.$attribute->id);
});

test('a position saves with its attribute values from the form', function () {
    $owner = User::factory()->create(['name' => 'Wlasciciel Testowy']);
    OwnerRoleProvisioner::addOwnerRole($owner);
    $fishery = Fishery::factory()->forUser($owner)->create();
    $flag = PositionAttribute::factory()->create(['name' => 'Pomost']);
    $number = PositionAttribute::factory()->number('m')->create(['name' => 'Do parkingu']);

    Filament::setCurrentPanel('owner');
    $this->actingAs($owner);

    Livewire::withQueryParams(['fishery' => $fishery->id])
        ->test(CreatePosition::class)
        ->fillForm([
            'name' => 'Stanowisko z cechami',
            'fishery_id' => $fishery->id,
            'max_anglers' => 2,
            'position_attributes' => [
                $flag->id => 1,
                $number->id => '35',
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $position = Position::where('name', 'Stanowisko z cechami')->firstOrFail();
    $values = $position->attributeValues()->get()->keyBy('position_attribute_id');

    expect($values[$flag->id]->value_flag)->toBeTrue()
        ->and($values[$number->id]->value_number)->toEqual('35.00');
});

test('a position without max anglers is rejected', function () {
    $owner = User::factory()->create(['name' => 'Wlasciciel Testowy']);
    OwnerRoleProvisioner::addOwnerRole($owner);
    $fishery = Fishery::factory()->forUser($owner)->create();

    Filament::setCurrentPanel('owner');
    $this->actingAs($owner);

    Livewire::withQueryParams(['fishery' => $fishery->id])
        ->test(CreatePosition::class)
        ->fillForm([
            'name' => 'Stanowisko bez pojemnosci',
            'fishery_id' => $fishery->id,
        ])
        ->call('create')
        ->assertHasFormErrors(['max_anglers']);
});

test('max people lower than max anglers is rejected', function () {
    $owner = User::factory()->create(['name' => 'Wlasciciel Testowy']);
    OwnerRoleProvisioner::addOwnerRole($owner);
    $fishery = Fishery::factory()->forUser($owner)->create();

    Filament::setCurrentPanel('owner');
    $this->actingAs($owner);

    Livewire::withQueryParams(['fishery' => $fishery->id])
        ->test(CreatePosition::class)
        ->fillForm([
            'name' => 'Stanowisko sprzeczne',
            'fishery_id' => $fishery->id,
            'max_anglers' => 4,
            'max_people' => 2,
        ])
        ->call('create')
        ->assertHasFormErrors(['max_people']);
});

test('max people may be left empty', function () {
    $owner = User::factory()->create(['name' => 'Wlasciciel Testowy']);
    OwnerRoleProvisioner::addOwnerRole($owner);
    $fishery = Fishery::factory()->forUser($owner)->create();

    Filament::setCurrentPanel('owner');
    $this->actingAs($owner);

    // Porównanie ma się w ogóle nie odbyć, gdy pole jest puste — stąd reguła
    // porównawcza zamiast `gte:max_anglers`.
    Livewire::withQueryParams(['fishery' => $fishery->id])
        ->test(CreatePosition::class)
        ->fillForm([
            'name' => 'Stanowisko bez limitu osob',
            'fishery_id' => $fishery->id,
            'max_anglers' => 4,
        ])
        ->call('create')
        ->assertHasNoFormErrors();
});

test('the dictionary list is reachable for an administrator', function () {
    $admin = $this->createSuperAdmin();
    Filament::setCurrentPanel('admin');
    $this->actingAs($admin);

    Livewire::test(ListPositionAttributes::class)->assertSuccessful();
});

/**
 * ⚠️ Regresja z przeglądu implementacji (2026-09-20): cecha kasuje się MIĘKKO, a
 * `position_attribute_values` kaskaduje tylko przy twardym usunięciu — po usunięciu
 * cechy ze słownika zostawał wiersz bez definicji i `->attribute->type` wywracało
 * formularz edycji KAŻDEGO stanowiska, które miało tę cechę wypełnioną.
 * Istniejący test usuwania używa `forceDelete()`, czyli innej ścieżki.
 */
test('soft deleting an attribute does not break the position form', function () {
    $owner = User::factory()->create(['name' => 'Wlasciciel Testowy']);
    OwnerRoleProvisioner::addOwnerRole($owner);
    $fishery = Fishery::factory()->forUser($owner)->create();

    $kept = PositionAttribute::factory()->create(['name' => 'Pomost']);
    $removed = PositionAttribute::factory()->create(['name' => 'Zadaszenie']);

    $position = Position::factory()->create(['fishery_id' => $fishery->id]);
    app(PositionAttributeWriter::class)->writeForPosition($position, [
        $kept->id => 1,
        $removed->id => 1,
    ]);

    $removed->delete();

    $data = PositionResource::getEloquentFormData(
        $position->fresh()->load('additionalServices', 'attributeValues.attribute', 'groups')
    );

    // Wartość usuniętej cechy wypada z formularza, wartość pozostałej zostaje.
    expect($data['position_attributes'])->toHaveKey($kept->id)
        ->and($data['position_attributes'])->not->toHaveKey($removed->id);

    // Osierocony wiersz NADAL jest w bazie — filtr go ukrywa, nie sprząta.
    // Sprzątanie to osobna decyzja (zadanie 022).
    expect($position->attributeValues()->count())->toBe(2);
});

test('an administrator can edit an attribute and its options on a full page', function () {
    $admin = $this->createSuperAdmin();
    Filament::setCurrentPanel('admin');
    $this->actingAs($admin);

    $attribute = PositionAttribute::factory()->choice()->create(['name' => 'Rodzaj brzegu']);
    PositionAttributeOption::factory()->create([
        'position_attribute_id' => $attribute->id,
        'name' => 'Trawiasty',
        'sort_order' => 0,
    ]);

    // ⚠️ Cały powód przejścia z `ManageRecords` na `ListRecords` to repeater opcji,
    // który w modalu jest ściśnięty — więc to właśnie on musi być pokryty.
    Livewire::test(EditPositionAttribute::class, ['record' => $attribute->getKey()])
        ->assertSuccessful()
        ->fillForm([
            'name' => 'Rodzaj brzegu',
            'type' => PositionAttributeType::Choice->value,
            'options' => [
                ['name' => 'Trawiasty', 'sort_order' => 0],
                ['name' => 'Kamienisty', 'sort_order' => 1],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($attribute->fresh()->options()->pluck('name')->sort()->values()->all())
        ->toBe(['Kamienisty', 'Trawiasty']);
});

/**
 * Wartości cechy tak/nie mają polskie etykiety.
 *
 * Klucze `Yes`/`No` długo nie istniały w `lang/pl.json`, więc obie opcje wyświetlały
 * się po angielsku pośród przetłumaczonego formularza. Test jest tak płaski, bo nic
 * innego nie pilnuje kompletności słownika tłumaczeń.
 */
test('the yes/no values are translated into Polish', function () {
    app()->setLocale('pl');

    expect(__('Yes'))->toBe('Tak')
        ->and(__('No'))->toBe('Nie')
        ->and(__('Not specified'))->toBe('Nie określono');
});

/**
 * ⚠️ „Nie" na cesze tak/nie musi PRZEŻYĆ powrót do formularza.
 *
 * Wartość wraca z bazy jako `bool`, a Filament dopasowuje stan do kluczy opcji po
 * rzutowaniu na string — `(string) false` to PUSTY łańcuch, czyli dokładnie to, czym
 * jest brak wyboru. Pole pokazywało „nie określono" zamiast „nie", a ZAPIS takiego
 * formularza kasował wiersz. Błąd dotyczył wyłącznie jednej z dwóch wartości, bo
 * `(string) true` to „1" — dlatego „tak" jest tu kontrolą pozytywną, bez której
 * test przechodziłby także na zepsutej hydratacji.
 */
test('a no value survives reopening and saving the position form', function () {
    $owner = User::factory()->create(['name' => 'Wlasciciel Testowy']);
    OwnerRoleProvisioner::addOwnerRole($owner);
    $fishery = Fishery::factory()->forUser($owner)->create();
    $attribute = PositionAttribute::factory()->create(['name' => 'Pomost']);

    Filament::setCurrentPanel('owner');
    $this->actingAs($owner);

    foreach ([['stored' => false, 'state' => '0'], ['stored' => true, 'state' => '1']] as $case) {
        $position = Position::factory()->create(['fishery_id' => $fishery->id]);
        app(PositionAttributeWriter::class)->writeForPosition($position, [$attribute->id => $case['stored']]);

        $component = Livewire::test(EditPosition::class, ['record' => $position->getRouteKey()]);

        // Stan pola ma dać się dopasować do klucza opcji. Pusty łańcuch — do którego
        // Filament sprowadza `false` — jest nie do odróżnienia od braku wyboru.
        expect($component->get('data')['position_attributes'][$attribute->id] ?? null)
            ->toBe($case['state']);

        // Zapis bez żadnej zmiany nie ma prawa ruszyć wartości.
        $component->call('save')->assertHasNoFormErrors();

        expect($position->attributeValues()->where('position_attribute_id', $attribute->id)->value('value_flag'))
            ->toBe($case['stored']);
    }
});
