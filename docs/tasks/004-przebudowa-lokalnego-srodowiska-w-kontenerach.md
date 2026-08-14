# 004 — Przebudowa lokalnego środowiska deweloperskiego na kontenery (wzorzec WorkSnap)

## Opis problemu

Repozytorium ma Docker, ale środowisko deweloperskie jest niekompletne, wewnętrznie sprzeczne
i w jednym miejscu wprost niebezpieczne. Chcemy przenieść tu układ sprawdzony w bliźniaczych
projektach (WorkSnap, PunktySzczepień): **jeden `docker compose up` stawia wszystko**, łącznie
z bazą, serwerem zasobów i skrzynką pocztową, a pakiet testów nie ma fizycznej możliwości dosięgnąć
bazy roboczej.

**Co konkretnie jest nie tak dzisiaj:**

1. **Compose nie zawiera bazy.** Wszystkie trzy usługi łączą się do MySQL-a na hoście przez
   `host.docker.internal`, a dodatkowo niosą wpis `extra_hosts` z adresem `192.168.65.7` —
   wewnętrznym adresem Docker Desktopa, który zmienia się między wersjami i maszynami.
2. **Pakiet testów da się skierować na bazę roboczą.** `docker-compose.yml` wstrzykuje
   `DB_CONNECTION`/`DB_DATABASE` przez listę `environment:`, więc trafiają one do `$_SERVER`;
   `phpunit.xml` deklaruje wprawdzie SQLite `:memory:`, ale **żaden jego wpis nie ma
   `force="true"`**, a nawet z `force` przegrałby, bo ten atrybut zapisuje wyłącznie
   `putenv()`/`$_ENV`. Jedyną realnie działającą warstwą jest `export DB_DATABASE=test`
   w `test.sh`. W bliźniaczym projekcie ten sam układ doprowadził do wielokrotnego wyczyszczenia
   realnej bazy przez `RefreshDatabase` — wyśledzonego dopiero w dzienniku binarnym MySQL-a.
3. **`docker-compose.override.yml` kłóci się z plikiem bazowym.** Nakładka montuje `vendor`
   i `node_modules` jako wolumeny anonimowe i podmienia `dockerfile`, powtarzając to samo dla trzech
   usług. Efektywna konfiguracja jest wynikiem scalenia dwóch plików, czego nie widać w żadnym z nich.
4. **Usługa `app` montuje `./storage` na korzeń aplikacji** (`- ./storage:/var/www/html`) — czyli
   katalog `storage` hosta przykrywa cały kod. W środowisku deweloperskim maskuje to nakładka,
   która montuje `.` w tym samym miejscu; w `docker-compose.prod.yml` ten montaż zostaje.
5. **`container_name: laravel-app`** — nazwa generyczna, kolidująca z każdym innym projektem
   Laravel na tym samym Dockerze.
6. **Oba obrazy są niemal identyczne i oba ciężkie.** `Dockerfile.dev` i `Dockerfile.prod` różnią się
   właściwie tylko obecnością PCOV; każdy wpieka kod (`COPY . .`), instaluje zależności Composera
   i Node, buduje zasoby i przechodzi `chown -R` po całym drzewie przy każdym budowaniu.
7. **Brak serwera Vite i skrzynki pocztowej w kontenerach.** Zasoby buduje się na hoście,
   a `.env.example` kieruje pocztę na `host.docker.internal:1025`, czyli na coś, co deweloper musi
   sobie postawić sam. Aplikacja wysyła maile weryfikacyjne i resetu hasła (Breeze), więc odnośniki
   z tych wiadomości muszą być klikalne.
8. **`DB_DATABASE=łowiska`** w `.env.example` — nazwa bazy z polskim znakiem diakrytycznym.
9. **Produkcja stoi na Apache, a wdrożenie idzie na Cloud Run** (zadania 001–003 opisują już
   `fisherya.com` / `staging.fisherya.com`). Bliźniacze projekty używają na tej platformie
   FrankenPHP w trybie classic — jednoprocesowego, wielowątkowego, natywnie obsługującego `$PORT`.
   Apache z `apache2-foreground` i `.docker/vhost.conf` jest tu rozjazdem, który trzeba będzie
   i tak usunąć przed pierwszym wdrożeniem.

## Wymagania

### Usługi i porty

- Porty hosta mają być przesunięte o **+3000** względem standardowych portów kontenerów
  (WorkSnap używa +1000, PunktySzczepień +2000, więc trzy środowiska mają móc chodzić równocześnie):

  | Usługa | Host | Kontener |
  |---|---|---|
  | `app` | 11000 | 8000 |
  | `vite` | 8173 | 8173 |
  | `mysql` | 6306 | 3306 |
  | `mailpit` (skrzynka) | 11025 | 8025 |
  | `mailpit` (SMTP) | 4025 | 1025 |

- `docker-compose.yml` ma opisywać sześć usług: `app`, `vite`, `queue`, `scheduler`, `mailpit`,
  `mysql` — i ma być **jedynym** plikiem opisującym środowisko deweloperskie, bez nakładek.
- Wpisy `extra_hosts`, `container_name` i nazwana sieć `laravel` mają zniknąć — sieć domyślna
  Compose'a wystarcza, a usługi mają się widzieć po nazwach.
- Wolumeny nazwane: `dbdata` (dane MySQL-a), `vendor` (zależności PHP), `node_modules`. Katalogi
  `vendor` i `node_modules` **nie mogą** leżeć na powiązaniu z dysku Windows — to tysiące plików
  czytanych przy każdym żądaniu.
- Usługa `vite` ma montować `vendor` tylko do odczytu, jeśli którykolwiek arkusz stylów importuje
  cokolwiek z tego katalogu; w przeciwnym razie montaż ma zostać pominięty, a nie dodany „na zapas".

### Baza danych

- Usługa `mysql` na obrazie `mysql:8.4`, z bazą **`lowiska`** (bez znaku diakrytycznego — dzisiejsze
  `łowiska` w `.env.example` ma zostać poprawione), własnym użytkownikiem i kontrolą stanu
  (`mysqladmin ping`). Usługi `app`, `queue` i `scheduler` mają na nią czekać przez
  `depends_on: condition: service_healthy`.
- Katalog `docker/mysql/initdb/` ma zawierać skrypt tworzący schemat **`lowiska_test`** i nadający
  do niego uprawnienia użytkownikowi aplikacji. Obraz MySQL-a nadaje uprawnienia wyłącznie do bazy
  wskazanej w `MYSQL_DATABASE`, więc bez tego kroku konto aplikacji nie zobaczy drugiego schematu.
- ⚠️ Skrypty z `docker-entrypoint-initdb.d` wykonują się **tylko** przy tworzeniu pustego katalogu
  danych. Ma to być opisane w dokumentacji operacyjnej wraz z ręcznym odpowiednikiem dla środowisk,
  w których wolumen już istnieje.
- **Bez przenoszenia obecnych danych.** Kontener startuje pusty, stan roboczy odtwarza się przez
  `php artisan migrate --seed` (`DatabaseSeeder`) oraz `php artisan app:make-admin`
  (`MakeAdminCommand`). Baza na hoście ma zostać nietknięta — jest kopią zapasową do ewentualnego
  późniejszego przeniesienia.

### Jeden obraz, dwa cele

- `Dockerfile.dev` i `Dockerfile.prod` mają zostać zastąpione **jednym wieloetapowym
  `Dockerfile`** z celami `base` → `vendor` → `assets` → `dev` / `prod`, jak w WorkSnapie. Powód:
  oba dzisiejsze pliki są w 90% tym samym tekstem, a rozjazd między nimi jest niewidoczny do chwili
  wdrożenia.
- **PHP 8.3** — bez podnoszenia wersji w tym zadaniu (`composer.json` deklaruje `^8.2`, oba obrazy
  stoją dziś na 8.3). Rozszerzenia: `pdo_mysql`, `mbstring`, `exif`, `pcntl`, `bcmath`, `gd`, `zip`,
  `intl`, **`soap`** (wymagane przez `gusapi/gusapi` w `CSOService`), a w celu `dev` dodatkowo
  **`pcov`** — bez sterownika pokrycia nie zadziała etap testów mutacyjnych z `/review-implementation`.
- **Cel `dev`:** OPcache z `enable_cli=1`, `validate_timestamps=1`, `revalidate_freq=0` (`artisan serve`
  działa na interfejsie wiersza poleceń, więc bez `enable_cli` OPcache nie obejmuje serwowanej
  aplikacji), `memory_limit=1G` wyłącznie tutaj, Node 22 i klient MySQL-a. Polecenie domyślne:
  `php artisan serve --host=0.0.0.0 --port=8000`. **Bez** `COPY . .`, **bez** `npm run build`,
  **bez** `chown -R` — kod ma trafiać do kontenera przez powiązanie katalogu.
- Ma powstać `docker/dev-entrypoint.sh`, który przy starcie kontenera: instaluje zależności Composera,
  gdy brakuje `vendor/autoload.php`, tworzy `.env` z `.env.example`, gdy go nie ma, i generuje
  `APP_KEY`, gdy nie jest ustawiony.

### Cel produkcyjny na FrankenPHP

- Cel `prod` ma stanąć na FrankenPHP w trybie classic, zgodnie z układem używanym przez bliźniacze
  projekty na Cloud Run: `tini` jako proces numer jeden (poprawna propagacja `SIGTERM` przy
  skalowaniu), nasłuch na wstrzykiwanym `$PORT`, kontrola stanu na trasie `/up`.
- Mają powstać: `docker/Caddyfile`, `docker/prod-entrypoint.sh` oraz pliki ustawień PHP dla produkcji
  (`opcache` z `validate_timestamps=0`).
- **Entrypoint produkcyjny ma wykonywać `config:cache`, `route:cache` i `view:cache`** — to jest
  dokładnie ten mechanizm, który unieszkodliwia `env()` w kodzie aplikacji, więc **zadanie 001 musi
  być zrealizowane wcześniej albo równolegle**; inaczej pierwsze wdrożenie po tej zmianie po cichu
  zepsuje `CSOService` i adres administratora.
- Zasoby front-endu i zależności PHP mają wchodzić do obrazu z osobnych etapów (`assets`, `vendor`),
  nie być budowane w warstwie produkcyjnej.
- ⚠️ **Bez konfigurowania samego wdrożenia** — to zadanie dostarcza obraz zdolny działać na Cloud
  Run, a nie potok wdrożeniowy. Sposób wykonywania kolejek na tej platformie jest przedmiotem
  **zadania 003** i nie jest tu rozstrzygany; usługi `queue` i `scheduler` z Compose'a zostają jako
  element układu **lokalnego**.

### Izolacja testów — pięć warstw, a `test.sh` znika

`test.sh` ma zostać usunięty, ale **wolno go usunąć dopiero razem z kompletem warstw poniżej** —
dziś jest jedyną działającą ochroną, więc skasowanie go samego pogorszyłoby stan.

1. **`phpunit.xml`** — komplet zmiennych `DB_*` z `force="true"`, w tym **`DB_URL` wymuszony pusty**
   (bez tego zmienna `DATABASE_URL` ze środowiska przesłania host i schemat). Wymuszenie ma objąć
   też `APP_ENV`, `MAIL_MAILER`, `QUEUE_CONNECTION`, `SESSION_DRIVER`, `CACHE_STORE`, `BCRYPT_ROUNDS`
   oraz wyłączenie paska diagnostycznego i Flare'a.
2. **`docker-compose.yml`** — z usług `app`, `queue` i `scheduler` mają zniknąć wszystkie zmienne
   `DB_*` z listy `environment:`; żadna z tych usług nie może też dostać `env_file`. To jest warstwa,
   której dziś brakuje i przez którą warstwa pierwsza jest bezsilna.
3. **`tests/TestCase.php`** — bramka wykonawcza w `createApplication()`, sprawdzająca **rozwiązane**
   połączenie (nie same zmienne): środowisko musi być dokładnie `testing`, a baza musi być tą
   testową. Niezgodność ma przerywać **cały** pakiet przez `exit(1)` z czytelnym komunikatem —
   nieudana asercja przerwałaby tylko jeden test i wpuściła następny na złą bazę.
4. **`tests/Unit/PhpunitConfigInvariantTest.php`** — test parsujący `phpunit.xml` i czerwieniejący
   w chwili, gdy ktoś zdejmie `force="true"` z dowolnej zmiennej `DB_*` albo zmieni schemat testowy.
   Ma dziedziczyć po klasie bazowej PHPUnit, nie po `Tests\TestCase`, żeby nie uruchamiać bramki
   z warstwy trzeciej.
5. **Uprawnienia w bazie** — skrypt startowy MySQL-a z sekcji „Baza danych".

Po usunięciu `test.sh` komendą testów staje się `php artisan test` (oraz `--filter=…`).

### Aktualizacja workflow po usunięciu `test.sh`

⚠️ **`test.sh` jest wpisany w pięć plików sterujących pracą** — pominięcie tego kroku zostawi
workflow wskazujący na nieistniejący skrypt:

- `CLAUDE.md` (sekcje „Komendy", tiery T1/T2/T3, ostrzeżenie o izolacji testów),
- `.claude/commands/create-task.md`, `review-task.md`, `implement-task.md`, `review-implementation.md`,
- `docs/tasks/_template.md` (komentarz w sekcji „Zakres testów").

Ostrzeżenie w `CLAUDE.md` o słabym punkcie izolacji ma zostać **przepisane** na opis stanu
docelowego, nie opatrzone dopiskiem „nieaktualne".

### Serwer zasobów i poczta

- Usługa `vite` ma uruchamiać serwer deweloperski na porcie **8173**, nasłuchując na `0.0.0.0`. Port
  wewnętrzny musi równać się portowi hosta, bo adres pochodzenia wstrzykiwany na stronę pochodzi
  z konfiguracji serwera, nie z mapowania Compose'a.
- `vite.config.js` ma dostać sekcję `server`: `hmr.host` ustawione na `localhost` (przeglądarka łączy
  się z hosta, a `0.0.0.0` jest w przeglądarkach blokowane) oraz odpytywanie plików zamiast zdarzeń
  systemu plików, z pominięciem katalogów `vendor`, `node_modules`, `storage`, `bootstrap/cache`,
  `.git` i `public/build` — powiązanie katalogu z Windows nie przekazuje zdarzeń, a odpytywanie
  całego drzewa zatyka serwer.
- Usługa `mailpit` ma przyjmować pocztę deweloperską, a `.env.example` ma wskazywać ją jako serwer
  SMTP (`MAIL_HOST=mailpit`, `MAIL_PORT=1025`) zamiast dzisiejszego `host.docker.internal`.

### Pliki do usunięcia

- `test.sh` — po wprowadzeniu kompletu warstw izolacji.
- `docker-compose.override.yml` — treść wchłonięta do pliku bazowego.
- `docker-compose.prod.yml` — opisuje lokalne uruchomienie produkcji na Apache'u przez ten sam
  Compose; po przejściu na Cloud Run nie ma odbiorcy.
- `.docker/vhost.conf` wraz z całym katalogiem `.docker/` — konfiguracja Apache'a przestaje mieć
  zastosowanie w obu celach. Nowe pliki pomocnicze mają trafić do `docker/` (bez kropki), jak
  w bliźniaczych projektach.
- `Dockerfile.dev` i `Dockerfile.prod` — zastąpione jednym `Dockerfile`.

`.dockerignore` ma zostać przejrzany pod kątem nowej struktury — dziś wyklucza `Dockerfile*`
i `docker-compose*.yml`, a nie zna katalogu `docker/`.

## Kryteria akceptacji

- [ ] `docker compose up --build` na czystym klonie stawia komplet usług, a aplikacja odpowiada pod
      `http://localhost:11000`; serwer zasobów działa na 8173, skrzynka pocztowa na 11025,
      baza na 6306.
- [ ] `docker compose exec app php artisan migrate --seed` przechodzi na świeżym, pustym wolumenie bazy.
- [ ] `docker compose exec app php artisan app:make-admin` tworzy konto, a panele `/admin` i `/owner`
      otwierają się po zalogowaniu.
- [ ] Mail weryfikacyjny wysłany przy rejestracji pojawia się w skrzynce pod `http://localhost:11025`,
      a odnośnik w nim jest klikalny.
- [ ] Pełny pakiet testów jest zielony — patrz „Zakres testów".
- [ ] **Dowód, że bramka wykonawcza działa**: po celowym wskazaniu bazy roboczej pakiet ma się
      przerwać komunikatem bramki, **zanim** wykona jakąkolwiek migrację.
- [ ] **Dowód, że test-strażnik działa**: po celowym zdjęciu `force="true"` z dowolnej zmiennej `DB_*`
      `PhpunitConfigInvariantTest` ma być czerwony.
- [ ] Po pełnym przebiegu testów baza `lowiska` **w kontenerze** zawiera dane sprzed przebiegu,
      a baza na **hoście** pozostaje nietknięta.
- [ ] `docker build --target prod -t lowiska:prod .` kończy się powodzeniem, a uruchomiony obraz
      odpowiada na `/up` pod portem podanym w zmiennej `PORT`.
- [ ] Żaden plik w repozytorium nie odwołuje się już do `test.sh`, `.docker/`, `host.docker.internal`
      ani `192.168.65.7`.
- [ ] `grep -r "łowiska"` nie zwraca nazwy bazy z diakrytykiem w plikach konfiguracyjnych.

## Zakres testów

- **Tier:** T3 — pełny pakiet
- **Uruchamiamy:**
  ```bash
  docker compose exec app php artisan test
  ```
- **Uzasadnienie:** tier nie jest tu przedmiotem wyboru — lista wyzwalaczy T3 w `CLAUDE.md` wymienia
  `phpunit.xml`, `tests/TestCase.php`, `test.sh` i pliki Dockerfile, a zadanie zmienia wszystkie
  naraz, dokładając do tego przeniesienie bazy. Pełny pakiet jest też jedynym wiarygodnym
  sprawdzianem, że przeniesienie środowiska niczego nie zerwało.
  ⚠️ **Komenda podana wyżej obowiązuje po zakończeniu zadania.** W trakcie implementacji, dopóki
  komplet warstw izolacji nie stoi, testy uruchamiaj **wyłącznie** przez `./test.sh` — usunięcie
  skryptu jest ostatnim krokiem, nie pierwszym.

## Zakres wyłączeń

- **Potok wdrożeniowy i konfiguracja Cloud Run** — zadanie dostarcza obraz zdolny tam działać,
  ale nie zakłada `.github/workflows/`, nie konfiguruje projektu w chmurze i nie wdraża niczego.
- **Sposób wykonywania kolejek na Cloud Run** — to zadanie **003**; tutaj `queue` i `scheduler`
  zostają wyłącznie jako usługi lokalnego Compose'a.
- **`trustProxies`** — zadanie **002**.
- **Odczyt konfiguracji przez `config()`** — zadanie **001**, ale patrz ostrzeżenie przy entrypoincie
  produkcyjnym: bez 001 pierwsze wdrożenie z `config:cache` po cichu zepsuje `CSOService`.
- **Przenoszenie obecnych danych** z MySQL-a hosta.
- **Podnoszenie wersji PHP**, aktualizacja zależności Composera i npm.
- **Zrównoleglenie testów** (`--parallel`, zależność `paratest`) — osobne zadanie, wymaga własnego
  sprawdzenia stanu współdzielonego między testami.
- **Kod aplikacji** — panele, polityki, zasoby, widoki i testy merytoryczne. Jedynym wyjątkiem jest
  `tests/TestCase.php`, zmieniany jako warstwa izolacji.

## Zmiany dokumentacji

- [ ] `docs/operations/docker.md` — **napisany od nowa** po polsku: układ sześciu usług, tabela
      mapowania portów, baza testowa i jej ręczne utworzenie na istniejącym wolumenie, pięć warstw
      izolacji, znane pułapki (wolumeny nazwane dla `vendor`/`node_modules`, odpytywanie plików
      przez Vite, skrypty startowe MySQL-a). Dzisiejszy plik jest po angielsku i opisuje układ
      z bazą poza kontenerem, więc zostaje zastąpiony, nie uzupełniony.
- [ ] `docs/operations/obraz-produkcyjny.md` — nowy: cel `prod`, FrankenPHP, entrypoint z pamięcią
      podręczną konfiguracji, budowanie obrazu i uruchomienie lokalne pod `$PORT`.
- [ ] `CLAUDE.md` — sekcja „Komendy" (nowe porty, baza w kontenerze), tiery T1/T2/T3 z nową komendą
      testów, **przepisane** ostrzeżenie o izolacji testów.
- [ ] `.claude/commands/*.md` oraz `docs/tasks/_template.md` — zamiana `./test.sh` na
      `php artisan test` (patrz „Aktualizacja workflow po usunięciu `test.sh`").
- [ ] `README.md` — sposób uruchomienia środowiska deweloperskiego, adresy i porty.
- [ ] `CHANGELOG.md` — **pominięte**: zmiana dotyczy wyłącznie środowiska deweloperskiego i obrazu,
      jest niewidoczna dla użytkownika aplikacji.

## Ograniczenia techniczne

- Host to Windows 11 z Docker Desktopem; powiązania katalogów są wolne i nie przekazują zdarzeń
  systemu plików — stąd wolumeny nazwane dla `vendor`/`node_modules` i odpytywanie plików w Vite.
- WorkSnap zajmuje porty z przesunięciem +1000, PunktySzczepień +2000 — trzy środowiska mają móc
  chodzić jednocześnie.
- Laravel 12, Filament 3.3, Pest 3, PHP 8.3 — bez podnoszenia żadnej z tych wersji.
- ⚠️ **Front-end jest w stanie mieszanym:** `package.json` ma jednocześnie `tailwindcss` w wersji 3
  oraz `@tailwindcss/vite` w wersji 4, a w repozytorium leżą `tailwind.config.js`
  i `postcss.config.js`. Zadanie **nie porządkuje** tego stanu, ale usługa `vite` i etap `assets`
  muszą działać z tym, co jest — jeśli okaże się to niemożliwe bez zmiany wersji, zgłoś to zamiast
  aktualizować zależności po cichu.
- Pakiet testów działa na Pest: `tests/Pest.php` rozszerza `Tests\TestCase` i dokłada
  `RefreshDatabase` całemu katalogowi `Feature`, więc bramka z warstwy trzeciej obejmuje wszystkie
  testy funkcjonalne.
- Aplikacja nie działa jeszcze produkcyjnie (`CLAUDE.md`), więc nie ma danych produkcyjnych do
  ochrony — ale **są** dane robocze w lokalnym MySQL-u i to je chroni warstwa izolacji.

## Rozstrzygnięcia

<!-- Uzupełnia /review-task. -->
- **Skrypt startowy MySQL-a nadaje uprawnienia do `lowiska\_test\_%` od razu**, obok grantu na sam
  `lowiska_test`. Powód: teraz to jedna linia w skrypcie wykonywanym przy tworzeniu pustego wolumenu,
  a po dołożeniu `paratest` trzeba by ją wykonać ręcznie w każdym istniejącym środowisku — skrypty
  z `docker-entrypoint-initdb.d` nie uruchomią się po raz drugi.
  *(Rozstrzygnięcie obowiązuje przy silniku MySQL; jeśli ADR-001 rozstrzygnie na SQLite, cała
  warstwa uprawnień odpada.)*
- **`docker-compose.prod.yml` znika bez zamiennika.** Kryterium akceptacji dotyczące `/up` realizuje
  się przez `docker run -e PORT=8080 -p 8080:8080 lowiska:prod`, więc drugi plik Compose nie ma czego
  wnieść poza kolejnym miejscem, które trzeba trzymać zgodne z `Dockerfile`.
- **Pliki pomocnicze obrazów trafiają do `docker/` (bez kropki)**, katalog `.docker/` znika w całości
  — spójnie z bliźniaczymi projektami i z sekcją „Pliki do usunięcia".
- **`.env.example` ustawia `APP_URL=http://localhost:11000`** (port hosta, nie kontenera) — bez tego
  odnośniki w mailach weryfikacyjnych i resetu hasła wskazują port 8000 i nie są klikalne, a kryterium
  akceptacji tego wymaga. Tą samą zmianą `DB_HOST` przechodzi z `host.docker.internal` na `mysql`.
- **Nie powstaje `.env.testing`.** `phpunit.xml` z `force="true"`, bramka wykonawcza w
  `TestCase::createApplication()` (sprawdzająca rozwiązane połączenie, nie zmienne) i test-strażnik
  wystarczają; czwarte miejsce zapisu tej samej prawdy tylko tworzy ryzyko cichego rozjazdu.

## Powiązane ADR-y

<!-- Uzupełnia /review-task. -->
- [**ADR-001 — Silnik bazy danych w pakiecie testów**](../adr/ADR-001-silnik-bazy-w-pakiecie-testow.md)
  — **status: accepted, decyzja podjęta.** Testy biegną na **MySQL-u, w schemacie `lowiska_test`**;
  SQLite znika z pakietu w całości. Wszystkie pięć warstw izolacji jest obowiązkowe, a naprawa
  testów zaczerwienionych przez zmianę silnika należy do tego zadania. Szczegóły konsekwencji —
  w sekcji „Decyzja" ADR-a.
- [**ADR-002 — FrankenPHP jako serwer aplikacyjny obrazu produkcyjnego**](../adr/ADR-002-frankenphp-jako-serwer-obrazu-produkcyjnego.md)
  — **status: accepted, decyzja podjęta.** Cel `prod` stoi na **FrankenPHP w trybie classic**
  z `tini` jako `PID 1`, nasłuchem na `$PORT` i kontrolą stanu na `/up`; Apache i `.docker/` znikają.
  Tryb worker (Octane) świadomie odroczony do osobnego zadania. Szczegóły konsekwencji — w sekcji
  „Decyzja" ADR-a.

**Obie decyzje architektoniczne są rozstrzygnięte — bramka `/implement-task` na pustej sekcji
„Decyzja" jest otwarta.**
