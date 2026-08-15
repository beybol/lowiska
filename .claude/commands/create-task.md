---
description: Zakłada nowy plik zadania w docs/tasks/ — kolejny wolny numer, szkielet z szablonu, krótki wywiad i dobór zakresu testów. Przekazuje do /review-task.
argument-hint: "<temat zadania>"
---

# Komenda: /create-task

Zakłada **jeden** plik zadania w `docs/tasks/` i przekazuje go do `/review-task`.

## Użycie
`/create-task <temat zadania>` — np. `/create-task filtr łowisk po metodzie połowu`

Bez argumentu: zapytaj, czego ma dotyczyć zadanie.

## Zakres — czego ta komenda NIE robi

- **Nie ocenia kompletności** zadania i **nie tworzy ADR-ów** — to robota `/review-task`.
  Jeśli w trakcie wywiadu natkniesz się na decyzję wyglądającą na architektoniczną, **zanotuj ją
  w treści zadania jako otwarte pytanie** i zostaw `/review-task` — nie zakładaj ADR-a sam.
- **Nie implementuje** ani jednej linii kodu produkcyjnego.
- **Nie wykonuje komend git** i nie przenosi plików zadań — cykl życia zadań należy do użytkownika.

## Instrukcja

> **Zasada:** Wszystkie operacje na plikach wykonuj bezpośrednio w katalogu głównym projektu.
> Nie używaj worktree, nie wykonuj żadnych komend git.

### Krok 1 — Ustal kolejny wolny numer

Numery zadań są ciągłe **przez oba katalogi** — zadania w toku leżą w `docs/tasks/`,
zrealizowane w `docs/tasks/implemented/`. Skanuj oba:

```bash
ls docs/tasks docs/tasks/implemented | grep -oE '^[0-9]{3}' | sort -n | tail -1
```

Nowy numer = największy + 1, zawsze trzycyfrowy (`007`, nie `7`). Gdy katalog jest pusty — `001`.
Nazwa pliku: `NNN-krotki-opis-po-polsku.md` (kebab-case, bez polskich znaków diakrytycznych
w nazwie pliku).

### Krok 2 — Krótki wywiad

Zbierz minimum potrzebne do sensownego pliku. Pytaj **zwięźle i tylko o to, czego nie da się
wywnioskować** z tematu, z `CLAUDE.md`, z pliku konwencji powierzchni (`docs/conventions/`,
tabela routingu w `CLAUDE.md`) i z kodu — jeśli coś jest oczywiste z repozytorium,
wpisz to sam i zaznacz jako założenie do potwierdzenia. Użyj `AskUserQuestion` tam, gdzie
odpowiedź realnie rozgałęzia zadanie.

Potrzebujesz: **opisu problemu** (co jest nie tak / czego brakuje i dlaczego), **wymagań**
(co ma powstać), **zakresu wyłączeń** (co celowo poza zakresem) oraz **ograniczeń technicznych**,
jeśli inne niż domyślne dla stacku.

⚠️ **Część `CLAUDE.md` opisująca aplikację jest oznaczona `⛏️ do uzupełnienia`, a katalog
`docs/conventions/` bywa jeszcze pusty.** Gdy zadanie wchodzi na powierzchnię bez pliku konwencji,
nie udawaj, że reguła istnieje — zapytaj autora o niezmiennik i zapisz odpowiedź w zadaniu.
To jest właśnie moment, w którym pierwsze pliki konwencji powstają.

### Krok 3 — Dobór zakresu testów (kluczowy krok tej komendy)

Ustal **jeden tier** wg sekcji „Zakres testów — dobierany do zadania" w `CLAUDE.md`
(T1 punktowy / T2 zależności / T3 pełny pakiet). To jest **źródło prawdy kryteriów** — nie
powtarzaj ich tutaj z pamięci, przeczytaj `CLAUDE.md`.

1. Ustal, czego zadanie dotknie (klasy, warstwy) — na podstawie tematu i szybkiego rozpoznania
   w kodzie (`Grep`/`Glob`), nie zgadywania.
2. **Sprawdź listę wyzwalaczy T3** z `CLAUDE.md` (`bootstrap/app.php`, `User`, polityki i Shield,
   providery paneli, `phpunit.xml`, `tests/TestCase.php`, `tests/Pest.php`,
   `tests/Unit/PhpunitConfigInvariantTest.php`, migracje, `composer.json`, `Dockerfile`,
   `docker-compose.yml`, `docker/**`). Trafienie w którykolwiek → **T3 nie jest przedmiotem wyboru**;
   powiedz to wprost zamiast oferować pozorną alternatywę.
3. **Zaproponuj tier z uzasadnieniem** i wskaż, jeśli już wiadomo, konkretne klasy testowe.
4. **Zapytaj o zatwierdzenie** (`AskUserQuestion`): zaproponowany tier / tier wyżej / tier niżej.
   Rekomendację postaw jako pierwszą opcję.
5. Wypełnij sekcję `## Zakres testów` (Tier / Uruchamiamy / Uzasadnienie). Pole „Uruchamiamy"
   ma zawierać **wykonywalną komendę**: `docker compose exec app php artisan test --filter="NazwaKlasy"` dla T1/T2 albo `docker compose exec app php artisan test`
   dla T3 — nie prozę, bo czyta je `/implement-task`.

⚠️ **Nie wpisuj do kryteriów akceptacji „zielony pełny pakiet testów", jeśli tier to nie T3.**
Kryterium ma odzwierciedlać zadeklarowany zakres. Przy tierze niższym niż T3 dopisz do kryteriów
jawnie, że pełny pakiet jest **odroczony** na koniec sesji (`/review-implementation`, Krok 1).

⚠️ **Komendą testów w tym projekcie jest `docker compose exec app php artisan test`** — testy biegną
na MySQL-u w schemacie `lowiska_test`, chronionym pięcioma warstwami izolacji (patrz `CLAUDE.md`,
sekcja o bezpieczeństwie bazy danych, oraz `docs/operations/docker.md`).

### Krok 4 — Zapis pliku

Utwórz `docs/tasks/NNN-opis.md` **ze wszystkimi sekcjami szablonu** `docs/tasks/_template.md`
(łącznie z `## Zakres testów`, `## Rozstrzygnięcia` i `## Powiązane ADR-y`). Sekcje, których
wywiad nie objął, zostaw z komentarzem szablonu — `/review-task` je wyłapie. Komentarze
instruktażowe (`<!-- … -->`) z wypełnionych sekcji usuń.

`## Rozstrzygnięcia` i `## Powiązane ADR-y` zostaw puste — uzupełnia je `/review-task`.

### Krok 5 — Raport i przekazanie

Zwróć zwięźle:
- 📄 ścieżkę utworzonego pliku,
- 🧪 zadeklarowany tier + co konkretnie będzie uruchamiane,
- ❓ sekcje pozostawione do uzupełnienia i wykryte otwarte pytania (kandydaci na ADR lub
  rozstrzygnięcia — **bez** zakładania ADR-ów),
- ➡️ kolejny krok: **`/review-task NNN`**.
