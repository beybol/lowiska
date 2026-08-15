---
description: Ocenia kompletność opisu zadania z docs/tasks/ (w tym zakres testów), rozstrzyga kwestie punktowe w jego treści i tworzy ADR-y tylko dla realnych decyzji architektonicznych.
argument-hint: "<numer-lub-nazwa-zadania>"
---

# Komenda: /review-task

Ocenia kompletność opisu zadania i identyfikuje decyzje architektoniczne.

## Użycie
`/review-task <nazwa-pliku-bez-rozszerzenia-lub-numer-zadania>`

Przykład: `/review-task 005-filtr-lowisk`
lub: `/review-task 005`

## Instrukcja

> **Zasada:** Wszystkie operacje na plikach wykonuj bezpośrednio w katalogu głównym projektu.
> Nie używaj worktree, nie wykonuj żadnych komend git.

Argument komendy to nazwa pliku zadania (bez `.md`) z katalogu `docs/tasks/` lub numer zadania,
jeśli jest jednoznaczny — to znaczy, że nie ma innego pliku zaczynającego się od podanego numeru.
Nazwy plików zadań mają postać `<numer>-<opis>.md`.
Jeśli argument nie został podany, wylistuj dostępne zadania z katalogu `docs/tasks/` i poproś o wybór.

### Krok 1 — Odczyt zadania
Przeczytaj plik `docs/tasks/$ARGUMENTS.md`.

### Krok 2 — Ocena kompletności
Oceń zadanie względem poniższej listy kontrolnej. Dla każdego brakującego
elementu zadaj konkretne pytanie do autora:

- [ ] **Opis problemu** — jasno sformułowany cel zmiany
- [ ] **Wymagania** — co dokładnie ma zostać zbudowane
- [ ] **Kryteria akceptacji** — mierzalne warunki ukończenia
- [ ] **Zakres testów** — zadeklarowany tier (T1/T2/T3) + wykonywalna komenda
- [ ] **Zakres wyłączeń** — co celowo jest poza zakresem
- [ ] **Zmiany dokumentacji** — które pliki docs wymagają aktualizacji
- [ ] **Ograniczenia techniczne** — stack, zgodność, środowisko

**Zakres testów wymaga osobnej uwagi:**
- Brak sekcji `## Zakres testów` → dobierz tier wg `CLAUDE.md` („Zakres testów — dobierany do
  zadania"), zaproponuj go i **dopisz sekcję** po zatwierdzeniu.
- Sekcja jest, ale kryteria akceptacji wymagają zielonego **pełnego** pakietu przy tierze T1/T2
  → to sprzeczność; zgłoś ją i popraw kryterium na zadeklarowany zakres + adnotację, że pełny
  pakiet jest **odroczony** na koniec sesji (`/review-implementation`, Krok 1).
- Tier wygląda na zaniżony względem tego, co zadanie realnie rusza → zaproponuj podniesienie
  z uzasadnieniem. ⚠️ Trafienie w **listę wyzwalaczy T3** z `CLAUDE.md` (`bootstrap/app.php`,
  `User`, polityki i Shield, providery paneli, `phpunit.xml`, `tests/TestCase.php`, `tests/Pest.php`,
  `tests/Unit/PhpunitConfigInvariantTest.php`, migracje, `composer.json`, `Dockerfile`,
  `docker-compose.yml`, `docker/**`) przesądza tier — to nie jest wtedy propozycja, tylko stwierdzenie.
- W polu „Uruchamiamy" ma stać wykonywalna komenda (`docker compose exec app php artisan test`
  albo `docker compose exec app php artisan test --filter="…"`), nie proza.

**Sprawdź też, czy zadanie wskazuje powierzchnię i jej plik konwencji.** Jeśli powierzchnia nie ma
jeszcze pliku w `docs/conventions/`, a zadanie ustala regułę wiążącą przyszły kod — dopisz do
„Zmiany dokumentacji" pozycję z założeniem tego pliku. To jest ścieżka, którą katalog konwencji
się zapełnia.

### Krok 3 — Nierozstrzygnięte kwestie: ADR czy rozstrzygnięcie w zadaniu

Przejrzyj zadanie pod kątem miejsc, w których implementacja musiałaby zgadywać. Następnie
**posegreguj je na dwie kupki** wg kryterium z `CLAUDE.md` („ADR czy rozstrzygnięcie w treści
zadania") — przeczytaj tę sekcję, nie odtwarzaj jej z pamięci.

⚠️ **Domyślną odpowiedzią jest rozstrzygnięcie w treści zadania, nie ADR.** ADR zakładaj
dopiero, gdy spełnione są **wszystkie trzy** warunki (zasięg poza zadaniem · wysoki koszt
odwrócenia · uzasadnienie warte zapamiętania). Samo „istnieją dwie sensowne opcje" **nie
wystarcza** — to najczęstsza przyczyna nadprodukcji ADR-ów. Pytania o wygląd, copy, ikonę,
kolejność w menu, wartość progu, nazwę pola czy umiejscowienie jednego przycisku **nie są
decyzjami architektonicznymi**, choćby miały po trzy warianty.

**A. Kwestie punktowe → rozstrzygnij tutaj, na miejscu:**
1. Zadaj pytanie autorowi (`AskUserQuestion`), z rekomendacją jako pierwszą opcją i krótkim
   uzasadnieniem każdego wariantu.
2. Zapisz odpowiedź do sekcji **`## Rozstrzygnięcia`** zadania: decyzja + jednozdaniowe
   uzasadnienie.
3. Jeśli rozstrzygnięcie zmienia wymagania — zaktualizuj też `## Wymagania`, żeby implementacja
   nie musiała czytać dwóch miejsc.

Nie odkładaj tych pytań na `/implement-task` — to dokładnie ten etap, na którym mają zniknąć.

**B. Decyzje architektoniczne → ADR:**
1. Sprawdź, czy plik ADR już istnieje w `docs/adr/` (wyszukaj po temacie). **Rozszerzenie
   istniejącego ADR-a jest pełnoprawną opcją** — jeśli decyzja jest wariantem już podjętej,
   dopisz ją tam zamiast zakładać nowy plik.
2. Jeśli nie istnieje — ustal kolejny numer ADR (`ADR-001`, `ADR-002`, …)
   i utwórz `docs/adr/ADR-NNN-krotki-temat.md` według szablonu `docs/adr/_template.md`:
   - Wypełnij: Kontekst, Alternatywy (minimum 2–3), **Rekomendację z uzasadnieniem**
   - Zostaw sekcję **Decyzja** pustą — wypełnia autor zadania
3. W treści zadania uzupełnij sekcję „Powiązane ADR-y" o odnośniki do plików ADR.
4. Poinformuj użytkownika, który plik ADR powstał i czego dotyczy — **wraz z uzasadnieniem,
   który z trzech warunków kryterium przesądził**, że to ADR, a nie rozstrzygnięcie.

### Krok 4 (tylko jeśli powstały nowe ADR-y)
Jeśli w Kroku 3 utworzono co najmniej jeden plik ADR, poinformuj użytkownika:
**„Wypełnij sekcję Decyzja w `docs/adr/ADR-NNN-*.md` i uruchom `/implement-task $ARGUMENTS`"**.

Pliki ADR **zawsze** zapisuj w katalogu `docs/adr/` repozytorium — nigdy w katalogu głównym
projektu ani w worktree.

Jeśli żaden ADR nie powstał — pomiń ten krok.

### Krok 5 — Raport
Zwróć zwięzłe podsumowanie:
- ✅ Co jest kompletne
- ❓ Pytania / brakujące elementy
- 🧪 Zadeklarowany zakres testów (tier + komenda); jeśli tier < T3 — zaznacz, że pełny pakiet
  jest odroczony na koniec sesji
- ⚖️ Rozstrzygnięcia zapisane w treści zadania (jeśli jakieś)
- 📄 Utworzone pliki ADR (jeśli jakieś) — z uzasadnieniem, dlaczego to ADR, a nie rozstrzygnięcie
- ➡️ Kolejny krok: albo uzupełnij zadanie, albo uruchom `/implement-task $ARGUMENTS`
- Zasugeruj minimalny model i wysiłek potrzebny do implementacji zadania na podstawie jego złożoności
