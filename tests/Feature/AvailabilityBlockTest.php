<?php

namespace Tests\Feature;

use App\Enums\BlockEffect;
use App\Enums\SelectionKind;
use App\Filament\Resources\AvailabilityBlockResource;
use App\Filament\Resources\AvailabilityBlockResource\Pages\CreateAvailabilityBlock;
use App\Filament\Resources\AvailabilityBlockResource\Pages\EditAvailabilityBlock;
use App\Filament\Resources\AvailabilityBlockResource\Pages\ListAvailabilityBlocks;
use App\Filament\Resources\PositionResource\Pages\CreatePosition;
use App\Models\AvailabilityBlock;
use App\Models\Fishery;
use App\Models\Position;
use App\Models\PositionAttribute;
use App\Models\PositionGroup;
use App\Models\User;
use App\Rules\AvailabilityBlockEffectMatchesAttribute;
use App\Rules\PositionsBelongToFishery;
use App\Services\AvailabilityBlockSelectionResolver;
use App\Services\OwnerRoleProvisioner;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Livewire\Livewire;

/**
 * Wpisy o dostępności: reguły spójności, materializowanie zbioru, granice paneli
 * i ostrzeżenie przy zakładaniu stanowiska w trakcie blokady całościowej.
 */
beforeEach(function () {
    Filament::setCurrentPanel('owner');
});

function ownerWithFisheryForBlocks(): array
{
    $owner = User::factory()->create(['name' => 'Wlasciciel Testowy']);
    OwnerRoleProvisioner::addOwnerRole($owner);

    return [$owner, Fishery::factory()->forUser($owner)->create()];
}

function ruleFailures(callable $run): array
{
    $failures = [];
    $run(function (string $message) use (&$failures): void {
        $failures[] = $message;
    });

    return $failures;
}

test('a suspension without an attribute is rejected', function () {
    $failures = ruleFailures(fn ($fail) => (new AvailabilityBlockEffectMatchesAttribute(BlockEffect::AttributeSuspended))
        ->validate('position_attribute_id', null, $fail));

    expect($failures)->toHaveCount(1);
});

test('a sale block pointing at an attribute is rejected', function () {
    $attribute = PositionAttribute::factory()->create();

    $failures = ruleFailures(fn ($fail) => (new AvailabilityBlockEffectMatchesAttribute(BlockEffect::SaleBlocked))
        ->validate('position_attribute_id', $attribute->id, $fail));

    expect($failures)->toHaveCount(1);
});

test('only a flag attribute can be suspended', function () {
    $number = PositionAttribute::factory()->number('m')->create();
    $choice = PositionAttribute::factory()->choice()->create();
    $flag = PositionAttribute::factory()->create();
    $rule = new AvailabilityBlockEffectMatchesAttribute(BlockEffect::AttributeSuspended);

    expect(ruleFailures(fn ($fail) => $rule->validate('a', $number->id, $fail)))->toHaveCount(1)
        ->and(ruleFailures(fn ($fail) => $rule->validate('a', $choice->id, $fail)))->toHaveCount(1)
        ->and(ruleFailures(fn ($fail) => $rule->validate('a', $flag->id, $fail)))->toBe([]);
});

test('an empty set of positions is rejected', function () {
    [, $fishery] = ownerWithFisheryForBlocks();

    $failures = ruleFailures(fn ($fail) => (new PositionsBelongToFishery($fishery->id))->validate('positions', [], $fail));

    expect($failures)->toHaveCount(1);
});

test('a position from another fishery is rejected', function () {
    [, $fishery] = ownerWithFisheryForBlocks();
    $own = Position::factory()->create(['fishery_id' => $fishery->id]);
    $foreign = Position::factory()->create();

    $rule = new PositionsBelongToFishery($fishery->id);

    // Identyfikatory przychodzą od klienta — samo zawężenie opcji nie wystarcza.
    expect(ruleFailures(fn ($fail) => $rule->validate('positions', [$own->id, $foreign->id], $fail)))->toHaveCount(1)
        ->and(ruleFailures(fn ($fail) => $rule->validate('positions', [$own->id], $fail)))->toBe([]);
});

test('the resolver materializes the whole fishery as a list of positions', function () {
    [, $fishery] = ownerWithFisheryForBlocks();
    $positions = Position::factory()->count(3)->create(['fishery_id' => $fishery->id]);
    Position::factory()->create(); // cudze łowisko

    $ids = app(AvailabilityBlockSelectionResolver::class)->resolve($fishery, SelectionKind::Fishery, null);

    expect($ids->all())->toEqualCanonicalizing($positions->pluck('id')->all());
});

test('the resolver follows a group and an attribute criterion', function () {
    [, $fishery] = ownerWithFisheryForBlocks();
    $inGroup = Position::factory()->create(['fishery_id' => $fishery->id]);
    $withFlag = Position::factory()->create(['fishery_id' => $fishery->id]);
    Position::factory()->create(['fishery_id' => $fishery->id]);

    $group = PositionGroup::factory()->create(['fishery_id' => $fishery->id]);
    $group->positions()->attach($inGroup->id);

    $attribute = PositionAttribute::factory()->create();
    $withFlag->attributeValues()->create(['position_attribute_id' => $attribute->id, 'value_flag' => true]);

    $resolver = app(AvailabilityBlockSelectionResolver::class);

    expect($resolver->resolve($fishery, SelectionKind::Group, $group->id)->all())->toBe([$inGroup->id])
        ->and($resolver->resolve($fishery, SelectionKind::Attribute, $attribute->id)->all())->toBe([$withFlag->id])
        ->and($resolver->resolve($fishery, SelectionKind::Manual, null))->toBeEmpty();
});

test('the resolver ignores a group belonging to another fishery', function () {
    [, $fishery] = ownerWithFisheryForBlocks();
    $foreignGroup = PositionGroup::factory()->create();
    $foreignGroup->positions()->attach(Position::factory()->create(['fishery_id' => $foreignGroup->fishery_id])->id);

    expect(app(AvailabilityBlockSelectionResolver::class)->resolve($fishery, SelectionKind::Group, $foreignGroup->id))
        ->toBeEmpty();
});

test('a position added after a whole fishery block is not covered by it', function () {
    [, $fishery] = ownerWithFisheryForBlocks();
    $existing = Position::factory()->create(['fishery_id' => $fishery->id]);
    $block = AvailabilityBlock::factory()->create([
        'fishery_id' => $fishery->id,
        'selection_kind' => SelectionKind::Fishery,
    ]);
    $block->positions()->sync(
        app(AvailabilityBlockSelectionResolver::class)->resolve($fishery, SelectionKind::Fishery, null)->all(),
    );

    $later = Position::factory()->create(['fishery_id' => $fishery->id]);

    // Zbiór jest zmaterializowany: nowe stanowisko NIE wchodzi do blokady samo.
    expect($block->positions()->pluck('positions.id')->all())->toBe([$existing->id])
        ->and($later->availabilityBlocks()->count())->toBe(0);
});

test('creating a position during a whole fishery block warns the operator', function () {
    [$owner, $fishery] = ownerWithFisheryForBlocks();
    AvailabilityBlock::factory()->openEnded()->create([
        'fishery_id' => $fishery->id,
        'selection_kind' => SelectionKind::Fishery,
        'starts_on' => now()->subDay()->toDateString(),
        'reason' => 'Zarybianie XYZ',
    ]);

    $this->actingAs($owner);

    Livewire::withQueryParams(['fishery' => $fishery->id])
        ->test(CreatePosition::class)
        ->fillForm([
            'name' => 'Stanowisko po blokadzie',
            'fishery_id' => $fishery->id,
            'max_anglers' => 2,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified();
});

test('owner can create a block through the form with a materialized set', function () {
    [$owner, $fishery] = ownerWithFisheryForBlocks();
    $positions = Position::factory()->count(2)->create(['fishery_id' => $fishery->id]);

    $this->actingAs($owner);

    Livewire::withQueryParams(['fishery' => $fishery->id])
        ->test(CreateAvailabilityBlock::class)
        ->fillForm([
            'fishery_id' => $fishery->id,
            'effect' => BlockEffect::SaleBlocked->value,
            'starts_on' => '2026-06-10',
            'ends_on' => '2026-06-20',
            'reason' => 'Zawody',
            'reason_visible' => true,
            'selection_kind' => SelectionKind::Manual->value,
            'positions' => $positions->pluck('id')->all(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $block = AvailabilityBlock::where('fishery_id', $fishery->id)->firstOrFail();

    expect($block->effect)->toBe(BlockEffect::SaleBlocked)
        ->and($block->positions()->count())->toBe(2)
        ->and($block->selection_label)->toBeNull();
});

test('a group criterion is stored as a readable label', function () {
    [$owner, $fishery] = ownerWithFisheryForBlocks();
    $position = Position::factory()->create(['fishery_id' => $fishery->id]);
    $group = PositionGroup::factory()->create(['fishery_id' => $fishery->id, 'name' => 'Brzeg polnocny']);
    $group->positions()->attach($position->id);

    $this->actingAs($owner);

    Livewire::withQueryParams(['fishery' => $fishery->id])
        ->test(CreateAvailabilityBlock::class)
        ->fillForm([
            'fishery_id' => $fishery->id,
            'effect' => BlockEffect::SaleBlocked->value,
            'starts_on' => '2026-06-10',
            'reason' => 'Remont',
            'selection_kind' => SelectionKind::Group->value,
            'position_group_id' => $group->id,
            'positions' => [$position->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(AvailabilityBlock::where('fishery_id', $fishery->id)->value('selection_label'))->toBe('Brzeg polnocny');
});

test('the form rejects an empty set and an end before the start', function () {
    [$owner, $fishery] = ownerWithFisheryForBlocks();

    $this->actingAs($owner);

    Livewire::withQueryParams(['fishery' => $fishery->id])
        ->test(CreateAvailabilityBlock::class)
        ->fillForm([
            'fishery_id' => $fishery->id,
            'effect' => BlockEffect::SaleBlocked->value,
            'starts_on' => '2026-06-20',
            'ends_on' => '2026-06-10',
            'reason' => 'Odwrocone daty',
            'selection_kind' => SelectionKind::Manual->value,
            'positions' => [],
        ])
        ->call('create')
        ->assertHasFormErrors(['positions', 'ends_on']);
});

test('owner does not see blocks of a fishery he does not own', function () {
    [$owner] = ownerWithFisheryForBlocks();
    $foreign = Fishery::factory()->forUser(User::factory()->create())->create();
    $foreignBlock = AvailabilityBlock::factory()->create(['fishery_id' => $foreign->id]);

    $this->actingAs($owner);

    $this->get(AvailabilityBlockResource::getUrl('edit', ['record' => $foreignBlock]))->assertNotFound();

    Livewire::withQueryParams(['fishery' => $foreign->id])
        ->test(ListAvailabilityBlocks::class)
        ->assertNotFound();
});

test('owner can not move his block under a fishery he does not own', function () {
    [$owner, $fishery] = ownerWithFisheryForBlocks();
    $foreign = Fishery::factory()->forUser(User::factory()->create())->create();
    $block = AvailabilityBlock::factory()->create(['fishery_id' => $fishery->id]);
    $block->positions()->attach(Position::factory()->create(['fishery_id' => $fishery->id])->id);

    $this->actingAs($owner);

    Livewire::test(EditAvailabilityBlock::class, ['record' => $block->getKey()])
        ->fillForm(['fishery_id' => $foreign->id])
        ->call('save');

    expect($block->fresh()->fishery_id)->toBe($fishery->id);
});

test('deleting a fishery for good takes its blocks with it', function () {
    [, $fishery] = ownerWithFisheryForBlocks();
    AvailabilityBlock::factory()->create(['fishery_id' => $fishery->id]);

    $fishery->forceDelete();

    expect(AvailabilityBlock::withTrashed()->where('fishery_id', $fishery->id)->count())->toBe(0);
});

/**
 * ⚠️ Regresje z przeglądu implementacji (2026-09-20). Oba testy wołają
 * `withSelectionLabel()` WPROST, nie przez formularz — pole grupy jest `Select`
 * z zawężoną listą, więc test przez `fillForm()` przechodziłby na zielono także
 * z zepsutym zawężeniem (`autoryzacja.md` §4: weryfikuj te warstwy negatywnie).
 */
test('the selection label refuses a group from another fishery', function () {
    [, $fishery] = ownerWithFisheryForBlocks();
    [, $otherFishery] = ownerWithFisheryForBlocks();

    $ownGroup = PositionGroup::factory()->create(['fishery_id' => $fishery->id, 'name' => 'Wlasna grupa']);
    $foreignGroup = PositionGroup::factory()->create(['fishery_id' => $otherFishery->id, 'name' => 'Cudza grupa']);

    // Kontrola pozytywna — bez niej test przechodziłby też wtedy, gdy etykieta
    // nie powstaje w ogóle.
    $own = AvailabilityBlockResource::withSelectionLabel([
        'fishery_id' => $fishery->id,
        'effect' => BlockEffect::SaleBlocked->value,
        'selection_kind' => SelectionKind::Group->value,
        'position_group_id' => $ownGroup->id,
    ]);

    expect($own['selection_label'])->toBe('Wlasna grupa');

    $foreign = AvailabilityBlockResource::withSelectionLabel([
        'fishery_id' => $fishery->id,
        'effect' => BlockEffect::SaleBlocked->value,
        'selection_kind' => SelectionKind::Group->value,
        'position_group_id' => $foreignGroup->id,
    ]);

    // Ma być NULL, a nie „cokolwiek innego niż cudza nazwa".
    expect($foreign['selection_label'])->toBeNull();
});

test('changing the effect away from a suspension clears the attribute', function () {
    [, $fishery] = ownerWithFisheryForBlocks();
    $attribute = PositionAttribute::factory()->create();

    $kept = AvailabilityBlockResource::withSelectionLabel([
        'fishery_id' => $fishery->id,
        'effect' => BlockEffect::AttributeSuspended->value,
        'position_attribute_id' => $attribute->id,
        'selection_kind' => SelectionKind::Fishery->value,
    ]);

    expect($kept['position_attribute_id'])->toBe($attribute->id);

    $cleared = AvailabilityBlockResource::withSelectionLabel([
        'fishery_id' => $fishery->id,
        'effect' => BlockEffect::SaleBlocked->value,
        'position_attribute_id' => $attribute->id,
        'selection_kind' => SelectionKind::Fishery->value,
    ]);

    expect($cleared['position_attribute_id'])->toBeNull();
});

/**
 * ⚠️ Akcja „Przelicz listę" jest osadzona w schemacie przez `Actions`, czyli komponent
 * BEZ ścieżki stanu. Bez jawnego `key()` Livewire nie odnajduje jej na powrotnym
 * żądaniu i klik kończy się `ActionNotResolvableException`. Testy niżej wołają ją
 * przez `TestAction`, bo tylko ta droga przechodzi przez rozwiązywanie akcji —
 * samo wołanie `AvailabilityBlockSelectionResolver` zieleniło się mimo zepsutego
 * przycisku.
 */
test('the recalculate action fills the list from a group criterion', function () {
    [$owner, $fishery] = ownerWithFisheryForBlocks();
    $inGroup = Position::factory()->create(['fishery_id' => $fishery->id]);
    Position::factory()->create(['fishery_id' => $fishery->id]);

    $group = PositionGroup::factory()->create(['fishery_id' => $fishery->id]);
    $group->positions()->attach($inGroup->id);

    $this->actingAs($owner);

    Livewire::withQueryParams(['fishery' => $fishery->id])
        ->test(CreateAvailabilityBlock::class)
        ->fillForm([
            'fishery_id' => $fishery->id,
            'selection_kind' => SelectionKind::Group->value,
            'position_group_id' => $group->id,
        ])
        ->callAction(TestAction::make('recalculate')->schemaComponent('recalculateActions'))
        ->assertHasNoActionErrors()
        ->assertFormSet(['positions' => [$inGroup->id]]);
});

test('the recalculate action fills the list with the whole fishery', function () {
    [$owner, $fishery] = ownerWithFisheryForBlocks();
    $positions = Position::factory()->count(3)->create(['fishery_id' => $fishery->id]);
    Position::factory()->create(); // cudze łowisko

    $this->actingAs($owner);

    $component = Livewire::withQueryParams(['fishery' => $fishery->id])
        ->test(CreateAvailabilityBlock::class)
        ->fillForm([
            'fishery_id' => $fishery->id,
            'selection_kind' => SelectionKind::Fishery->value,
        ])
        ->callAction(TestAction::make('recalculate')->schemaComponent('recalculateActions'))
        ->assertHasNoActionErrors();

    expect($component->get('data')['positions'])
        ->toEqualCanonicalizing($positions->pluck('id')->all());
});

/**
 * ⚠️ Pusty słownik cech jest stanem DOMYŚLNYM świeżej instalacji — `DatabaseSeeder`
 * sieje pozostałe słowniki, ale nie cechy. Opcje zależne od cech muszą wtedy zniknąć,
 * inaczej operator wybiera skutek, którego `AvailabilityBlockEffectMatchesAttribute`
 * nie pozwoli zapisać, albo kryterium, które zawsze daje pusty zbiór.
 */
test('attribute based options disappear while the dictionary has no flag attribute', function () {
    [$owner, $fishery] = ownerWithFisheryForBlocks();

    // Cecha liczbowa NIE odblokowuje tych opcji — zawiesić da się wyłącznie flagę.
    PositionAttribute::factory()->number('m')->create();

    $this->actingAs($owner);

    Livewire::withQueryParams(['fishery' => $fishery->id])
        ->test(CreateAvailabilityBlock::class)
        ->assertFormFieldExists('effect', fn (Select $field): bool => array_keys($field->getOptions()) === [BlockEffect::SaleBlocked->value])
        ->assertFormFieldExists('selection_kind', fn (Select $field): bool => ! array_key_exists(SelectionKind::Attribute->value, $field->getOptions()));
});

test('a flag attribute in the dictionary brings both options back', function () {
    [$owner, $fishery] = ownerWithFisheryForBlocks();
    PositionAttribute::factory()->create();

    $this->actingAs($owner);

    Livewire::withQueryParams(['fishery' => $fishery->id])
        ->test(CreateAvailabilityBlock::class)
        ->assertFormFieldExists('effect', fn (Select $field): bool => array_key_exists(BlockEffect::AttributeSuspended->value, $field->getOptions()))
        ->assertFormFieldExists('selection_kind', fn (Select $field): bool => array_key_exists(SelectionKind::Attribute->value, $field->getOptions()));
});

/**
 * ⚠️ Kontrola negatywna dla bramki wyżej: wpis, który JUŻ zawiesza cechę, nie może
 * stracić swojej opcji po opróżnieniu słownika — inaczej edycja czegokolwiek innego
 * (dat, powodu) po cichu gubiłaby skutek wpisu.
 */
test('an existing suspension keeps its option even with an empty dictionary', function () {
    [$owner, $fishery] = ownerWithFisheryForBlocks();
    $position = Position::factory()->create(['fishery_id' => $fishery->id]);
    $attribute = PositionAttribute::factory()->create();

    $block = AvailabilityBlock::factory()->create([
        'fishery_id' => $fishery->id,
        'effect' => BlockEffect::AttributeSuspended->value,
        'position_attribute_id' => $attribute->id,
        'selection_kind' => SelectionKind::Fishery->value,
    ]);
    $block->positions()->attach($position->id);

    $attribute->delete();

    $this->actingAs($owner);

    Livewire::test(EditAvailabilityBlock::class, ['record' => $block->getRouteKey()])
        ->assertFormFieldExists('effect', fn (Select $field): bool => array_key_exists(BlockEffect::AttributeSuspended->value, $field->getOptions()));
});
