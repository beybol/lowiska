<?php

namespace Tests\Feature;

use App\Helpers\Helper;
use App\Models\User;

/**
 * Regresja dla zadania 012: wejścia do kreatora zakładania łowiska.
 *
 * Kreator przeniósł się z `companies/create?wizard=1` (trzy sklejone strony) na
 * `fisheries/create` (natywny `Wizard`). Stary URL nie tworzy już kreatora —
 * prowadzi do zwykłego formularza firmy.
 *
 * ⚠️ Ten test istnieje, bo przy refaktorze poprawiono jedno wejście, a przeoczono
 * cztery: przycisk „Utwórz łowisko" na liście łowisk, dwa linki w nawigacji
 * (desktop i mobile) oraz link na stronie powitalnej. Każdy z nich prowadził
 * użytkownika do formularza firmy zamiast do kreatora — i żaden test tego nie
 * widział, bo wszystkie sprawdzały kreator wyłącznie po wejściu wprost na jego URL.
 */
test('no view links to the retired wizard entry point', function () {
    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);

    $pages = [
        '/owner/fisheries',
        '/owner/companies',
    ];

    foreach ($pages as $page) {
        $html = $this->actingAs($owner)->get($page)->getContent();

        expect($html)->not->toContain(
            'companies/create?wizard',
            "Strona {$page} linkuje do wycofanego wejścia kreatora."
        );
    }
});

test('the create button on the fisheries list opens the wizard', function () {
    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);

    $html = $this->actingAs($owner)->get('/owner/fisheries')->getContent();

    expect($html)->toContain('/owner/fisheries/create');
    expect($html)->not->toContain('/owner/companies/create');
});

test('the public register-fishery link points at the wizard', function () {
    // Strona powitalna Breeze zaprasza do założenia łowiska — bez warunku `if`,
    // żeby zniknięcie tego linku zaczerwieniło test zamiast go wyciszyć.
    $html = $this->get('/')->getContent();

    expect($html)->toContain(__('Register fishery'));
    expect($html)->toContain('/owner/fisheries/create');
    expect($html)->not->toContain('companies/create?wizard');
});
