# 001 — Odczyt konfiguracji przez `config()` zamiast `env()` w kodzie aplikacji

## Opis problemu

Dwa miejsca w kodzie aplikacji czytają zmienne środowiskowe bezpośrednio przez `env()`,
z pominięciem warstwy konfiguracji:

- `app/Services/CSOService.php:50` — `new GusApi(env('CSO_Key'))`
- `app/Helpers/Helper.php:353` — `$adminEmail = env('ADMIN_EMAIL')`

Laravel gwarantuje działanie `env()` **wyłącznie w plikach `config/`**. Gdy uruchomione zostanie
`php artisan config:cache` — a jest to standardowy krok obrazu produkcyjnego i praktycznie warunek
akceptowalnego czasu zimnego startu na Cloud Run — framework przestaje ładować `.env` i **`env()`
zwraca `null`**.

Skutek na produkcji, po cichu, bez błędu przy starcie:

- `CSOService::fetchAddress()` tworzy `GusApi(null)` → logowanie do rejestru GUS kończy się
  `InvalidUserKeyException`, czyli wyszukiwanie firmy po NIP/REGON przestaje działać w całym
  procesie weryfikacji podmiotu (`Helper`, `CompanyResource`, `VerifyCompany`);
- komunikat o firmie zajętej przez inną osobę renderuje się z pustym adresem administratora
  (`"… skontaktuj się z administratorem - ."`), czyli użytkownik dostaje instrukcję bez danych
  kontaktowych.

Dziś, przy uruchomieniu bez `config:cache`, obie ścieżki działają — problem ujawnia się dopiero
w środowisku kontenerowym z zbudowaną konfiguracją. Wdrożenie na Cloud Run
(`fisherya.com` / `staging.fisherya.com`) jest w przygotowaniu, więc jest to defekt do usunięcia
**przed** pierwszym wdrożeniem, a nie po nim.

## Wymagania

- Dodać wpisy odczytujące obie zmienne w warstwie konfiguracji (tam `env()` jest poprawne) —
  umiejscowienie ustalone w „Rozstrzygnięciach": **`config/services.php` → `cso.key`** (klucz
  rejestru GUS) oraz **`config/app.php` → `admin_email`** (adres administratora).
- `CSOService::fetchAddress()` pobiera klucz przez `config('services.cso.key')`, nie `env('CSO_Key')`.
- `Helper` (linia 353) pobiera adres administratora przez `config('app.admin_email')`,
  nie `env('ADMIN_EMAIL')`.
- Zachować dotychczasowe nazwy zmiennych środowiskowych (`CSO_Key`, `ADMIN_EMAIL`) — zmiana jest
  wewnętrzna, `.env` i konfiguracja wdrożenia pozostają bez zmian.
- Dodać **test-strażnik w pakiecie Pest** blokujący nawrót: asercja, że w `app/` nie występuje
  wywołanie `env(` (dozwolone wyłącznie w `config/`). To jest właściwa ochrona — sam test jednostkowy
  nie wykryje kolejnego takiego wywołania dopisanego w przyszłości. Test **nie dziedziczy** po
  `Tests\TestCase` (nie potrzebuje aplikacji ani bazy).
- Uzupełnić `.env.example` o komentarz, że obie zmienne są czytane przez `config/services.php`
  (dziś leżą luzem na górze pliku, bez powiązania z czymkolwiek).

## Kryteria akceptacji

- [ ] `grep -rn "env(" app/` nie zwraca żadnego trafienia.
- [ ] `config/services.php` zawiera oba klucze, a `CSOService` i `Helper` czytają wyłącznie przez `config()`.
- [ ] Test wykrywający `env(` w `app/` istnieje i przechodzi.
- [ ] Zakres testów zadeklarowany niżej (T2) jest zielony.
- [ ] Weryfikacja odporności na cache konfiguracji: po `php artisan config:cache` wyszukiwanie
      firmy po NIP w panelu działa, a komunikat o zajętej firmie zawiera adres administratora.
- [ ] Pełny pakiet testów jest **odroczony** na koniec sesji (`/review-implementation`, Krok 1) —
      nie jest kryterium tego zadania.

## Zakres testów

- **Tier:** T2 — zależności
- **Uruchamiamy:** `./test.sh --filter="CSOServiceTest|OwnerPanelTest|AdminPanelTest|NoEnvInAppTest"`
- **Uzasadnienie:** zmiana rusza źródło konfiguracji dla klasy używanej poza nią samą.
  `tests/Unit/CSOServiceTest.php` pokrywa klasę zmienianą, a panele (`OwnerPanelTest`,
  `AdminPanelTest`) są najbliższym istniejącym testem ścieżek, które wołają `CSOService`
  i `Helper` z poziomu formularza firmy. `NoEnvInAppTest` to test-strażnik **tworzony w tym zadaniu** —
  filtr musi go obejmować, inaczej nowy test nie wykona się ani razu przed zakończeniem zadania.
  Żaden wyzwalacz T3 z `CLAUDE.md` nie jest tu trafiony — zadanie nie dotyka `bootstrap/app.php`,
  `User`, polityk, providerów paneli, migracji ani `composer.json`. **Wybór testu zamiast statycznej
  analizy (patrz „Rozstrzygnięcia") jest tym, co utrzymuje tier na T2** — dołożenie PHPStan-a do
  `composer.json` przesunęłoby zadanie na T3.
  ⚠️ Nazwa `NoEnvInAppTest` jest wiążąca dla implementacji — komenda wyżej ma zadziałać bez poprawek.

## Zakres wyłączeń

- **Nie** zmieniamy nazw zmiennych środowiskowych (`CSO_Key` ma nietypową wielkość liter, ale
  zmiana wymagałaby równoległej korekty konfiguracji wdrożenia i sekretów w GCP — osobna decyzja).
- **Nie** refaktoryzujemy `CSOService` ani `Helper` poza samym odczytem konfiguracji.
- **Nie** dodajemy obsługi braku klucza (fallback, walidacja startowa) — to osobny temat.
- **Nie** dotykamy pozostałych `env()` w `config/**`, bo tam są poprawne.
- **Nie** wprowadzamy narzędzia statycznej analizy (PHPStan/Larastan/Psalm) — patrz „Rozstrzygnięcia".
  Nowa zależność w `composer.json` podniosłaby tier na T3 i wymaga własnego zadania: poziom ścisłości,
  plik konfiguracyjny, komenda w workflow.
- **Nie** zmieniamy treści komunikatu o firmie zajętej przez inną osobę ani zachowania przy pustym
  `ADMIN_EMAIL` — po zmianie komunikat renderuje się tak samo jak dziś, tylko źródłem wartości jest
  `config()`. Ewentualna wartość domyślna to część wyłączonej wyżej „obsługi braku klucza".

## Zmiany dokumentacji

- [ ] `docs/conventions/integracje.md` — nowy plik konwencji powierzchni (zadanie dotyka
      `app/Services/CSOService.php` i `app/Helpers/**`): niezmiennik „konfiguracja tylko przez
      `config()`, `env()` wyłącznie w `config/`" wraz z jednozdaniowym uzasadnieniem (`config:cache`).
- [ ] `README.md` — bez zmian
- [ ] `CLAUDE.md` — bez zmian (reguła jest niezmiennikiem powierzchni, nie regułą workflow)
- [ ] `CHANGELOG.md` — wpis w changelogu

## Ograniczenia techniczne

- Laravel 12, PHP 8.2+, Pest 3; testy uruchamiane **wyłącznie** przez `./test.sh`.
- Zmiana musi być zgodna wstecz ze środowiskiem lokalnym (Docker Compose bez `config:cache`).
- Docelowe środowisko uruchomieniowe to Cloud Run z konfiguracją wstrzykiwaną jako zmienne
  środowiskowe z Secret Managera — stąd wymóg, by warstwa `config/` była jedynym czytelnikiem `.env`.

## Rozstrzygnięcia

<!-- Punktowe decyzje ustalone przy /review-task: wygląd, copy, próg, nazwa, umiejscowienie.
     Wiążą implementację tak samo jak decyzje z ADR-ów, ale nie mają zasięgu poza zadaniem.
     Format: decyzja + jednozdaniowe uzasadnienie. -->
- **Klucz rejestru GUS → `config/services.php` jako `services.cso.key`.** GUS jest faktycznie usługą
  zewnętrzną uwierzytelnianą kluczem, czyli dokładnie tym, na co ten plik jest przeznaczony.
- **Adres administratora → `config/app.php` jako `app.admin_email`.** To dana kontaktowa aplikacji,
  a nie poświadczenie usługi zewnętrznej — nagłówek `config/services.php` deklaruje wprost
  „credentials for third party services", a wpis `services.admin` sugerowałby usługę, która nie istnieje.

  ```php
  // config/services.php
  'cso' => [
      'key' => env('CSO_Key'),
  ],

  // config/app.php
  'admin_email' => env('ADMIN_EMAIL'),

  // użycie
  config('services.cso.key')   // CSOService::fetchAddress()
  config('app.admin_email')    // Helper.php
  ```

- **Zakaz `env()` poza `config/` egzekwuje test w pakiecie Pest**, nie statyczna analiza. Powód:
  zero nowych zależności, strażnik biegnie w każdym przebiegu pakietu, a `composer.json` zostaje
  nietknięty — jego zmiana jest wyzwalaczem T3 i podniosłaby tier tego zadania z T2. Wprowadzenie
  PHPStan/Larastan pozostaje **osobnym zadaniem** (własna zależność, plik konfiguracyjny, poziom
  ścisłości, komenda w workflow), a nie doklejką do dwóch zmienianych linijek.
- **Test-strażnik skanuje wyłącznie `app/`** i nie dziedziczy po `Tests\TestCase` (nie potrzebuje
  aplikacji ani bazy) — `env()` w `config/**` jest poprawne i ma pozostać dozwolone.
- **Zadanie 001 idzie przed zadaniem 004.** Zadanie 004 usuwa `test.sh`, na którym stoi komenda
  testów tego zadania, a jednocześnie wprowadza `config:cache` do entrypointu produkcyjnego — czyli
  uzbraja dokładnie tę minę, którą 001 rozbraja. Odwrotna kolejność wymaga najpierw poprawienia
  komendy testowej w tym pliku.

## Powiązane ADR-y

<!-- Numery ADR-ów podjętych dla tego zadania (uzupełnia /review-task).
     Tylko decyzje spełniające trzyskładnikowe kryterium z CLAUDE.md —
     reszta idzie do „Rozstrzygnięcia" powyżej. -->
- **Brak.** Obie kwestie otwarte przy `/review-task` zamknięto rozstrzygnięciami w treści zadania.
  Umiejscowienie kluczy konfiguracji to nazwa i lokalizacja wpisu — odwracalna jedną linijką, czyli
  wprost anty-sygnał z `CLAUDE.md`. Wybór narzędzia strażnika **mógłby** być ADR-em, ale tylko
  w wariancie „wprowadzamy statyczną analizę do projektu" — a ten wariant został z tego zadania
  wyłączony i doczeka się własnego zadania z własnym ADR-em, jeśli powstanie. Sam test w istniejącym
  pakiecie nie wiąże niczego poza jednym plikiem.
