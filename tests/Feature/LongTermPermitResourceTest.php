<?php

namespace Tests\Feature;

use App\Filament\Resources\FisheryResource;
use App\Filament\Resources\FisheryResource\RelationManagers\LongTermPermitsRelationManager;
use App\Filament\Resources\LongTermPermitResource\Pages\CreateLongTermPermit;
use App\Models\Company;
use App\Models\Fishery;
use App\Models\LongTermPermit;

/**
 * Zadanie 012: po utworzeniu pozwolenia przekierowanie prowadzi na **zakładkę
 * pozwoleń w hubie „Zarządzaj łowiskiem"**, a nie na samotną stronę listy.
 *
 * ⚠️ Ten test celowo NIE idzie przez cykl Livewire: sprawdza gałąź, w której źródłem
 * łowiska jest **zapisany rekord**, a nie parametr `?fishery` z żądania. Kolejność tych
 * dwóch źródeł jest istotna — przy właścicielu dwóch łowisk rekord mógł wylądować w B,
 * a przekierowanie prowadzić do A (druga tura przeglądu, zadanie 012 §17).
 *
 * ⚠️ Zakładkę identyfikuje **pozycja** w `FisheryResource::getRelations()`, nie nazwa
 * klasy — dlatego oczekiwany adres liczymy z tej samej tablicy, zamiast wpisywać numer.
 */
test('creating a long term permit redirects to its tab in the fishery hub', function () {
    $company = Company::factory()->create();
    $fishery = Fishery::factory()->create(['company_id' => $company->id]);
    $permit = LongTermPermit::factory()->create(['fishery_id' => $fishery->id]);

    $page = new CreateLongTermPermit;
    $page->record = $permit;

    expect($page->getRedirectUrl())->toBe(FisheryResource::getUrl('manage', [
        'record' => $fishery,
        'relation' => array_search(LongTermPermitsRelationManager::class, FisheryResource::getRelations(), true),
    ]));
});
