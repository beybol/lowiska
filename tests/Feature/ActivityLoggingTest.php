<?php

namespace Tests\Feature;

use App\Models\Country;
use Spatie\Activitylog\Models\Activity;

/**
 * Zadanie 010: upgrade `spatie/laravel-activitylog` 4.x -> 5.x.
 *
 * ⚠️ To jedyny test, który odróżnia „logowanie działa" od „tabela zapisuje
 * puste wpisy". W v5 `getActivitylogOptions()` jest opcjonalne, a domyślne
 * zachowanie loguje samo zdarzenie BEZ śledzenia zmian atrybutów — usunięcie
 * albo błędne przeniesienie tej metody w którymkolwiek z trzynastu modeli nie
 * rzuciłoby żadnym błędem, po prostu dziennik przestałby nosić treść.
 */
test('saving a model creates an activity log entry with non-empty attribute changes', function () {
    $country = Country::factory()->create(['country_name' => 'Stara nazwa']);

    $countBefore = Activity::count();

    $country->update(['country_name' => 'Nowa nazwa']);

    expect(Activity::count())->toBe($countBefore + 1);

    $activity = Activity::latest('id')->first();

    expect($activity->attribute_changes)->not->toBeNull();
    expect($activity->attribute_changes['old']['country_name'] ?? null)->toBe('Stara nazwa');
    expect($activity->attribute_changes['attributes']['country_name'] ?? null)->toBe('Nowa nazwa');
});
