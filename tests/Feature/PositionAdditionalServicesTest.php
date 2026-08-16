<?php

namespace Tests\Feature;

use App\Filament\Resources\PositionResource\Pages\CreatePosition;
use App\Helpers\Helper;
use App\Models\Company;
use App\Models\Fishery;
use App\Models\Position;
use App\Models\User;
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
    Helper::addOwnerRole($owner);
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
    Helper::addOwnerRole($owner);
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
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Position::query()->where('fishery_id', $fishery->id)->exists())->toBeTrue();
});
