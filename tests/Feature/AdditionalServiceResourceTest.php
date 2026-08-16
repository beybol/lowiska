<?php

namespace Tests\Feature;

use App\Filament\Resources\AdditionalServiceResource\Pages\CreateAdditionalService;
use App\Filament\Resources\FisheryResource;
use App\Filament\Resources\FisheryResource\RelationManagers\AdditionalServicesRelationManager;
use App\Models\AdditionalService;
use App\Models\Company;
use App\Models\Fishery;

/**
 * Zadanie 012: po utworzeniu usługi dodatkowej przekierowanie prowadzi na
 * **zakładkę usług w hubie „Zarządzaj łowiskiem"**, a nie na samotną stronę listy.
 * Dotyczy obu paneli, bo `AdditionalServiceResource` jest tą samą klasą
 * zarejestrowaną wprost w `OwnerPanelProvider`.
 *
 * ⚠️ Patrz `tests/Feature/PositionResourceTest.php` — ten sam powód, dla
 * którego nie testujemy przez pełny cykl Livewire, i ten sam powód, dla którego
 * numer zakładki liczymy z `getRelations()` zamiast go wpisywać.
 */
test('creating an additional service redirects to its tab in the fishery hub', function () {
    $company = Company::factory()->create();
    $fishery = Fishery::factory()->create(['company_id' => $company->id]);
    $additionalService = AdditionalService::factory()->create(['fishery_id' => $fishery->id]);

    $page = new CreateAdditionalService;
    $page->record = $additionalService;

    expect($page->getRedirectUrl())->toBe(FisheryResource::getUrl('manage', [
        'record' => $fishery,
        'relation' => array_search(AdditionalServicesRelationManager::class, FisheryResource::getRelations(), true),
    ]));
});
