---
description: Implementuje zadanie z docs/tasks/ — bramka na niewypełnione ADR-y, zmiany plików, testy w zadeklarowanym zakresie (z raportem czego nie uruchomiono), aktualizacja dokumentacji i CHANGELOG.
argument-hint: "<numer-lub-nazwa-zadania>"
---

# Komenda: /implement-task

Implementuje zadanie opisane w pliku zadania i dokonuje lokalnych zmian plików.

## Użycie
`/implement-task <nazwa-pliku-bez-rozszerzenia-lub-numer-zadania>`

Przykład: `/implement-task 005-filtr-lowisk`
lub: `/implement-task 005`

## Instrukcja

> **Zasada:** Wszystkie operacje na plikach wykonuj bezpośrednio w katalogu głównym projektu.
> Nie używaj worktree, nie wykonuj żadnych komend git.

Argument komendy to nazwa pliku zadania (bez `.md`) z katalogu `docs/tasks/`.

### Krok 1 — Odczyt zadania i ADR-ów
1. Przeczytaj `docs/tasks/$ARGUMENTS.md`
2. Znajdź wszystkie powiązane ADR-y w `docs/adr/` (wymienione w zadaniu lub powiązane tematycznie)
3. Dla każdego ADR sprawdź, czy sekcja **Decyzja** jest wypełniona.
   Jeśli którykolwiek ADR ma pustą decyzję — **zatrzymaj się** i poinformuj użytkownika:
   „Przed implementacją uzupełnij decyzję w: `docs/adr/ADR-NNN-*.md`"
4. Przeczytaj sekcję **`## Rozstrzygnięcia`** zadania — wiąże implementację **tak samo jak
   decyzja z ADR-a**, różni się tylko zasięgiem. Nie podważaj jej i nie rozstrzygaj ponownie.
5. Odczytaj sekcję **`## Zakres testów`** (tier + komenda) — potrzebna w Kroku 3.
   Brak sekcji → dobierz tier wg `CLAUDE.md` („Zakres testów — dobierany do zadania"), powiedz
   wprost jaki i dlaczego, i tak go raportuj w podsumowaniu.
6. **Ustal powierzchnię zadania i przeczytaj jej plik konwencji** — tabela routingu jest
   w `CLAUDE.md` (sekcja „Konwencje powierzchni"), pliki w `docs/conventions/`.
   Zadanie dotykające dwóch powierzchni → przeczytaj oba pliki.
   ⚠️ **Bez tego kroku nie zaczynaj implementacji.** Te pliki niosą niezmienniki i świadome
   odstępstwa, których nie da się odtworzyć z kodu — łamiąc je, wywołasz regres, który testy
   przepuszczą (część reguł jest właśnie o tym, czego test nie zobaczy).
   ⚠️ **Jeśli plik konwencji dla tej powierzchni jeszcze nie istnieje** (katalog jest młody) —
   powiedz to wprost w podsumowaniu zamiast przemilczeć, i rozważ założenie go w Kroku 4, jeśli
   zadanie ustaliło niezmiennik.
   W podsumowaniu (Krok 5) wymień, który plik konwencji przeczytałeś — albo że go nie było.

### Krok 2 — Implementacja
Zrealizuj wymagania z pliku zadania, respektując decyzje z ADR-ów, rozstrzygnięcia z zadania
i **plik konwencji powierzchni z Kroku 1.6** — wiąże tak samo jak decyzja z ADR-a.
Przestrzegaj też ogólnych konwencji z `CLAUDE.md` (sekcja „Konwencje kodu").

### Krok 3 — Testy w zadeklarowanym zakresie
1. Uruchom **dokładnie to**, co deklaruje `## Zakres testów` — nie mniej i nie więcej:
   `docker compose exec app php artisan test --filter="NazwaKlasy"` (T1/T2) albo `docker compose exec app php artisan test` (T3).
   ⚠️ **Testy uruchamiaj w kontenerze** (`docker compose exec app …`) — pakiet biegnie na MySQL-u
   w schemacie `lowiska_test`, a bramka z `tests/TestCase.php` przerwie przebieg uruchomiony
   przeciwko innej bazie.
2. Uruchom `docker compose exec app vendor/bin/pint` na dotkniętych plikach — lint biegnie
   **niezależnie od tieru**, także przy pustym T1.
3. Czerwone testy w zadeklarowanym zakresie → napraw przed przejściem dalej. Nie raportuj
   zadania jako gotowego z czerwonym zakresem.
4. ⚠️ **Nie rozszerzaj samowolnie zakresu do pełnego pakietu** „na wszelki wypadek" — po to
   tier jest deklarowany. Jeśli w trakcie implementacji okaże się, że zmiana dotknęła
   fundamentów (patrz lista wyzwalaczy T3 w `CLAUDE.md`), **powiedz to wprost** i zaproponuj
   podniesienie tieru zamiast po cichu odpalać całość.

### Krok 4 — Dokumentacja
Zaktualizuj pliki wymienione w sekcji „Zmiany dokumentacji" pliku zadania:
- `docs/conventions/<powierzchnia>.md` — **domyślne miejsce** na nowy niezmiennik ustalony
  przez zadanie. Zasady wpisu: `CLAUDE.md`, sekcja „Gdzie ląduje wiedza z zakończonego zadania",
  oraz `docs/conventions/README.md`. W skrócie: **stan obowiązujący, nie przebieg zadania**;
  historia, pomiary i archeologia zostają w pliku zadania; regułę unieważnioną **przepisz**,
  nie dopisuj obok korekty.
- `README.md` — jeśli zmienił się sposób uruchamiania lub struktura plików
- `docs/operations/*.md` — jeśli zmieniło się środowisko, kontenery albo wdrożenie
- `CLAUDE.md` — **tylko** gdy zmiana obowiązuje niezależnie od tematu zadania (workflow, tiery,
  bezpieczeństwo, komendy, stack). Reguła powierzchniowa tu **nie trafia**.
  Jeśli zadanie domknęło któreś z miejsc oznaczonych `⛏️ do uzupełnienia` — **usuń ten znacznik**
  razem z wpisaniem treści.
- `CHANGELOG.md` — zaktualizuj changelog przez skill `changelog` (format keepachangelog 1.0.0 PL)

### Krok 5 — Podsumowanie
1. Wyświetl listę nowych plików w projekcie z krótkim opisem
2. Wyświetl listę zmienionych plików w projekcie z krótkim opisem
3. Podaj, który plik konwencji przeczytałeś w Kroku 1.6 — albo że dla tej powierzchni jeszcze
   nie istnieje
4. **Raport testów — sekcja obowiązkowa, nigdy jej nie pomijaj:**
   - **Tier:** zadeklarowany (albo dobrany, jeśli zadanie go nie miało).
   - **✅ Uruchomiono:** komendy + wynik (liczba testów, zielone/czerwone) + `pint`.
   - **⚠️ NIE uruchomiono:** przy tierze **T1/T2** napisz **wprost**, że **pełny pakiet testów
     nie był uruchomiony** i jest **odroczony na koniec sesji** — do zrobienia przez
     `/review-implementation` (Krok 1) albo ręcznie (`docker compose exec app php artisan test`).
   - Przy tierze **T3** napisz równie wprost, że pełny pakiet **przeszedł** — wtedy Krok 1
     `/review-implementation` można świadomie pominąć.

   ⚠️ Ta informacja nie jest kosmetyką raportu: to jedyne miejsce, w którym widać, że zielone
   testy zadania **nie znaczą** zielonego systemu. Podsumowanie bez niej jest niekompletne.
