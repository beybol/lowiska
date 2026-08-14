---
description: Pełny audyt bezpieczeństwa całego kodu projektu (nie diff) przez skill security-audit.
argument-hint: "[quick] [ścieżka]"
---

# Komenda: /security-audit

Pełny audyt bezpieczeństwa **całego** kodu projektu (nie diff) — uruchamia globalny skill
`security-audit`. W odróżnieniu od `/security-review` (tylko diff bieżącej gałęzi) analizuje
całą bazę kodu i zwraca ustrukturyzowany raport.

## Użycie
- `/security-audit` — pełny audyt (tryb Full).
- `/security-audit quick` — szybki przegląd: audyt zależności + manualny przegląd najwyższego
  ryzyka (uwierzytelnianie i autoryzacja, ujścia wstrzyknięć, sekrety), bez ciężkiego SAST.
- `/security-audit <ścieżka>` — ogranicz zakres do podkatalogu (np. `/security-audit app/Filament`).

`$ARGUMENTS` może łączyć tryb i ścieżkę, np. `quick app/`.

## Instrukcja
1. Uruchom skill **`security-audit`** (przez narzędzie Skill, po nazwie — nie po ścieżce) i wykonaj
   jego workflow: rozpoznanie → narzędzia automatyczne → manualny przegląd wg kategorii → triage →
   raport.
2. Wykryj stack i załaduj właściwy moduł: `references/laravel-php.md`. Dla **Laravel ≥ 12** pomiń
   Enlightn (niewspierany) → użyj analizy skażeń Psalma + `composer audit`.
3. Zinterpretuj `$ARGUMENTS`: `quick` → tryb Quick; podana ścieżka → ogranicz zakres audytu do niej.
4. **Zwróć szczególną uwagę na obszary specyficzne dla tego projektu:**
   - granica między panelem `admin` a panelem `owner` — czy właściciel nie sięga po cudze dane
     (providery paneli, polityki, zakresy zapytań);
   - role i uprawnienia `filament-shield` — czy uprawnienie istnieje dla każdego zasobu i czy
     polityka nie jest obchodzona warunkiem w widoku;
   - `CSOService` (dane z rejestru GUS) i `IbanValidation` — walidacja danych z zewnątrz;
   - `laravel/socialite` — obsługa powrotu z dostawcy tożsamości i wiązanie kont;
   - upload plików i zasoby importujące dane (`app/Filament/Imports/`).
5. Trzymaj się zasad skilla: **read-only** (nie zmieniaj kodu bez wyraźnej prośby), a przed
   instalacją jakiegokolwiek narzędzia (zależności deweloperskiej) lub uruchomieniem
   `composer/npm update` — zapytaj użytkownika. Komendy `*audit` są bezpieczne (read-only).
6. Zwróć wynik w formacie z sekcji „Report format" skilla (Executive summary → Tooling results →
   Findings High→Low z `plik:linia`, severity, confidence ≥ 7 → Out of scope).
