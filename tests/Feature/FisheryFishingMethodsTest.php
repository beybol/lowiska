<?php

namespace Tests\Feature;

use App\Filament\Resources\FisheryResource\Pages\EditFishery;
use App\Models\Fishery;
use App\Models\FishingMethod;
use Livewire\Livewire;

/**
 * Metody połowu łowiska zapisują się przez relację wiele-do-wielu `fishingMethods`.
 *
 * ⚠️ Regresja: pole `fishing_methods` miało same opcje bez `relationship()`, a takiej kolumny nie ma —
 * formularz przyjmował wybór i po cichu go gubił. Test sprawdza zapis, odczyt w formularzu i odznaczenie.
 */
test('the fishing methods picked in the form are saved, shown again and can be unpicked', function () {
    $admin = $this->createSuperAdmin();
    $fishery = Fishery::factory()->create();
    [$carp, $float, $feeder] = FishingMethod::factory()->count(3)->create()->all();

    Livewire::actingAs($admin)
        ->test(EditFishery::class, ['record' => $fishery->getRouteKey()])
        ->fillForm(['fishing_methods' => [$carp->id, $feeder->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($fishery->fresh()->fishingMethods->pluck('id')->sort()->values()->all())
        ->toBe(collect([$carp->id, $feeder->id])->sort()->values()->all());

    $form = Livewire::actingAs($admin)->test(EditFishery::class, ['record' => $fishery->getRouteKey()]);
    expect(collect($form->get('data.fishing_methods'))->map(fn ($id): int => (int) $id)->sort()->values()->all())
        ->toBe(collect([$carp->id, $feeder->id])->sort()->values()->all());

    $form->fillForm(['fishing_methods' => [$float->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($fishery->fresh()->fishingMethods->pluck('id')->all())->toBe([$float->id]);
});
