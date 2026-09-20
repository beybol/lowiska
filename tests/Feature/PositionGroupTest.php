<?php

namespace Tests\Feature;

use App\Enums\PositionStatus;
use App\Filament\Resources\PositionGroupResource;
use App\Filament\Resources\PositionGroupResource\Pages\EditPositionGroup;
use App\Filament\Resources\PositionGroupResource\Pages\ListPositionGroups;
use App\Helpers\Helper;
use App\Models\AdditionalService;
use App\Models\Fishery;
use App\Models\LongTermPermit;
use App\Models\Position;
use App\Models\PositionGroup;
use App\Models\User;
use App\Services\PositionLabelDuplicateGuard;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

/**
 * Grupy stanowisk: relacja wiele-do-wielu, zawężenie widoczności i niezmienniki,
 * które zadanie 014 wnosi do samej tabeli `positions`.
 */
beforeEach(function () {
    Filament::setCurrentPanel('owner');
});

function ownerWithFisheryForGroups(): array
{
    $owner = User::factory()->create(['name' => 'Wlasciciel Testowy']);
    Helper::addOwnerRole($owner);

    return [$owner, Fishery::factory()->forUser($owner)->create()];
}

test('a position belongs to many groups and a group collects many positions', function () {
    [, $fishery] = ownerWithFisheryForGroups();

    $first = PositionGroup::factory()->create(['fishery_id' => $fishery->id]);
    $second = PositionGroup::factory()->create(['fishery_id' => $fishery->id]);
    $position = Position::factory()->create(['fishery_id' => $fishery->id]);
    $other = Position::factory()->create(['fishery_id' => $fishery->id]);

    $position->groups()->sync([$first->id, $second->id]);
    $first->positions()->syncWithoutDetaching([$other->id]);

    expect($position->groups()->pluck('position_groups.id')->all())
        ->toEqualCanonicalizing([$first->id, $second->id])
        ->and($first->positions()->count())->toBe(2)
        ->and($second->positions()->count())->toBe(1);
});

test('a group carries no attributes and no state of its own', function () {
    // Niezmiennik z „Rozstrzygnięć": grupa jest ETYKIETĄ. Gdyby dostała kolumnę stanu
    // albo cech, wróciłoby dziedziczenie tylnymi drzwiami — a wraz z nim reguła
    // „co wygrywa", której to zadanie świadomie nie wprowadza.
    expect(Schema::getColumnListing('position_groups'))->toEqualCanonicalizing([
        'id', 'fishery_id', 'name', 'description', 'created_at', 'updated_at', 'deleted_at',
    ]);
});

test('deleting a fishery for good takes its groups with it', function () {
    [, $fishery] = ownerWithFisheryForGroups();
    PositionGroup::factory()->create(['fishery_id' => $fishery->id]);

    $fishery->forceDelete();

    expect(PositionGroup::withTrashed()->where('fishery_id', $fishery->id)->count())->toBe(0);
});

test('owner does not see groups of a fishery he does not own', function () {
    [$owner] = ownerWithFisheryForGroups();
    $foreign = Fishery::factory()->forUser(User::factory()->create())->create();
    $foreignGroup = PositionGroup::factory()->create([
        'fishery_id' => $foreign->id,
        'name' => 'Grupa Cudza XYZ',
    ]);

    $this->actingAs($owner);

    // Zawężenie działa na wiązaniu rekordu, więc cudza grupa NIE ISTNIEJE dla tej
    // strony — nie „istnieje, ale zabronione".
    $this->get(PositionGroupResource::getUrl('edit', ['record' => $foreignGroup]))
        ->assertNotFound();
});

test('owner can not list groups of a fishery he does not own', function () {
    [$owner] = ownerWithFisheryForGroups();
    $foreign = Fishery::factory()->forUser(User::factory()->create())->create();

    $this->actingAs($owner);

    Livewire::withQueryParams(['fishery' => $foreign->id])
        ->test(ListPositionGroups::class)
        ->assertNotFound();
});

test('owner can not move his group under a fishery he does not own', function () {
    [$owner, $fishery] = ownerWithFisheryForGroups();
    $foreign = Fishery::factory()->forUser(User::factory()->create())->create();
    $group = PositionGroup::factory()->create(['fishery_id' => $fishery->id]);

    $this->actingAs($owner);

    Livewire::test(EditPositionGroup::class, ['record' => $group->getKey()])
        ->fillForm(['fishery_id' => $foreign->id])
        ->call('save');

    // `fishery_id` jest w formularzu polem `Hidden`, czyli danymi od klienta —
    // bramka przy zapisie jest jedynym, co to zatrzymuje.
    expect($group->fresh()->fishery_id)->toBe($fishery->id);
});

test('a position label can not repeat within one fishery', function () {
    [, $fishery] = ownerWithFisheryForGroups();
    Position::factory()->create(['fishery_id' => $fishery->id, 'name' => 'Stanowisko 1']);

    expect(fn () => Position::factory()->create([
        'fishery_id' => $fishery->id,
        'name' => 'Stanowisko 1',
    ]))->toThrow(QueryException::class);
});

test('the label of a soft deleted position does not return to circulation', function () {
    [, $fishery] = ownerWithFisheryForGroups();
    $withdrawn = Position::factory()->create(['fishery_id' => $fishery->id, 'name' => 'Stanowisko 7']);
    $withdrawn->delete();

    // To jest CEL indeksu, nie efekt uboczny: wycofana etykieta zostaje zajęta, żeby
    // historia pozwoleń nie zaczęła wskazywać na „to samo" stanowisko.
    expect(fn () => Position::factory()->create([
        'fishery_id' => $fishery->id,
        'name' => 'Stanowisko 7',
    ]))->toThrow(QueryException::class);
});

test('the same label is allowed in two different fisheries', function () {
    [, $fishery] = ownerWithFisheryForGroups();
    $second = Fishery::factory()->create();

    Position::factory()->create(['fishery_id' => $fishery->id, 'name' => 'Pomost']);
    Position::factory()->create(['fishery_id' => $second->id, 'name' => 'Pomost']);

    expect(Position::where('name', 'Pomost')->count())->toBe(2);
});

test('the duplicate guard names the offending labels instead of failing on a database error', function () {
    [, $fishery] = ownerWithFisheryForGroups();

    $guard = new PositionLabelDuplicateGuard;

    // Na zmigrowanej bazie duplikatów nie ma — indeks ich nie dopuści.
    expect($guard->find())->toBeEmpty();

    // Komunikat musi wskazywać WINNE rekordy: błąd bazy („Duplicate entry … for key")
    // nie mówi, które stanowiska poprawić, więc naprawa zaczynałaby się od pisania
    // własnego zapytania diagnostycznego.
    $message = $guard->message(collect([
        (object) ['fishery_id' => $fishery->id, 'name' => 'Pomost', 'total' => 2],
    ]));

    expect($message)
        ->toContain('Pomost')
        ->toContain((string) $fishery->id)
        ->toContain('usuniętych miękko');
});

test('existing links to permits and services survive the new columns', function () {
    [, $fishery] = ownerWithFisheryForGroups();
    $position = Position::factory()->create(['fishery_id' => $fishery->id]);
    $permit = LongTermPermit::factory()->create(['fishery_id' => $fishery->id]);
    $service = AdditionalService::factory()->create(['fishery_id' => $fishery->id]);

    $position->longTermPermits()->attach($permit->id);
    $position->additionalServices()->attach($service->id, ['is_required' => true]);

    $fresh = $position->fresh();

    expect($fresh->longTermPermits)->toHaveCount(1)
        ->and($fresh->additionalServices)->toHaveCount(1)
        ->and($fresh->additionalServices->first()->pivot->is_required)->toBeTruthy();
});

test('the available scope counts by the new state and is_active is gone', function () {
    [, $fishery] = ownerWithFisheryForGroups();
    Position::factory()->create(['fishery_id' => $fishery->id, 'status' => PositionStatus::Available]);
    Position::factory()->create(['fishery_id' => $fishery->id, 'status' => PositionStatus::Withdrawn]);

    expect($fishery->positions()->available()->count())->toBe(1)
        ->and(Schema::hasColumn('positions', 'is_active'))->toBeFalse();
});
