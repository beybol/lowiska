<?php

namespace Tests\Feature;

use App\Filament\Resources\AdditionalServiceResource\Pages\CreateAdditionalService;
use App\Filament\Resources\FisheryResource;
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

    // Adres sekcji budowany po KLASIE STRONY — parametr `?relation=N` zniknął razem
    // z zakładkami huba (ADR-006, aktualizacja z zadania 016).
    expect($page->getRedirectUrl())
        ->toBe(FisheryResource::getUrl('additional-services', ['record' => $fishery]));
});
