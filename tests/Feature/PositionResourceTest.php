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
 * ⚠️ Nie przez pełny cykl Livewire (`fillForm()->call('create')`) — `mount()`
 * tej strony czyta `request()->get('fishery')` wprost z frameworkowego żądania,
 * a testowy harness Livewire (`Livewire::test()`, także `withQueryParams()`,
 * które obsługuje wyłącznie właściwości `#[Url]`) nie przenosi query stringa
 * do tego wywołania — zweryfikowane empirycznie. `getRedirectUrl()` ma fallback
 * `$this->record->fishery_id`, więc wołamy go bezpośrednio na instancji strony
 * z ustawionym rekordem — testuje tę samą logikę, bez symulowania żądania.
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
