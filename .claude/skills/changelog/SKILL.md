---
name: changelog
description: Utrzymuje changelog projektu (CHANGELOG.md) w formacie keepachangelog 1.0.0 (PL) z wersjonowaniem semantycznym. Używaj przy dodawaniu wpisów po wdrożeniu zmian w lokalnych plikach, lub gdy zadanie w docs/tasks/ ma w checkliście "CHANGELOG.md — wpis w changelogu".
---

# Utrzymanie Changelogu

Jesteś specjalistą od utrzymania changelogu projektu. Twoim zadaniem jest aktualizowanie pliku `CHANGELOG.md` tak, aby odzwierciedlał rzeczywiste zmiany w kodzie — pogrupowane według wersji semantycznych zgodnie z konwencją [keepachangelog 1.0.0 (PL)](https://keepachangelog.com/pl/1.0.0/) oraz [astrotech versioning](https://dev.astrotech.io/agility/proces-wytwarzania-oprogramowania/wersjonowanie.html).

**Pracujesz wyłącznie na lokalnych plikach** — nie używaj git ani gh, nie odwołuj się do commitów, PR-ów ani historii repozytorium. Źródłem prawdy jest stan plików roboczych i kontekst zadania, w ramach którego skill jest wywoływany.

## Format pliku

### Struktura sekcji

Każda sekcja to nagłówek wersji w formacie:

```
## [X.Y.Z] - YYYY-MM-DD
```

- **Wersja bez daty** = wersja "do wydania" (najnowsza, na samej górze, zbiera bieżące zmiany):
  ```
  ## [0.10.0]
  ```
- **Wersja z datą** = wersja wydana:
  ```
  ## [0.9.0] - 2026-05-20
  ```
- Sortowanie malejąco — najnowsza wersja na górze, najstarsza na dole.
- Nagłówek pliku (`# Log zmian` + linki do konwencji wersjonowania i keepachangelog) pozostaje nietknięty.

### Standardowe podsekcje

W każdej sekcji wersji używaj wyłącznie tych podsekcji (pomijaj puste):

- **Dodane** — nowe funkcjonalności
- **Zmienione** — zmiany w istniejących funkcjonalnościach
- **Przestarzałe** — funkcje oznaczone do usunięcia w przyszłości
- **Usunięte** — usunięte funkcje
- **Poprawione** — poprawki błędów
- **Bezpieczeństwo** — zmiany związane z podatnościami

### Format wpisu

Każda pozycja to pojedyncza linia opisowa, czas przeszły, perspektywa użytkownika:

```
### Dodane
- walidator numeru NIP zgodny z algorytmem MF
- kreator warunków współpracy na grupie Przedstawicieli

### Zmienione
- działanie filtrów — wyniki odświeżają się bez kliknięcia "filtruj"

### Poprawione
- błąd renderowania menu po wejściu w szczegóły obiektu
```

## Kiedy aktualizować changelog

1. **Trigger w zadaniu** — gdy plik `docs/tasks/NNN-*.md` ma w sekcji "Zmiany dokumentacji" zaznaczony checkbox `CHANGELOG.md — wpis w changelogu`. Jest to wprost wskazanie do aktualizacji po wdrożeniu zadania.
2. **Na wyraźną prośbę użytkownika** — gdy poprosi o udokumentowanie konkretnej zmiany w changelogu.
3. **Przy wydaniu wersji** — sekcja "do wydania" (bez daty) dostaje datę, a na górze powstaje nowa pusta sekcja kolejnej wersji.

## Jak ustalić, co dopisać

### 1. Sprawdź aktualny stan `CHANGELOG.md`

- Przeczytaj **górną część** pliku — sekcja najnowszej wersji, ewentualnie sekcja "do wydania".
- Ustal: jaka jest aktualna sekcja "do wydania" (jeśli istnieje) i jaki jest numer ostatniej wydanej wersji.

### 2. Ustal źródło informacji o zmianie

Pracujesz tylko z plikami lokalnymi, więc kontekst zmiany pochodzi z:

- **Pliku zadania** w `docs/tasks/NNN-*.md` — sekcje "Opis problemu", "Wymagania", "Kryteria akceptacji" mówią, co zostało zrealizowane.
- **Powiązanych ADR-ów** w `docs/adr/NNN-*.md` — mogą zawierać informacje o decyzjach widocznych dla użytkownika.
- **Bezpośredniego polecenia użytkownika** — jeśli użytkownik wprost mówi "dodaj wpis o X".
- **Stanu plików roboczych** — odczyt zmienionych plików w katalogu projektu, jeśli trzeba zweryfikować zakres realnej zmiany.

### 3. Co uwzględniać

- **Nowe funkcje** widoczne dla użytkownika (nowe widoki, formularze, walidatory, kreatory, eksporty).
- **Poprawki błędów** zauważalne w UX lub w danych.
- **Zmiany łamiące kompatybilność** (zmieniony format pól, usunięte opcje konfiguracyjne).
- **Zmiany w uprawnieniach** lub ograniczeniach dostępu (`ipRestrictions`, role, polityki).
- **Zmiany w eksportach** i raportach.
- **Zmiany związane z bezpieczeństwem** — w podsekcji "Bezpieczeństwo".

### 4. Co pomijać

- Wewnętrzne refaktoryzacje bez wpływu na użytkownika.
- Optymalizacje wydajności (chyba że naprawiają zauważalny problem).
- Zmiany tylko w testach.
- Zmiany tylko w dokumentacji deweloperskiej (CLAUDE.md, ADR-y, `docs/tasks/`).
- Aktualizacje zależności bez efektu funkcjonalnego.
- Zmiany w modułach oznaczonych jako eksperymentalne/beta.

**Kluczowa zasada**: changelog jest dla użytkowników końcowych, nie dla deweloperów. Jeśli wpis nie ma znaczenia dla osoby korzystającej z systemu, pomiń go.

## Styl pisania

- **Zwięźle** — jedna linia na zmianę, jeśli się da.
- **Z perspektywy użytkownika** — opisuj efekt, nie implementację.
  - Dobrze: "Naprawiono kasowanie wszystkich filtrów jednym przyciskiem"
  - Źle: "Refaktoryzacja FilterService — wyniesienie logiki do dedykowanej metody"
- **Konkretnie** — jeśli wpis można zilustrować akcją użytkownika, podaj ją.
  - Dobrze: "Dodano filtr aktywności i partycypacji dla bazy Produktów"
  - Źle: "Poprawiono filtrowanie"
- **Bez technicznego żargonu** — chyba że jest częścią domeny biznesowej (NIP, REGON, IBAN, KRS, PESEL są OK).
- **Czas przeszły** dla ukończonych zmian: "Dodano…", "Naprawiono…", "Usunięto…".
- **Bez duplikacji** — ta sama zmiana pojawia się w jednej podsekcji, nie w dwóch.
- **Jeśli nie potrafisz ustalić konkretnego przypadku** ze źródeł lokalnych (zadanie, ADR, pliki, polecenie użytkownika) — **pomiń wpis** zamiast zostawiać go niejasnym, lub dopytaj użytkownika o doprecyzowanie.

## Przykład poprawnej sekcji

```
## [0.9.0]

### Dodane
- obsługa danych podstawowych Sprzedawców
- słowniki branż i powodów obserwacji
- słownik rodzajów integracji

### Zmienione
- podejście do warunków współpracy — połączenie zakładek danych bieżących i historycznych z przełącznikiem

## [0.8.0] - 2023-09-13

### Dodane
- obsługa danych podstawowych Przedstawicieli
- walidatory numeru rachunku bankowego, KRS, REGON, NIP
- obsługa notatek dla Przedstawicieli z opcją dodawania grupowych
- kreator warunków współpracy Przedstawiciela
- mechanizm automatycznego zamykania Agentów na podstawie daty (zadanie nocne)

### Zmienione
- działanie filtrów — nie trzeba klikać "filtruj" po powrocie do wcześniej filtrowanej listy

### Poprawione
- rozwijanie menu przy wchodzeniu głębiej w opcje obiektu
- kolory pól formularzy po autouzupełnieniu
- błąd biblioteki logowania zmian
```

## Proces aktualizacji

1. **Przeczytaj `CHANGELOG.md`** — sprawdź ostatnią wydaną wersję i istniejącą sekcję "do wydania".
2. **Ustal źródło zmiany** — plik zadania w `docs/tasks/`, polecenie użytkownika, lub zmienione pliki w projekcie.
3. **Dla każdej zmiany podejmij decyzję** — czy widoczna dla użytkownika? Jeśli nie, pomiń.
4. **Dopisz lub zaktualizuj sekcję "do wydania"** (`## [X.Y.Z]` bez daty). Nigdy nie modyfikuj sekcji wydanej (z datą), chyba że poprawiasz oczywisty błąd typograficzny.
5. **Pogrupuj wpisy** w odpowiednie podsekcje (Dodane / Zmienione / Przestarzałe / Usunięte / Poprawione / Bezpieczeństwo).
6. **Zachowaj istniejący format i konwencję** — porównaj z poprzednimi sekcjami pod kątem stylu.
7. **Zapisz plik** — i tylko tyle. Nie commituj, nie pushuj, nie twórz PR-a — to decyzja użytkownika.

## Ważne uwagi

- **Pracuj tylko na lokalnym `CHANGELOG.md`** — żadnych operacji na repozytorium (git, gh, commit, push, PR).
- **Nie modyfikuj sekcji już wydanych** (z datą) poza poprawkami typograficznymi.
- **Nie twórz wielu sekcji dla tej samej wersji** — każda wersja pojawia się raz.
- **Utrzymuj sortowanie malejąco** — najnowsza wersja na górze.
- **Nie usuwaj nagłówka pliku** — linki do konwencji wersjonowania i keepachangelog muszą zostać.
- **Jeśli nie ma czego dopisać** (np. zmiana jest wewnętrzna i nie podlega logowaniu) — powiedz to wprost i nie zmieniaj pliku.
