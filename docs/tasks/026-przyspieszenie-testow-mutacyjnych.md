# 026 — Przyspieszenie testów mutacyjnych

## Opis problemu

Testy mutacyjne Pesta trwają godzinami, przez co bywają odkładane albo zawężane. Pomiar
z 24.09.2026 (kontener `app`, PCOV, MySQL `lowiska_test`) pokazał, że czas nie idzie na same
testy, tylko na dwa stałe narzuty:

| Składnik | Czas | Kiedy się płaci |
|---|---|---|
| Start procesu PHP bez bazy (`tests/Unit/IbanValidationTest.php`) | ok. 0,4 s | raz na proces |
| `migrate:fresh` przez `RefreshDatabase` (46 migracji) | **ok. 8–12 s** | **raz na każdego mutanta** |
| Typowy test funkcjonalny po migracjach | ok. 1 s | na test |
| Faza pokrycia: cały pakiet (604 testy) z PCOV przed mutacjami | **555 s** | **raz na każde wywołanie `--mutate`** |

Przebieg na jednej małej klasie (`App\Rules\PriceRuleDatesAreOrdered`, 54 linie, 27 mutantów)
trwał **939 s**: 555 s fazy pokrycia i 379 s mutacji, czyli **ok. 14 s na mutanta**, z czego
60–85% to migracje.

**Skąd te narzuty (sprawdzone w kodzie `pestphp/pest-plugin-mutate`):**
- Każdy mutant uruchamia się w **osobnym procesie** (`MutationTest.php`, `new Process(...)`,
  z `--bail` i filtrem testów pokrywających zmutowaną linię). Nowy proces oznacza, że
  `RefreshDatabase` z `tests/Pest.php` robi pełne `migrate:fresh` przed pierwszym testem.
- Faza pokrycia uruchamia **cały pakiet** niezależnie od liczby mutowanych klas. Procedura
  `/review-implementation` (Krok 3C, `.claude/commands/review-implementation.md`) woła dziś
  `--mutate` **osobno dla każdej klasy**, więc płaci tę fazę tyle razy, ile jest klas.
- Mutacje biegną jedna po drugiej, choć kontener ma 8 rdzeni, a Pest obsługuje `--parallel`.

**Skala:** przebieg z zadania 023 (29 klas, szacunkowo ok. 580 mutantów) to dziś ok. 6,5–7 h.
Szacunek po zadaniu: ok. 15–20 min (patrz „Wymagania" i kryteria).

**Rozważone i odrzucone: przejście pakietu na SQLite.** Dałoby na mutanta mniej więcej ten sam
zysk co wymaganie 2, ale nie rusza fazy pokrycia ani braku równoległości, a łamie ADR-001:
kolacja `utf8mb4_unicode_ci` (na niej stoi np. `PositionLabelDuplicateGuard`), sortowanie
z polskimi znakami, kolumny JSON i migracje pisane pod MySQL. Silnik zostaje bez zmian.

## Wymagania

### 1. Jedno wywołanie `--mutate` dla całego zestawu klas

- Procedura `/review-implementation` (Krok 3A–3C) i wzorzec w zadaniach uruchamiają mutacje
  **jednym wywołaniem** z listą klas po przecinku:
  `vendor/bin/pest --mutate --covered-only --class="App\\Services\\A,App\\Rules\\B"`.
  Obsługa listy jest potwierdzona w kodzie (`CliConfiguration.php:127`,
  `explode(',', …)`).
- Procedura zapisuje trzy pułapki dopasowania klas (`MutationGenerator::doesNotContainClassToMutate()`):
  - **nazwa klasy jest prefiksem** — `StayOffer` obejmuje też `StayOfferVerdict`,
    `SaleCalendar` także `SaleCalendarCell/Grid/Row`;
  - **przecinek na końcu listy (pusta pozycja) mutuje całą aplikację** — lista bez pustych
    pozycji;
  - **przestrzeń nazw jest prefiksem** — `App\Services` obejmuje cały katalog z podkatalogami.
- Szacunek kosztu w procedurze (Krok 3A, pkt 3) liczy fazę pokrycia **raz** na przebieg.

### 2. Tryb mutacji bez `migrate:fresh` w każdym procesie

- Gdy proces jest procesem mutanta (Pest ustawia zmienną `PEST_MUTATION_TESTING`),
  przygotowanie bazy wykonuje **`migrate`** (przy aktualnym schemacie to jedno zapytanie do
  tabeli migracji), a nie `migrate:fresh`. Izolacja testów zostaje jak dziś: każdy test
  w transakcji wycofywanej po teście.
- Aktualny schemat zostawia faza pokrycia, która biegnie zwykłym trybem (`migrate:fresh`).
- **Zwykły pakiet (bez zmiennej) działa dokładnie jak dziś.**
- **Przełącznik to własny trait w `tests/`**, który korzysta z `RefreshDatabase` i nadpisuje
  `refreshTestDatabase()`: przy `PEST_MUTATION_TESTING` wykonuje `migrate`, bez niej woła
  oryginalne `migrate:fresh`. `tests/Pest.php` dołącza ten trait do katalogu `Feature`
  zamiast `RefreshDatabase`.
  - Nadpisanie w `TestCase` **nie zadziała**: Pest dokłada trait do klasy testu, a metoda
    traitu przebija metodę dziedziczoną z `TestCase`.
  - `class_uses_recursive()` nadal widzi `RefreshDatabase`, więc mechanizm równoległy Laravela
    (`TestDatabases::bootTestDatabase()`) działa bez zmian.

### 3. Przebieg równoległy

- Procedura uruchamia mutacje z **`--parallel --processes=6`** (6 z 8 rdzeni kontenera;
  zapas dla MySQL-a i Vite biegnących obok).
- W trybie równoległym Pest ustawia `LARAVEL_PARALLEL_TESTING` i `TEST_TOKEN`, a Laravel
  przełącza każdy proces na własny schemat `lowiska_test_test_{N}`. Uprawnienia do
  `lowiska\_test\_%` nadaje już `docker/mysql/initdb/01-test-schema.sql` (zadanie 004).
- **Bramka w `TestCase::createApplication()` nie może zostać osłabiona.** Akceptuje wyłącznie
  `lowiska_test` albo `lowiska_test_test_{liczba}` — nic innego.
- **Dwie kontrole, jedna funkcja sprawdzająca nazwę:**
  - `createApplication()` sprawdza środowisko i bazę jak dziś;
  - dodatkowo rejestruje **własny callback `ParallelTesting::setUpTestCase`**. Laravel wywołuje
    te callbacki po przełączeniu schematu (callback Laravela jest zarejestrowany wcześniej, przy
    starcie aplikacji), ale **przed** traitami, czyli przed migracjami. Callback sprawdza
    schemat faktycznie używany przez połączenie i przy niezgodności przerywa pakiet przez
    `exit(1)`, tak jak pierwsza kontrola.
- Tryb z wymagania 2 działa także w procesach równoległych (każdy schemat migruje się
  w całości tylko przy pierwszym użyciu).
- **Każdy przebieg zaczyna od czystych schematów równoległych.** Proces w trybie zwykłym (bez
  `PEST_MUTATION_TESTING` — w przebiegu mutacji jest nim faza pokrycia) przy pierwszym teście
  usuwa pozostałe schematy `lowiska_test_test_{liczba}`. Chroni to przed nieaktualnym schematem,
  gdy migracja została poprawiona w miejscu (bez nowej nazwy): `migrate` sprawdza tylko nazwy.
  - Usuwanie działa **wyłącznie** na nazwach pasujących dokładnie do
    `^lowiska_test_test_[0-9]+$`, sprawdzanych przed każdym `DROP` — nigdy na `lowiska`
    ani `lowiska_test`. Test to potwierdza.
  - Przerwany proces mutanta nie zostawia danych: MySQL wycofuje niezatwierdzoną transakcję
    po zerwaniu połączenia.

### 4. Dokumentacja decyzji

- ADR-001 dostaje sekcję **„Aktualizacja"**: zrównoleglenie wprowadzone, tryb mutacji bez
  `migrate:fresh`, silnik bez zmian, SQLite rozważony i odrzucony z pomiarem.
- `docs/operations/docker.md` — izolacja testów: schematy równoległe i tryb mutacji.

## Kryteria akceptacji

- [ ] Procedura `/review-implementation` uruchamia mutacje jednym wywołaniem z listą klas
      i `--parallel`, z opisem trzech pułapek dopasowania klas.
- [ ] Ponowny pomiar na `App\Rules\PriceRuleDatesAreOrdered` (ta sama klasa, ten sam
      kontener): czas na mutanta spada z ok. 14 s do **≤ 4 s** przy przebiegu sekwencyjnym,
      a wynik (27 mutantów, 4 ocalałe, 85,19%) jest **taki sam** jak przed zmianą.
- [ ] Przebieg równoległy na tej samej klasie daje ten sam wynik co sekwencyjny.
- [ ] Zwykły pakiet (`docker compose exec app php artisan test`) jest zielony i nie zmienia
      zachowania: bez zmiennej `PEST_MUTATION_TESTING` baza jest przygotowywana przez
      `migrate:fresh` jak dziś.
- [ ] Bramka izolacji: test potwierdza, że akceptuje `lowiska_test` i `lowiska_test_test_{N}`,
      a odrzuca każdy inny schemat (w tym bazę roboczą `lowiska` i np. `lowiska_test_x`).
      Druga kontrola działa w callbacku `ParallelTesting` po przełączeniu schematu, przed
      migracjami. `PhpunitConfigInvariantTest` zielony bez zmian.
- [ ] Czyszczenie schematów równoległych usuwa wyłącznie nazwy `^lowiska_test_test_[0-9]+$`
      (test z nazwami `lowiska`, `lowiska_test`, `lowiska_test_x` — żadna nie jest ruszana).
- [ ] Żadna z pięciu warstw izolacji (CLAUDE.md, „Bezpieczeństwo bazy danych") nie jest
      osłabiona.
- [ ] ADR-001 ma sekcję „Aktualizacja", `docs/operations/docker.md` opisuje schematy
      równoległe i tryb mutacji.
- [ ] Zielony pełny pakiet (T3).

## Zakres testów

- **Tier:** T3
- **Uruchamiamy:** `docker compose exec app php artisan test`
- **Uzasadnienie:** zadanie zmienia `tests/TestCase.php` (bramkę izolacji) i sposób
  przygotowania bazy w testach — oba są na liście wyzwalaczy T3 w `CLAUDE.md`, więc tier nie
  jest przedmiotem wyboru. Do tego ponowny pomiar mutacji z kryteriów (przebieg sekwencyjny
  i równoległy na `App\Rules\PriceRuleDatesAreOrdered`).

## Zakres wyłączeń

- **Zmiana silnika testów na SQLite** — odrzucona (patrz „Opis problemu"), ADR-001 zostaje.
- **Zrównoleglenie zwykłego pakietu** (`php artisan test --parallel`) — to zadanie dotyczy
  mutacji; ewentualne rozszerzenie na zwykły pakiet osobno.
- **`schema:dump`** — rozważony; tryb z wymagania 2 daje większy zysk na mutanta bez pliku
  schematu do utrzymania.
- **4 ocalałe mutanty w `PriceRuleDatesAreOrdered`** (linia 52, obcinanie daty `substr`
  do 10 znaków: `UnwrapSubstr` i warianty długości) — luka w testach cennika, do poprawy
  osobno. Tutaj służą wyłącznie jako punkt odniesienia pomiaru.
- Zmiany w samym silniku mutacji Pesta (`vendor/`).

## Zmiany dokumentacji

- [ ] `.claude/commands/review-implementation.md` — Krok 3A (szacunek kosztu: faza pokrycia
      raz), Krok 3C (jedno wywołanie z listą klas, `--parallel`, pułapki dopasowania).
- [ ] `docs/adr/ADR-001-silnik-bazy-w-pakiecie-testow.md` — sekcja „Aktualizacja".
- [ ] `docs/operations/docker.md` — schematy równoległe `lowiska_test_test_{N}`, tryb mutacji.
- [ ] `CLAUDE.md` — sekcja „Bezpieczeństwo bazy danych": dopisać do warstwy 3, że bramka
      akceptuje także schematy równoległe (i tylko je), ma drugą kontrolę w callbacku
      `ParallelTesting` i że faza zwykła czyści schematy `lowiska_test_test_{N}`.
- [ ] `CHANGELOG.md` — wpis przez skill `changelog` (zmiana narzędzi deweloperskich).

## Ograniczenia techniczne

- Laravel 13, Pest 5 z `pestphp/pest-plugin-mutate`, PHP 8.4, MySQL 8.4, PCOV w obrazie dev.
- ⚠️ **Nie uruchamiać `migrate:fresh`, `migrate:refresh`, `db:wipe` ręcznie** bez zgody autora
  (CLAUDE.md) — także przy pomiarach. Migracje wykonuje wyłącznie `RefreshDatabase` w testach.
- ⚠️ **Pięć warstw izolacji zostaje w całości**; `force="true"` w `phpunit.xml` i brak `DB_*`
  w Compose bez zmian. Bez `.env.testing`.
- W trakcie przebiegu mutacji nie uruchamiać niczego innego, co dotyka bazy testowej.

## Rozstrzygnięcia

Ustalone przy `/review-task`, 24.09.2026:

- **Z pomiaru i wywiadu:** 4 ocalałe mutanty w `PriceRuleDatesAreOrdered` poprawiane osobno;
  tryb mutacji to `migrate` zamiast `migrate:fresh` w procesie mutanta (nie `schema:dump`).
- **Bramka: druga kontrola w callbacku `ParallelTesting::setUpTestCase`.** To jedyny punkt, który
  widzi już przełączony schemat, a jeszcze przed migracjami; obie kontrole używają jednej funkcji
  sprawdzającej nazwę.
- **`--processes=6`** — zapas dwóch rdzeni dla MySQL-a i Vite w tym samym środowisku Dockera.
- **Przełącznik trybu mutacji jako własny trait w `tests/Pest.php`.** Nadpisanie w `TestCase`
  nie działa, bo metoda traitu na klasie testu ma pierwszeństwo. Flaga
  `RefreshDatabaseState::$migrated` odrzucona — zależy od wewnętrznego szczegółu frameworka.
- **Faza zwykła czyści schematy `lowiska_test_test_{N}`** (ścisły wzorzec nazwy), żeby migracja
  poprawiona w miejscu nie zostawiała nieaktualnego schematu.
- **Brak nowego ADR-a.** Zmiana bramki wiąże każdy test i jej uzasadnienie jest warte zapamiętania,
  ale odwraca się jedną zmianą w `tests/`, bez migracji danych. Zapis trafia do sekcji
  „Aktualizacja" ADR-001, który ustanowił bramkę i przygotował uprawnienia do schematów
  równoległych.

## Powiązane ADR-y
<!-- Numery ADR-ów podjętych dla tego zadania (uzupełnia /review-task).
     Tylko decyzje spełniające trzyskładnikowe kryterium z CLAUDE.md —
     reszta idzie do „Rozstrzygnięcia" powyżej. -->
- 
