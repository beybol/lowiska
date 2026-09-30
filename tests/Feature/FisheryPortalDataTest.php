<?php

namespace Tests\Feature;

use App\Enums\PositionStatus;
use App\Filament\Resources\FisheryResource\Pages\EditFishery;
use App\Filament\Resources\FisheryResource\Pages\ManageFishery;
use App\Filament\Resources\StateResource\Pages\CreateState;
use App\Models\Company;
use App\Models\Country;
use App\Models\Fishery;
use App\Models\Position;
use App\Models\State;
use App\Models\User;
use App\Services\OwnerRoleProvisioner;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

/**
 * Dane łowiska dla portalu — slug, slug województwa, kontakt i adresy w sieci (zadanie 030).
 *
 * ⚠️ Slug jest STAŁY i unikalny w całym portalu, łącznie z łowiskami usuniętymi miękko —
 * testy kolizji tworzą łowisko usunięte, bo zapytanie przez model z `SoftDeletes` by je pominęło.
 */
/** Łowisko właściciela z JEGO firmą — formularz właściciela oferuje wyłącznie jego firmy. */
function ownedFishery(User $owner, array $attributes = []): Fishery
{
    return Fishery::factory()->forUser($owner)->create(array_merge([
        'company_id' => Company::factory()->forUser($owner)->create(['is_verified' => true])->id,
    ], $attributes));
}

function portalOwner(): User
{
    $owner = User::factory()->create();
    OwnerRoleProvisioner::addOwnerRole($owner);
    test()->actingAs($owner);
    Filament::setCurrentPanel('owner');

    return $owner;
}

test('a fishery gets its slug from the name when it is created', function () {
    $fishery = Fishery::factory()->create(['name' => 'Łowisko Klasztorne']);

    expect($fishery->fresh()->slug)->toBe('lowisko-klasztorne');
});

test('renaming a fishery does not change its slug', function () {
    $fishery = Fishery::factory()->create(['name' => 'Łopienno']);

    $fishery->update(['name' => 'Łopienno Nowe']);

    expect($fishery->fresh()->slug)->toBe('lopienno');
});

test('a colliding slug gets a suffix, also against a soft-deleted fishery', function () {
    Fishery::factory()->create(['name' => 'Klasztorne'])->delete();
    $second = Fishery::factory()->create(['name' => 'Klasztorne']);
    $third = Fishery::factory()->create(['name' => 'KLASZTORNE!']);

    expect($second->slug)->toBe('klasztorne-2')
        ->and($third->slug)->toBe('klasztorne-3');
});

test('a name without a single usable character still gets a slug', function () {
    expect(Fishery::factory()->create(['name' => '!!!'])->slug)->toBe('lowisko');
});

test('a state slug is Polish, made from the translated name, and unique within a country', function () {
    $poland = Country::factory()->create();
    $other = Country::factory()->create();

    $greaterPoland = State::create(['name' => 'Greater Poland', 'country_id' => $poland->id]);
    $lodz = State::create(['name' => 'Lodz', 'country_id' => $poland->id]);
    $duplicate = State::create(['name' => 'Greater Poland', 'country_id' => $poland->id]);
    $abroad = State::create(['name' => 'Greater Poland', 'country_id' => $other->id]);

    expect($greaterPoland->slug)->toBe('wielkopolskie')
        ->and($lodz->slug)->toBe('lodzkie')
        ->and($duplicate->slug)->toBe('wielkopolskie-2')
        ->and($abroad->slug)->toBe('wielkopolskie');

    $greaterPoland->update(['name' => 'Wielkopolska']);

    expect($greaterPoland->fresh()->slug)->toBe('wielkopolskie');
});

test('the admin creates a state with an empty slug and gets one from the Polish name', function () {
    $this->actingAs($this->createSuperAdmin());
    $country = Country::factory()->create(['is_active' => true]);

    Livewire::test(CreateState::class)
        ->fillForm(['name' => 'Holy Cross', 'country_id' => $country->id, 'slug' => null])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(State::where('name', 'Holy Cross')->value('slug'))->toBe('swietokrzyskie');
});

test('the admin changes the slug of a fishery in the admin panel', function () {
    $this->actingAs($this->createSuperAdmin());
    Filament::setCurrentPanel('admin');
    $fishery = Fishery::factory()->create(['name' => 'Klasztorne']);

    Livewire::test(EditFishery::class, ['record' => $fishery->getKey()])
        ->assertFormFieldExists('slug')
        ->fillForm(['slug' => 'jezioro-klasztorne'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($fishery->fresh()->slug)->toBe('jezioro-klasztorne');
});

test('the admin can not set a malformed slug or one taken by a soft-deleted fishery', function () {
    $this->actingAs($this->createSuperAdmin());
    Filament::setCurrentPanel('admin');
    Fishery::factory()->create(['name' => 'Zajete'])->delete();
    $fishery = Fishery::factory()->create(['name' => 'Klasztorne']);

    foreach (['Duze-Litery', 'dwa--myslniki', 'spacja w srodku', 'zajete'] as $slug) {
        Livewire::test(EditFishery::class, ['record' => $fishery->getKey()])
            ->fillForm(['slug' => $slug])
            ->call('save')
            ->assertHasFormErrors(['slug']);
    }

    expect($fishery->fresh()->slug)->toBe('klasztorne');
});

test('the owner does not get the slug field and can not change the slug by injecting it', function () {
    $owner = portalOwner();
    $fishery = ownedFishery($owner, ['name' => 'Klasztorne']);

    Livewire::test(EditFishery::class, ['record' => $fishery->getKey()])
        ->assertFormFieldDoesNotExist('slug')
        ->fillForm(['slug' => 'przejete', 'name' => 'Klasztorne 2'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($fishery->fresh())
        ->slug->toBe('klasztorne')
        ->name->toBe('Klasztorne 2');
});

test('the owner sees the slug read-only on the fishery data page', function () {
    $owner = portalOwner();
    $fishery = Fishery::factory()->forUser($owner)->create(['name' => 'Klasztorne']);

    Livewire::test(ManageFishery::class, ['record' => $fishery->getKey()])
        ->assertSee('klasztorne');
});

test('positions_count is gone and the data page counts positions for sale', function () {
    $owner = portalOwner();
    $fishery = Fishery::factory()->forUser($owner)->create();
    Position::factory()->count(3)->create(['fishery_id' => $fishery->id, 'status' => PositionStatus::Available]);
    Position::factory()->create(['fishery_id' => $fishery->id, 'status' => PositionStatus::Withdrawn]);

    expect(Schema::hasColumn('fisheries', 'positions_count'))->toBeFalse();

    Livewire::test(ManageFishery::class, ['record' => $fishery->getKey()])
        ->assertSchemaStateSet(['positions_for_sale' => 3], 'infolist');
});

test('the owner saves the contact and the links of the fishery', function () {
    $owner = portalOwner();
    $fishery = ownedFishery($owner);

    Livewire::test(EditFishery::class, ['record' => $fishery->getKey()])
        ->fillForm([
            'phone' => '+48 661 231 623',
            'email' => 'kontakt@klasztorne.pl',
            'contact_hours' => '10:00–20:00, także SMS',
            'website_url' => 'https://klasztorne.pl',
            'facebook_url' => 'https://www.facebook.com/klasztorne',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($fishery->fresh())
        ->phone->toBe('+48 661 231 623')
        ->email->toBe('kontakt@klasztorne.pl')
        ->contact_hours->toBe('10:00–20:00, także SMS')
        ->website_url->toBe('https://klasztorne.pl')
        ->facebook_url->toBe('https://www.facebook.com/klasztorne');
});

test('the contact fields refuse values that are not a phone, an e-mail or a proper link', function (string $field, string $value) {
    $owner = portalOwner();
    $fishery = ownedFishery($owner);

    Livewire::test(EditFishery::class, ['record' => $fishery->getKey()])
        ->fillForm([$field => $value])
        ->call('save')
        ->assertHasFormErrors([$field]);
})->with([
    'phone with letters' => ['phone', '517 971 00x'],
    'phone too short' => ['phone', '12-34'],
    'e-mail' => ['email', 'kontakt@'],
    'website without http' => ['website_url', 'ftp://klasztorne.pl'],
    'facebook on another host' => ['facebook_url', 'https://evilfacebook.com/klasztorne'],
    'facebook not a link' => ['facebook_url', 'facebook.com/klasztorne'],
]);
