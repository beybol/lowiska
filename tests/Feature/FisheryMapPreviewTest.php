<?php

namespace Tests\Feature;

use App\Filament\Resources\FisheryResource\Pages\CreateFishery;
use App\Helpers\Helper;
use App\Models\State;
use App\Models\User;
use Livewire\Livewire;

/**
 * Regresja dla zadania 012: podgląd mapy przestał działać po upgradzie do Filamenta 5.
 *
 * Stary widok czytał wartości pól z DOM-u (selektory `.choices__item`,
 * `select#data\.state_id`) i wiązał obsługę kliknięcia przez `DOMContentLoaded` —
 * po nawigacji Livewire (`wire:navigate`) to zdarzenie już nie pada, więc przycisk
 * przestawał cokolwiek robić.
 *
 * ⚠️ Test celuje w to, co jest teraz źródłem prawdy: adres liczony SERWEROWO
 * w `ViewField::viewData()` z pól `live()`. Gdyby ktoś zdjął `live()` z pól adresu
 * albo wrócił do czytania DOM-u, `src` iframe'a przestanie nadążać za formularzem
 * i ten test zaczerwieni się — czego test samego kodu odpowiedzi HTTP nie zobaczy.
 *
 * ⚠️ Mapa nie ma już przełącznika „pokaż/ukryj". Druga wersja podglądu trzymała go
 * w Alpine (`x-data="{ shown: false }"`), ale każda aktualizacja pola `live()`
 * przerenderowuje ten fragment, więc stan wracał do `false` i mapa znikała tuż po
 * pokazaniu. Skoro adres i tak liczy się serwerowo, mapa pojawia się sama, gdy
 * adres jest kompletny — nie dokładaj tu lokalnego stanu.
 */
test('map preview stays empty until the address is complete', function () {
    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);
    $this->actingAs($owner);

    $html = Livewire::test(CreateFishery::class)->html();

    expect($html)->toContain(__('Fill in the address fields to see the map.'));
    expect($html)->not->toContain('maps/embed');
});

test('map preview embeds the address assembled from live form fields', function () {
    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);
    $this->actingAs($owner);

    $state = State::factory()->create(['name' => 'Mazowieckie']);

    $html = Livewire::test(CreateFishery::class)
        ->fillForm([
            'street' => 'Kwiatowa',
            'building_number' => '12',
            'zip_code' => '00-001',
            'town' => 'Warszawa',
            'state_id' => $state->id,
        ])
        ->html();

    expect($html)->toContain('maps/embed');
    expect($html)->toContain(urlencode('Kwiatowa 12'));
    expect($html)->toContain(urlencode('00-001 Warszawa'));
    expect($html)->toContain(urlencode('Mazowieckie'));
});
