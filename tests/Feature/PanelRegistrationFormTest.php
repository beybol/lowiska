<?php

namespace Tests\Feature;

/**
 * Regresja dla zadania 009 (upgrade Filamenta 3 → 5).
 *
 * Strona rejestracji panelu dokłada do domyślnego formularza Filamenta trzy
 * własne pola: `surname`, `country_id` i `phone`. Po migracji nadpisany
 * `getForms()` przestał być wołany (`makeForm()` zniknęło z Filamenta 5),
 * przez co strona renderowała wyłącznie pola rodzica.
 *
 * ⚠️ Awaria była CICHA — `/admin/register` nadal zwracało 200, więc żaden test
 * statusu ani przegląd „czy panel się otwiera" jej nie widział; zniknięcie pól
 * wyszło dopiero z analizy statycznej. Ten test pilnuje ZAWARTOŚCI formularza,
 * nie jego kodu odpowiedzi.
 */
test('panel registration form keeps the custom fields', function () {
    $html = $this->get('/admin/register')->getContent();

    // ⚠️ Nie `expect()->toContain()` — ta metoda jest wariadyczna i traktuje
    // KAŻDY argument jako szukany ciąg, więc komunikat stałby się igłą.
    foreach (['data.name', 'data.surname', 'data.email', 'data.country_id', 'data.phone'] as $field) {
        $this->assertStringContainsString(
            $field,
            $html,
            "Formularz rejestracji zgubił pole {$field}."
        );
    }
});
