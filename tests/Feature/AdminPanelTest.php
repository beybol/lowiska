<?php

namespace Tests\Feature;

use App\Models\User;

/*
 * ⚠️ Zadanie 009: usunięto stąd `assertSee(__('Panel'))`. Nie istnieje klucz
 * tłumaczenia `Panel`, więc asercja sprawdzała gołe słowo „Panel", które
 * Filament 3 wypisywał gdzieś w swoim znaczniku, a Filament 5 już nie —
 * czyli szczegół implementacyjny biblioteki, nie zachowanie aplikacji.
 * Zweryfikowane sondą: po migracji wszystkie dziewięć realnych pozycji
 * nawigacji nadal się renderuje i to one są tu właściwym dowodem.
 */
test('Admin panel is accessible.', function () {
    $superAdmin = $this->createSuperAdmin();

    $this->actingAs($superAdmin)
        ->get('/admin')
        ->assertStatus(200)
        ->assertSee(__('Companies'))
        ->assertSee(__('Fish'))
        ->assertSee(__('Conveniences'))
        ->assertSee(__('Countries'))
        ->assertSee(__('Fishery types'))
        ->assertSee(__('Fishing methods'))
        ->assertSee(__('States'))
        ->assertSee(__('Fisheries'))
        ->assertSee(__('Users'));
});

test('Other user can not have access to admin panel.', function () {
    // Nazwa wprost — patrz komentarz w tests/Feature/OwnerPanelTest.php:
    // losowe polskie nazwisko bywa podciągiem tłumaczenia sprawdzanego przez `assertDontSee`.
    $user = User::factory()->create(['name' => 'Uzytkownik Testowy']);

    // Asercja negatywna celuje w REALNĄ etykietę nawigacji, nie w gołe słowo
    // „Panel" (patrz komentarz wyżej) — dzięki temu naprawdę dowodzi, że
    // użytkownik bez uprawnień nie zobaczył zawartości panelu.
    $this->actingAs($user)
        ->get('/admin')
        ->assertStatus(403)
        ->assertDontSee(__('Companies'));
});
