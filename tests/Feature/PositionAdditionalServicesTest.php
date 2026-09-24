<?php

namespace Tests\Feature;

use App\Enums\PositionStatus;
use App\Filament\Resources\PositionResource\Pages\CreatePosition;
use App\Filament\Resources\PositionResource\Pages\EditPosition;
use App\Models\AdditionalService;
use App\Models\Company;
use App\Models\Fishery;
use App\Models\Position;
use App\Models\User;
use App\Services\OwnerRoleProvisioner;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Regresja dla zadania 012: formularz stanowiska startował z JEDNĄ pustą pozycją
 * w liście usług dodatkowych (domyślne `Repeater::defaultItems(1)` Filamenta).
 *
 * ⚠️ Skutek nie był kosmetyczny: wybór usługi w tym wierszu jest `required()`,
 * więc stanowisko bez usług dodatkowych **nie dawało się zapisać**, dopóki
 * użytkownik nie domyślił się usunąć pustego wiersza. Usługi dodaje się
 * przyciskiem „Dodaj usługę dodatkową".
 */
test('the additional services list starts empty so a position can be saved without any', function () {
    $owner = User::factory()->create();
    OwnerRoleProvisioner::addOwnerRole($owner);
    $this->actingAs($owner);
    Filament::setCurrentPanel('owner');

    $company = Company::factory()->forUser($owner)->create();
    $fishery = Fishery::factory()->create([
        'company_id' => $company->id,
        'user_id' => $owner->id,
    ]);

    $component = Livewire::withQueryParams(['fishery' => $fishery->id])
        ->test(CreatePosition::class, ['fishery' => $fishery->id]);

    // Żadnego pustego wiersza usługi na starcie.
    expect($component->get('data.additionalServices'))->toBe([]);
});

test('a position without additional services saves successfully', function () {
    $owner = User::factory()->create();
    OwnerRoleProvisioner::addOwnerRole($owner);
    $this->actingAs($owner);
    Filament::setCurrentPanel('owner');

    $company = Company::factory()->forUser($owner)->create();
    $fishery = Fishery::factory()->create([
        'company_id' => $company->id,
        'user_id' => $owner->id,
    ]);

    $position = Position::factory()->make(['fishery_id' => $fishery->id]);

    Livewire::withQueryParams(['fishery' => $fishery->id])
        ->test(CreatePosition::class)
        ->fillForm([
            'name' => $position->name,
            'fishery_id' => $fishery->id,
            'status' => PositionStatus::Available->value,
            // Od zadania 014 pojemność jest wymagana przy zapisie stanowiska.
            'max_anglers' => 2,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Position::query()->where('fishery_id', $fishery->id)->exists())->toBeTrue();
});

/**
 * ⚠️ Przypięcie usługi NIEAKTYWNEJ zostaje (zadanie 020) — formularz stanowiska musi je pokazać.
 * Bez tego wiersz repeatera miał wartość spoza opcji, a zapis stanowiska odpadał na walidacji
 * albo gubił przypięcie (przegląd pakietu 017–021).
 */
test('a pinned inactive service stays on the position form and survives saving it', function () {
    $owner = User::factory()->create();
    OwnerRoleProvisioner::addOwnerRole($owner);
    $this->actingAs($owner);
    Filament::setCurrentPanel('owner');

    $company = Company::factory()->forUser($owner)->create();
    $fishery = Fishery::factory()->create(['company_id' => $company->id, 'user_id' => $owner->id]);
    $position = Position::factory()->create(['fishery_id' => $fishery->id, 'max_anglers' => 2]);
    $dormant = AdditionalService::factory()->create([
        'fishery_id' => $fishery->id, 'name' => 'Lodka zimowa', 'is_active' => false,
    ]);
    $otherInactive = AdditionalService::factory()->create([
        'fishery_id' => $fishery->id, 'name' => 'Nieprzypieta', 'is_active' => false,
    ]);
    $position->additionalServices()->attach($dormant->id, ['is_required' => true]);

    $page = Livewire::test(EditPosition::class, ['record' => $position->getRouteKey()])
        ->assertSee('Lodka zimowa ('.__('inactive').')')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($position->additionalServices()->whereKey($dormant->id)->first()?->pivot->is_required)->toBeTruthy();

    // Nieaktywnej usługi, której stanowisko nie ma, nie da się dobrać nowym wierszem.
    $page->assertDontSee('Nieprzypieta');
});
