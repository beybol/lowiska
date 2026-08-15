<?php

namespace Tests\Feature;

use App\Filament\Resources\AdditionalServiceResource;
use App\Filament\Resources\AdditionalServiceResource\Pages\CreateAdditionalService;
use App\Models\AdditionalService;
use App\Models\Company;
use App\Models\Fishery;

/**
 * Zadanie 011: po utworzeniu usługi dodatkowej przekierowanie ma prowadzić na
 * listę usług **z zachowanym filtrem `fishery`** (wzorem
 * `LongTermPermitResource\Pages\CreateLongTermPermit`) — dotyczy obu paneli,
 * bo `AdditionalServiceResource` jest tą samą klasą zarejestrowaną wprost w
 * `OwnerPanelProvider` (patrz treść zadania, „Ważny fakt architektoniczny").
 *
 * ⚠️ Patrz `tests/Feature/PositionResourceTest.php` — ten sam powód, dla
 * którego nie testujemy przez pełny cykl Livewire.
 */
test('creating an additional service redirects to the resource list scoped to its fishery', function () {
    $company = Company::factory()->create();
    $fishery = Fishery::factory()->create(['company_id' => $company->id]);
    $additionalService = AdditionalService::factory()->create(['fishery_id' => $fishery->id]);

    $page = new CreateAdditionalService;
    $page->record = $additionalService;

    expect($page->getRedirectUrl())
        ->toBe(AdditionalServiceResource::getUrl('index', ['fishery' => $fishery->id]));
});
