# 005 — Trwały storage uploadów na buckecie GCS (dysk lokalny lokalnie, GCS na Cloud Run)

## Opis problemu

Dwa pola `FileUpload` w panelu administratora zapisują dziś na dysku `public`
(`storage/app/public`, symlink `public/storage`):

- `FisheryResource.map_image_path` (mapa łowiska, katalog `maps`) — [`FisheryResource.php:216`](app/Filament/Resources/FisheryResource.php:216),
- `FisheryResource.gallery_images` (galeria zdjęć, katalog `galleries`) — [`FisheryResource.php:222`](app/Filament/Resources/FisheryResource.php:222).

Docelowym środowiskiem uruchomieniowym jest Cloud Run (`fisherya.com` / `staging.fisherya.com`,
kontekst w zadaniach 001–003), gdzie system plików kontenera jest **efemeryczny per instancja**:
znika przy każdym wdrożeniu, przy każdym scale-to-zero i zimnym starcie (staging chodzi z
`min_instances 0`, więc dzieje się to tego samego dnia co upload) oraz przy restarcie po awarii.
Po przeskalowaniu produkcji powyżej jednej instancji dochodzi drugi tryb awarii: plik zapisany
przez instancję A jest niewidoczny dla żądania obsłużonego przez instancję B.

Awaria jest **cicha** — upload się udaje, panel pokazuje sukces, a obrazek przestaje się otwierać
dopiero po restarcie. Aplikacja nie działa jeszcze produkcyjnie, więc jest to defekt do usunięcia
**przed** pierwszym wdrożeniem, a nie po nim.

Fundament (`gcp-foundation`) swoją część już wykonał — nie ma tam nic do zrobienia:

- bucket `esurf-foundation-lowiska-<env>-storage` per środowisko (moduł `modules/app-storage`,
  instancja `lowiska_storage` w `environments/{staging,prod}/main.tf`, `public_read = true`),
- runtime SA aplikacji ma `roles/storage.objectAdmin` **na tym jednym buckecie** (nie projektowo) —
  zapis/odczyt/usuwanie działa **bez klucza JSON**, przez Application Default Credentials
  z metadata servera Cloud Run,
- `allUsers` ma `roles/storage.objectViewer` (publiczny odczyt — to są zdjęcia i mapy pokazywane
  odwiedzającym; publiczny odczyt nigdy nie oznacza publicznego zapisu),
- nazwa bucketa jako **niesekretny** output `lowiska.storage_bucket`
  (`terraform -chdir=environments/{staging,prod} output lowiska`).

Uzasadnienie architektoniczne (dlaczego GCS, model uprawnień, dlaczego bucket per środowisko) leży
w fundamencie: ADR-0013 („Persistent object storage for tenant file uploads") i **ADR-0014**
(„`modules/app-storage` is the default for any tenant with file uploads" — ten ADR wprost wymienia
Łowiska i oba pola `FisheryResource`). Cross-repo, nie linkowalne stąd — analogicznie do referencji
ADR-0012 w zadaniu 003. Podział ról jest w ADR-0014 zapisany wprost: **fundament ma bucket i grant,
repozytorium aplikacji ma pakiet Composera, konfigurację dysku `gcs` i przełączenie komponentów
`FileUpload` z dysku `public`.**

Krok po stronie aplikacji jest opisany w runbooku fundamentu
`docs/operations/lowiska-onboarding.md` § „File storage (GCS)" i to zadanie go realizuje, tym samym
wzorcem co bliźniaczy PunktySzczepień (zadanie 00055 tamże).

## Wymagania

- Dodać `spatie/laravel-google-cloud-storage` do `composer.json` (wersja kompatybilna z Laravel 12 /
  Flysystem 3 — bliźniaczy projekt stoi na `^2.4`). Pakiet opakowuje
  `league/flysystem-google-cloud-storage` i **dostarcza service provider rejestrujący sterownik
  `gcs`** przez package discovery. Laravel core nie ma adaptera GCS (`config/filesystems.php`
  wymienia tylko `local`/`ftp`/`sftp`/`s3`), a surowy pakiet league'a sam z siebie nie rejestruje
  żadnego dysku — wymagałby własnego `Storage::extend('gcs', …)`.
- Dodać dysk `gcs` w `config/filesystems.php`:
  - `bucket` z nowej zmiennej `GOOGLE_CLOUD_STORAGE_BUCKET` — wartość to fundamentowy output
    `storage_bucket`, wstrzykiwana jako zwykła zmienna wdrożenia, **nie sekret**;
  - **żadnej konfiguracji poświadczeń** (`key_file`, `key_file_path`, `project_id`) — klient
    `google/cloud-storage` rozwiązuje ADC z metadata servera Cloud Run. Jawna ścieżka do klucza
    byłaby błędem: kluczy SA w tej platformie nie ma i nie ma być;
  - `visibility` domyślnie `public` (zgodnie z tym, co dziś robi `visibility('public')` na dysku
    `public`);
  - **`'visibility_handler' => \League\Flysystem\GoogleCloudStorage\UniformBucketLevelAccessVisibility::class`
    jest obowiązkowe.** Bucket fundamentu ma `uniform_bucket_level_access = true` bezwarunkowo
    (potwierdzone w `modules/app-storage/main.tf`, nie założenie) — dostęp wyłącznie przez IAM,
    zero ACL per-obiekt. Bez tego handlera domyślny `PortableVisibilityHandler` próbuje ustawić
    legacy ACL przy każdym uploadzie i kończy się błędem `Cannot insert legacy ACL for an object
    when uniform bucket-level access is enabled` — ujawniłby się dopiero przy pierwszym realnym
    uploadzie na staging.
- **Domyślną wartością zostaje dysk lokalny; GCS włącza się wpisem w konfiguracji wdrożenia** —
  czyli `'default' => env('FILESYSTEM_DISK', 'local')` bez zmian, a na Cloud Run (staging, prod)
  `FILESYSTEM_DISK=gcs` i `FILAMENT_FILESYSTEM_DISK=gcs`. Ten sam układ co w PunktachSzczepień.
  Rozważane było odwrócenie (fallback `gcs`, dysk lokalny włączany z `.env`) i **zostało odrzucone**
  — uzasadnienie w sekcji `## Rozstrzygnięcia`.
- **Strażnik startowy zamiast odwróconego fallbacku.** Poza środowiskiem lokalnym aplikacja ma
  **odmówić startu**, jeśli dysk uploadów rozwiązuje się do sterownika `local` — to jest właściwa
  ochrona przed cichą utratą danych na efemerycznym kontenerze, bo pęka przy starcie kontenera
  (wpis w logach wdrożenia), a nie przy pierwszym uploadzie, jak zrobiłby to każdy wariant
  domyślnej wartości. Miejsce i dokładny warunek — patrz otwarte pytania.
- Zdjąć twarde `->disk('public')` z obu pól `FileUpload` w `FisheryResource`, żeby faktycznie szły
  za konfiguracją, a nie omijały ją niezależnie od `.env`. `visibility('public')` zostaje.
  ⚠️ To jest **świadomy rozjazd z PunktamiSzczepień**, gdzie pola mają przybite `disk('gcs')` obok
  ustawionego `FILAMENT_FILESYSTEM_DISK` — zapis redundantny, w którym zmienna środowiskowa niczego
  realnie nie przełącza.
- `.env.example` opisuje oba środowiska: wartość deweloperską plus zakomentowane wpisy dla Cloud Run
  z informacją, skąd brać nazwę bucketa (`terraform -chdir=environments/{staging,prod} output
  lowiska` → `storage_bucket`) i że lokalnie bucket nie jest potrzebny.
  ⚠️ Wartością dla developmentu jest **`public`**, nie dzisiejsze `FILESYSTEM_DISK=local` — dysk
  `local` ma korzeń w `storage/app/private` i nie wystawia publicznych URL-i, więc po zdjęciu
  twardego `->disk('public')` z pól formularza zepsułby podgląd obrazków.
- Sprawdzić generowanie publicznego URL-a (`Storage::disk('gcs')->url(…)`) — ma zwracać bezpośrednio
  otwierany URL do obiektu, nie URL wymagający podpisu (bucket jest publiczny do odczytu, więc
  podpisany URL byłby zbędny i fałszywie sugerowałby prywatny dostęp).
- Dopisać testy przepływu uploadu dla obu pól (`Storage::fake('gcs')`): plik ląduje na
  skonfigurowanym dysku, we właściwym katalogu (`maps` / `galleries`), a `visibility('public')` daje
  publiczny URL. Dziś **żaden** z tych przepływów nie ma pokrycia testowego. Osobny test pokrywa
  strażnika startowego.

## Kryteria akceptacji

- [x] `spatie/laravel-google-cloud-storage:^2.4` w `composer.json`, `composer install` (`composer
      require`) przeszedł czysto.
- [x] Dysk `gcs` w `config/filesystems.php` — bez konfiguracji poświadczeń, z `visibility_handler`
      ustawionym na `UniformBucketLevelAccessVisibility`, `throw` => `true` (patrz
      „Rozstrzygnięcia").
- [x] Oba pola `FileUpload` w `FisheryResource` nie mają twardego `->disk('public')` i podążają
      za konfiguracją — dowód: `tests/Feature/FisheryFileUploadTest.php` przełącza dysk na `gcs`
      w trakcie testu i weryfikuje, że plik faktycznie tam ląduje.
- [x] Lokalne środowisko Docker Compose zapisuje uploady jak dotychczas (dysk `public`), bez
      potrzeby posiadania bucketa i bez zmian w deweloperskim przepływie — potwierdzone pełnym
      zielonym przebiegiem pakietu (64/64), w tym `OwnerPanelTest`, który korzysta z zasobów
      łowisk.
- [x] Strażnik startowy: `AppServiceProvider::assertUploadDiskIsSafe()` odmawia startu poza
      `local`/`testing`, gdy dysk uploadów rozwiązuje się do sterownika `local`; komunikat wskazuje
      brakujące zmienne. Pokryte testem (`tests/Unit/UploadDiskGuardTest.php`, 5 przypadków).
- [x] `.env.example` niesie wartość deweloperską (`public`) i zakomentowane wpisy dla Cloud Run
      wraz ze źródłem nazwy bucketa.
- [x] Testy uploadu dla `map_image_path` i `gallery_images` istnieją i przechodzą.
- [x] Zakres testów zadeklarowany niżej (T3, pełny pakiet) jest zielony — 64/64, 221 asercji.
- [ ] Upload i odczyt zweryfikowane end-to-end na staging po wdrożeniu (ręczny upload w panelu,
      potwierdzony bezpośrednim otwarciem publicznego URL-a obrazka) — **to kryterium świadomie
      pozostaje otwarte**, domyka się po pierwszym wdrożeniu, nie w tej sesji.

## Zakres testów

- **Tier:** T3 — pełny pakiet
- **Uruchamiamy:** `docker compose exec app php artisan test`
- **Uzasadnienie:** T3 **nie jest tu przedmiotem wyboru** — zadanie zmienia `composer.json`, czyli
  pozycję z listy „T3 obowiązkowy" w `CLAUDE.md`, a nowa zależność wnosi własny service provider,
  więc dokłada element bootstrapu aplikacji. Drugim powodem jest strażnik startowy: kod wykonywany
  przy każdym starcie, na każdej ścieżce, którego błąd nie ujawnia się w żadnym pojedynczym filtrze.

## Zakres wyłączeń

- **Prywatny (niepubliczny) storage** — oba dzisiejsze uploady są z natury publiczne (obrazy
  pokazywane odwiedzającym). Gdyby pojawiła się potrzeba przechowywania czegoś prywatnego, to osobny
  bucket po stronie fundamentu (`public_read = false`, moduł to wspiera) i osobne zadanie tutaj.
  Per ADR-0014 fundamentu **nowy rodzaj treści z publicznym odczytem wymaga własnego ADR-u.**
- **Migracja istniejących plików** ze `storage/app/public` na bucket — aplikacja nie działa
  produkcyjnie, więc nie ma czego migrować; jednorazowe przeniesienie plików deweloperskich (jeśli
  ktoś ich potrzebuje) to krok operacyjny, nie część zadania.
- **Lokalny emulator GCS** (fake-gcs-server, MinIO) — lokalny development zostaje na dysku `public`.
- **Wdrożenie i konfiguracja Cloud Run** (wstrzyknięcie `FILESYSTEM_DISK`,
  `FILAMENT_FILESYSTEM_DISK` i `GOOGLE_CLOUD_STORAGE_BUCKET` do usługi, workflow GitHub Actions) —
  należy do zadania wdrożeniowego. To zadanie dostarcza jedynie kod, który tych zmiennych oczekuje,
  oraz strażnika, który wykryje ich brak.
- **Cokolwiek po stronie `gcp-foundation`** — bucket, granty i output już istnieją; to zadanie nie
  dotyka Terraform.
- **Pozostałe pola formularza `FisheryResource`** i jakikolwiek refaktor tego zasobu poza samym
  dyskiem uploadu.

## Zmiany dokumentacji

- [x] `docs/conventions/panel-admina.md` — nowy plik konwencji powierzchni: niezmiennik
      „`FileUpload` nie przybija dysku na sztywno" + ⚠️ pułapka `UniformBucketLevelAccessVisibility`
      + strażnik startowy, z odsyłaczem do ADR-0013/0014 fundamentu.
- [x] `docs/operations/obraz-produkcyjny.md` — nowa sekcja 9: granica „tak jest lokalnie (dysk
      `public`), tak jest na Cloud Run (bucket `gcs`)" oraz opis strażnika startowego.
- [x] `docs/operations/docker.md` — jedno zdanie przy opisie uploadów lokalnych z odsyłaczem do
      sekcji 9 w `obraz-produkcyjny.md`.
- [x] `README.md` — nowa sekcja „Uploady": zmienne `FILESYSTEM_DISK` / `FILAMENT_FILESYSTEM_DISK` /
      `GOOGLE_CLOUD_STORAGE_BUCKET` i skąd brać wartość bucketa.
- [x] `CLAUDE.md` — bez zmian (to niezmiennik powierzchni, nie reguła workflow).
- [x] `CHANGELOG.md` — wpis w sekcji „Poprawione".

## Ograniczenia techniczne

- Laravel 12, PHP 8.3 (`config.platform` w `composer.json`), Filament 3.3, Pest 3; testy uruchamiane
  **wyłącznie** przez `docker compose exec app php artisan test`.
- **Zero kluczy service account.** Nie dodawać `key_file`, `key_file_path` ani żadnej zmiennej
  z treścią klucza JSON — tożsamość runtime to ADC z metadata servera Cloud Run, tak jak WIF
  po stronie CI/CD. To ograniczenie całej platformy, nie preferencja tego zadania.
- **Nazwa bucketa nie jest sekretem** — to publiczny identyfikator (jak `sql_connection_name` czy
  `artifact_registry` w kontrakcie fundamentu), więc trafia jako zwykła zmienna wdrożenia, nie do
  Secret Managera.
- `spatie/laravel-google-cloud-storage` musi pociągać `league/flysystem-google-cloud-storage`
  w wersji wspierającej `league/flysystem: ^3.0` (Laravel 12 wymaga Flysystem 3).
- Bucket po stronie fundamentu musi być zaaplikowany (`terraform apply`) **zanim** dysk `gcs`
  zostanie włączony na staging/prod. Dla samej implementacji w repo nie jest to blokada — bez
  bucketa nie da się jedynie domknąć kryterium weryfikacji end-to-end.
- Strażnik startowy nie może wywracać lokalnego środowiska ani pakietu testów — warunek musi być
  związany ze środowiskiem, nie z samą wartością dysku.
- ~~Kolizja z zadaniem 004~~ **Rozwiązana: zadanie 004 jest zaimplementowane**
  (`docs/tasks/implemented/004-*.md`). `.env.example` i `docker-compose.yml` są dziś w stanie
  docelowym zadania 004; to zadanie dokłada do nich wyłącznie własne, nowe wpisy (dysk, bucket),
  bez ryzyka nadpisania.

## Rozstrzygnięcia

- **Fallback zostaje na dysku lokalnym (`env('FILESYSTEM_DISK', 'local')`), GCS włącza się jawnie
  w konfiguracji wdrożenia — jak w PunktachSzczepień.** Rozważany był wariant odwrotny (fallback
  `gcs`, dysk lokalny włączany wpisem w `.env`), z zamiarem zamiany cichej utraty danych na głośną
  awarię przy braku konfiguracji. Odrzucony z trzech powodów:
  1. **Ta „głośna awaria" nie istnieje.** Przy `bucket => null` klient GCS nie wybucha ani przy
     starcie, ani przy rozwiązywaniu dysku — obiekt bucketa jest leniwy, błąd przychodzi dopiero
     przy pierwszym wywołaniu API, czyli **przy pierwszym uploadzie**: równie późno jak cicha utrata
     danych, którą miał wykryć. Przy `'throw' => false` (konwencja pozostałych dysków w tym pliku)
     zapis w ogóle nie rzuca wyjątku, tylko zwraca `false`.
  2. **Domyślna wartość ma być bezpieczna dla środowiska najmniej kontrolowanego.** GCS jest
     właściwy w dwóch środowiskach, które mają jawną, wersjonowaną konfigurację wdrożenia
     i wypisany kontrakt w runbooku fundamentu. Dysk lokalny jest właściwy w każdym pozostałym
     kontekście: maszyny deweloperskie, CI, pakiet testów, ręczne `php artisan`. Odwrócony fallback
     psuje domyślne zachowanie dla wielu kontekstów, by zabezpieczyć dwa konfigurowane jawnie —
     widać to po koszcie: wymuszałby dopisanie nadpisania dysku w `phpunit.xml`.
  3. **Rozjazd między bliźniaczymi projektami kosztuje przy każdym kolejnym czytaniu obu**, a przy
     korzyści z punktów 1–2 nie ma czego nim kupić.
- **Realne ryzyko adresuje strażnik startowy, nie domyślna wartość.** Sprawdzenie przy starcie
  („poza środowiskiem lokalnym dysk uploadów nie może być sterownikiem `local`") pęka wcześniej
  i głośniej niż którykolwiek fallback — przy starcie kontenera, w logach wdrożenia, zanim
  ktokolwiek zdąży wgrać plik.
- **Pola `FileUpload` nie przybijają dysku na sztywno.** Inaczej zmienna środowiskowa niczego nie
  przełącza, a konfiguracja kłamie o tym, gdzie ląduje plik — to jest właśnie stan zastany
  w PunktachSzczepień i nie przenosimy go tutaj.
- **`.env.example` dostaje `FILESYSTEM_DISK=public`, nie dzisiejsze `local`.** Zweryfikowane
  w `config/filesystems.php`: dysk `local` ma korzeń `storage/app/private` i **nie ma klucza
  `url`**, więc `Storage::disk('local')->url(...)` nie działa. Po zdjęciu twardego `->disk('public')`
  z pól formularza domyślny dysk `local` zepsułby podgląd obrazków w środowisku deweloperskim —
  to nie jest hipoteza, tylko konsekwencja obecnej konfiguracji.
- **Strażnik startowy mieszka w `AppServiceProvider::boot()`**, nie w `bootstrap/app.php`. Ten
  drugi ma dziś wyłącznie okablowanie frameworka (middleware, routing, wyjątki); dokładanie tam
  logiki biznesowej zacierałoby tę granicę, mimo że tier T3 i tak by się nie zmienił.
  **Warunek:** `! app()->environment(['local', 'testing'])` **oraz** rozwiązany sterownik dysku
  (`config('filesystems.disks.' . config('filesystems.default') . '.driver')`) równy `local`.
  Białą listę trzeba objąć oba środowiska: `phpunit.xml` wymusza `APP_ENV=testing`, a nie nadpisuje
  `FILESYSTEM_DISK` — bez zwolnienia `testing` strażnik wywróciłby cały pakiet testów przy starcie
  aplikacji. Sprawdzenie rozwiązanego sterownika (nie samej zmiennej środowiskowej) to ten sam
  wzorzec co bramka w `tests/TestCase.php` (ADR-001).
  ⚠️ Strażnik **nie wymaga** dodatkowo niepustego `GOOGLE_CLOUD_STORAGE_BUCKET` — pusty bucket przy
  sterowniku `gcs` ujawni się głośno przy pierwszym uploadzie (błąd klienta GCS), co jest innym
  rodzajem awarii niż cicha utrata danych na dysku `local`, którą ten strażnik ma wyłapać. Mieszanie
  obu sprawdzeń w jednym warunku zaciera, co faktycznie się zepsuło.
- **`'throw' => true` na dysku `gcs`**, mimo rozjazdu z resztą `config/filesystems.php`
  (`false` wszędzie indziej) i z PunktamiSzczepień. Nieudany zapis do bucketa (sieć, uprawnienia)
  zwracałby dziś po cichu `false` — to ta sama kategoria cichej awarii, którą całe to zadanie ma
  usunąć. Zadanie już raz świadomie odeszło od wzorca PunktówSzczepień (przybite `disk('gcs')` na
  polu formularza) z tego samego powodu — spójność z bliźniakiem nie jest tu wartością nadrzędną.
- **Niezmiennik „`FileUpload` nie przybija dysku" trafia do `docs/conventions/panel-admina.md`**,
  nie do nowego, wspólnego pliku o storage'u. Dziś dotyczy wyłącznie `app/Filament/Resources/**`
  (panel administratora) — panel właściciela nie ma jeszcze żadnego pola uploadu. Zakładanie
  wspólnego pliku pod hipotetyczną przyszłą potrzebę byłoby projektowaniem na zapas; gdy panel
  właściciela dostanie własny `FileUpload`, to zadanie doda odsyłacz do `panel-admina.md` albo,
  jeśli reguła urośnie, wydzieli wspólny plik wtedy — nie teraz.

## Powiązane ADR-y

<!-- Numery ADR-ów podjętych dla tego zadania (uzupełnia /review-task).
     Tylko decyzje spełniające trzyskładnikowe kryterium z CLAUDE.md —
     reszta idzie do „Rozstrzygnięcia" powyżej. -->
- **Brak.** Uzasadnienie architektoniczne (GCS, ADC, uniform bucket-level access, bucket per
  środowisko) leży już w ADR-0013/ADR-0014 fundamentu (`gcp-foundation`, cross-repo) — to zadanie
  je wyłącznie realizuje. Cztery pozostałe pytania (umiejscowienie i warunek strażnika, `throw`
  na dysku `gcs`, wartość `.env.example`, plik konwencji) są każde odwracalne jedną linijką albo
  jednym przeniesieniem pliku — żadne nie spełnia kompletu kryterium ADR z `CLAUDE.md`, wszystkie
  rozstrzygnięte w treści zadania powyżej.

## Otwarte pytania — zamknięte przy `/review-task` (2026-08-15)

- ~~**Gdzie mieszka strażnik startowy i jaki jest jego warunek?**~~ → `AppServiceProvider::boot()`,
  warunek `! app()->environment(['local', 'testing'])` + rozwiązany sterownik — patrz
  „Rozstrzygnięcia".
- ~~**Czy dysk `gcs` ma mieć `'throw' => true`?**~~ → **Tak** — patrz „Rozstrzygnięcia".
- ~~**Czy `.env.example` ma zmienić `FILESYSTEM_DISK=local` na `public`?**~~ → **Tak**, potwierdzone
  weryfikacją `config/filesystems.php` (dysk `local` nie ma klucza `url`) — patrz „Rozstrzygnięcia".
- ~~**Gdzie trafia niezmiennik „`FileUpload` nie przybija dysku"?**~~ → `docs/conventions/panel-admina.md`
  — patrz „Rozstrzygnięcia".
