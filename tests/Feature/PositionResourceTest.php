<?php

namespace Tests\Feature;

use App\Filament\Resources\FisheryResource;
use App\Filament\Resources\FisheryResource\RelationManagers\PositionsRelationManager;
use App\Filament\Resources\PositionResource\Pages\CreatePosition;
use App\Models\Company;
use App\Models\Fishery;
use App\Models\Position;

/**
 * Zadanie 012: po utworzeniu stanowiska przekierowanie prowadzi na **zakładkę
 * stanowisk w hubie „Zarządzaj łowiskiem"**, a nie na samotną stronę listy —
 * ta druga wypada poza kontekst łowiska. Dotyczy obu paneli, bo `PositionResource`
 * jest tą samą klasą zarejestrowaną wprost w `OwnerPanelProvider` (patrz treść
 * zadania 011, „Ważny fakt architektoniczny").
 *
 * ⚠️ Zakładkę identyfikuje **pozycja** w `FisheryResource::getRelations()`, nie nazwa
 * klasy — dlatego oczekiwany adres liczymy z tej samej tablicy, zamiast wpisywać numer.
 *
 * ⚠️ Ten test celowo NIE idzie przez cykl Livewire: sprawdza gałąź fallbacku
 * `$this->record->fishery_id`, czyli sytuację bez parametru `?fishery` w żądaniu.
 * Gałąź `request()->get('fishery')` pokrywa `OwnerPanelTest` przez
 * `Livewire::withQueryParams()`.
 *
 * ⚠️ Wcześniej stało tu, że `withQueryParams()` nie dowozi query stringa do
 * `mount()` — to nieprawda po upgrade z zadania 009. Reguła jest opisana raz,
 * w `docs/conventions/panel-admina.md` §4.
 */
test('creating a position redirects to its tab in the fishery hub', function () {
    $company = Company::factory()->create();
    $fishery = Fishery::factory()->create(['company_id' => $company->id]);
    $position = Position::factory()->create(['fishery_id' => $fishery->id]);

    $page = new CreatePosition;
    $page->record = $position;

    expect($page->getRedirectUrl())->toBe(FisheryResource::getUrl('manage', [
        'record' => $fishery,
        'relation' => array_search(PositionsRelationManager::class, FisheryResource::getRelations(), true),
    ]));
});
