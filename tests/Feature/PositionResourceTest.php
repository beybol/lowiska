<?php

namespace Tests\Feature;

use App\Filament\Resources\PositionResource;
use App\Filament\Resources\PositionResource\Pages\CreatePosition;
use App\Models\Company;
use App\Models\Fishery;
use App\Models\Position;

/**
 * Zadanie 011: po utworzeniu stanowiska przekierowanie ma prowadzić na listę
 * stanowisk **z zachowanym filtrem `fishery`** (wzorem
 * `LongTermPermitResource\Pages\CreateLongTermPermit`) — dotyczy obu paneli,
 * bo `PositionResource` jest tą samą klasą zarejestrowaną wprost w
 * `OwnerPanelProvider` (patrz treść zadania, „Ważny fakt architektoniczny").
 *
 * ⚠️ Nie przez pełny cykl Livewire (`fillForm()->call('create')`) — `mount()`
 * tej strony czyta `request()->get('fishery')` wprost z frameworkowego żądania,
 * a testowy harness Livewire (`Livewire::test()`, także `withQueryParams()`,
 * które obsługuje wyłącznie właściwości `#[Url]`) nie przenosi query stringa
 * do tego wywołania — zweryfikowane empirycznie. `getRedirectUrl()` ma fallback
 * `$this->record->fishery_id`, więc wołamy go bezpośrednio na instancji strony
 * z ustawionym rekordem — testuje tę samą logikę, bez symulowania żądania.
 */
test('creating a position redirects to the resource list scoped to its fishery', function () {
    $company = Company::factory()->create();
    $fishery = Fishery::factory()->create(['company_id' => $company->id]);
    $position = Position::factory()->create(['fishery_id' => $fishery->id]);

    $page = new CreatePosition;
    $page->record = $position;

    expect($page->getRedirectUrl())
        ->toBe(PositionResource::getUrl('index', ['fishery' => $fishery->id]));
});
