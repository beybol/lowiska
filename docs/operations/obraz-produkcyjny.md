# Obraz produkcyjny

Cel `prod` w `Dockerfile` buduje obraz zdolny działać na **Cloud Run**. Serwerem jest **FrankenPHP
w trybie classic** — decyzja i alternatywy w [ADR-002](../adr/ADR-002-frankenphp-jako-serwer-obrazu-produkcyjnego.md).

⚠️ **Ten dokument opisuje obraz, nie wdrożenie.** Potok wdrożeniowy, konfiguracja projektu w chmurze
i sposób wykonywania kolejek na tej platformie (zadanie 003) są poza zakresem zadania 004.

---

## 1. Budowanie i uruchomienie lokalne

```bash
docker build --target prod -t lowiska:prod .
docker run --rm -e PORT=8080 -p 8080:8080 lowiska:prod
curl http://localhost:8080/up
```

Obraz nie ma własnego pliku Compose — `docker-compose.prod.yml` został usunięty w zadaniu 004.
Powód: opisywał uruchomienie produkcji na Apache'u przez lokalny Compose, a po przejściu na Cloud
Run jedynym potrzebnym sprawdzeniem jest `docker run` powyżej.

---

## 2. Etapy budowania

Jeden wieloetapowy `Dockerfile` obsługuje oba środowiska:

```
base ──┬─→ vendor ──┐
       │            ├─→ prod
       ├─→ assets ──┘
       └─→ dev
```

| Etap | Rola |
|---|---|
| `base` | PHP 8.4 + rozszerzenia (`pdo_mysql`, `mbstring`, `exif`, `pcntl`, `bcmath`, `gd`, `zip`, `intl`, **`soap`**) + Composer |
| `vendor` | `composer install --no-dev --optimize-autoloader` |
| `assets` | Node 22, `npm ci`, `npm run build` |
| `dev` | `base` + `pcov` + Node + klient MySQL-a; kod wchodzi **powiązaniem katalogu** |
| `prod` | FrankenPHP + `tini`; zasoby i zależności **kopiowane z etapów**, nie budowane tutaj |

`soap` jest wymagany przez `gusapi/gusapi` (`CSOService`) — bez niego wyszukiwanie firmy po NIP-ie
przestaje działać. `pcov` żyje wyłącznie w celu `dev`, bo bez sterownika pokrycia nie zadziała etap
testów mutacyjnych z `/review-implementation`.

⚠️ Zasoby front-endu i zależności PHP **nie są budowane w warstwie produkcyjnej**. Poprzedni
`Dockerfile.prod` robił `npm run build || true` — nieudane budowanie zasobów przechodziło po cichu,
a obraz i tak powstawał. Nie wracaj do tego wzorca.

---

## 3. Czego wymaga Cloud Run

| Wymaganie | Jak jest spełnione |
|---|---|
| Nasłuch na wstrzykiwanym `$PORT` | `prod-entrypoint.sh` ustawia `SERVER_NAME=":$PORT"`, `Caddyfile` czyta `{$SERVER_NAME}` |
| Jeden proces | FrankenPHP w trybie classic — serwer i PHP w jednym procesie |
| Czysta obsługa `SIGTERM` | `tini` jako `PID 1`; platforma wysyła ten sygnał przy skalowaniu w dół |
| Kontrola stanu | trasa `/up` (`bootstrap/app.php`, `health: '/up'`) |

⚠️ **Port nie może być wpisany na sztywno.** Poprzedni obraz miał `EXPOSE 80`
i `<VirtualHost *:80>` — na Cloud Run nie wystartowałby.

---

## 4. Entrypoint i pamięć podręczna konfiguracji

`docker/prod-entrypoint.sh` wykonuje przy starcie:

```
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

⚠️ **`config:cache` unieszkodliwia `env()` poza katalogiem `config/`** — po zbudowaniu pamięci
podręcznej framework przestaje ładować `.env`, a `env()` wywołane z `app/` zwraca `null`, **bez
żadnego błędu przy starcie**. Cała konfiguracja musi być czytana przez `config()`; niezmiennik
opisuje [`docs/conventions/integracje.md`](../conventions/integracje.md), a pilnuje go test
`tests/Unit/NoEnvInAppTest.php` (zadanie 001).

---

## 5. Ustawienia PHP

`docker/php/prod.ini` — OPcache z **`validate_timestamps=0`** (kod jest wpieczony w obraz i nie
zmienia się w trakcie życia instancji, więc sprawdzanie znaczników czasu byłoby czystym kosztem),
`expose_php=Off`, `memory_limit=256M`.

Odpowiednik deweloperski (`docker/php/dev.ini`) ma odwrotne ustawienia: `validate_timestamps=1`,
`revalidate_freq=0` i `enable_cli=1` — `artisan serve` działa na interfejsie wiersza poleceń, więc
bez `enable_cli` OPcache w ogóle nie obejmowałby serwowanej aplikacji.

---

## 6. Tryb worker (Octane) — świadomie odroczony

Obraz stoi na trybie **classic**, nie worker. Tryb worker trzyma aplikację w pamięci między
żądaniami i wymaga przeglądu stanu współdzielonego (statyczne właściwości, singletony, kontener
usług) — to osobne zadanie z własnym sprawdzeniem. Przejście nie wymaga zmiany serwera ani obrazu,
więc nic nie jest tu zamknięte.

---

## 7. Zaufanie do proxy — dlaczego `trustProxies` jest wymagane

Cloud Run terminuje TLS na froncie Google — do kontenera trafia zwykły HTTP z nagłówkami
`X-Forwarded-Proto`, `X-Forwarded-For`, `X-Forwarded-Port`. Bez zaufanego proxy Laravel widzi
połączenie jako `http` i generuje adresy zasobów (`asset()`, `url()`, Vite, Filament) ze schematem
`http://` na stronie serwowanej po `https://` → przeglądarka blokuje je jako **mixed content** →
panele Filamenta renderują się bez styli i bez JS.

`bootstrap/app.php` deklaruje:

```php
$middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR
    | Request::HEADER_X_FORWARDED_PORT
    | Request::HEADER_X_FORWARDED_PROTO);
```

- **`at: '*'`** jest bezpieczne wyłącznie dlatego, że do kontenera na Cloud Run nie da się dostać
  z pominięciem frontu Google — adresy proxy nie są stałą pulą, więc lista IP jest niewykonalna.
- **Maska nagłówków jest jawna i celowo nie obejmuje `HEADER_X_FORWARDED_HOST` ani `_PREFIX`.**

⚠️ **Nigdy nie wywołuj `trustProxies()` bez argumentu `headers:`.** Domyślna maska Laravela
zawiera `X-Forwarded-Host`; w połączeniu z `at: '*'` oznacza to, że **dowolny klient dyktuje host**,
z którego Laravel buduje adresy absolutne i podpisy URL-i (linki resetu hasła, weryfikacji e-maila)
— zatrucie hosta, CWE-644. Podpis URL-a **nie chroni**: sygnatura liczona jest z `$request->url()`,
które czyta ten sam podrobiony nagłówek. Pełne uzasadnienie i alternatywy w
[ADR-003](../adr/ADR-003-zaufanie-do-proxy-i-maska-naglowkow.md); znane wcześniejsze wystąpienie
tego wzorca — [`docs/security/2026-08-15-trustproxies-bez-maski-naglowkow.md`](../security/2026-08-15-trustproxies-bez-maski-naglowkow.md).

Regresja pilnowana testem: `tests/Feature/TrustedProxyHeadersTest.php`.

---

## 8. Wykonywanie zadań kolejkowanych — brak workera na Cloud Run

**Lokalnie** kolejkę (`QUEUE_CONNECTION=database`) konsumuje osobny kontener `queue`
z `docker-compose.yml`. **Na Cloud Run nie ma procesu, który mógłby ją konsumować** — instancja
obsługuje wyłącznie żądania HTTP i jest usypiana między nimi (środowisko `staging` schodzi do zera
instancji). Bez workera zadanie trafia do tabeli `jobs` i nikt go nigdy nie wykonuje — cicha awaria,
bez błędu przy przyjęciu żądania.

Jedyny dziś realny konsument kolejki to import CSV w `CountryResource`
(`ImportAction::make()->importer(CountryImporter::class)`) — mechanizm importu Filamenta z
definicji dzieli plik na porcje i wysyła je jako zadania (`Filament\Actions\Imports\Jobs\ImportCsv`),
nigdy nie wykonuje importu w żądaniu.

**Rozstrzygnięcie: `QUEUE_CONNECTION=sync` w środowiskach Cloud Run** (`staging`, `prod`) —
[ADR-004](../adr/ADR-004-wykonywanie-kolejki-na-cloud-run.md). Import wykonuje się wtedy w tym
samym żądaniu HTTP, które go zleciło; Filament wspiera ten sterownik jako pełnoprawny przypadek
(inny sposób wysyłki powiadomienia o zakończeniu, gdy `config('queue.default') === 'sync'`), nie
jako obejście.

⚠️ **Ta zmienna żyje w konfiguracji wdrożenia (Cloud Run/Secret Manager), nie w tym repozytorium.**
`.env`/`.env.example` opisują wyłącznie środowisko lokalne, gdzie zostaje `QUEUE_CONNECTION=database`
z kontenerem `queue` — dokładnie zgodnie z „Zakresem wyłączeń" zadania 003 („nie usuwamy kontenerów
`queue`/`scheduler` z konfiguracji lokalnej"). Rozjazd między środowiskami jest tu **świadomy i
udokumentowany**, nie przeoczeniem.

### Znany limit — teoretyczny, nie praktyczny

Import w żądaniu HTTP ma twardą granicę: limit czasu żądania Cloud Run. Dla importu krajów ryzyko
jest **teoretyczne**: świat ma naturalny sufit ~195–250 uznawanych państw/terytoriów, więc plik
nigdy nie urośnie do rozmiaru zagrażającego temu limitowi.

⚠️ **Ten limit dotyczy każdego przyszłego importu, nie tylko krajów.** Jeśli powstanie funkcja
z importem bez małego, naturalnego sufitu rozmiaru (albo z zadaniem kolejkowym trwającym dłużej niż
rozsądny czas odpowiedzi HTTP) — `sync` przestaje być bezpiecznym wyborem dla **tej** funkcji.
Wzorzec na taki przypadek już istnieje w warstwie infrastruktury: Cloud Scheduler → Cloud Run Job
(`gcp-foundation`, ADR-0012), sprawdzony w działaniu w WorkSnapie. Nie trzeba go wymyślać od nowa —
tylko zastosować w momencie, gdy koszt uzasadnienia faktycznie się pojawi. Pełna analiza obu
wariantów: [ADR-004](../adr/ADR-004-wykonywanie-kolejki-na-cloud-run.md).

Regresja pilnowana testem: `tests/Feature/CountryImporterTest.php` — weryfikuje, że import
domyka się (`Import::completed_at` ustawione) i że błędne wiersze lądują w `failed_import_rows`,
pod tym samym sterownikiem kolejki (`sync`), którego pakiet testów używa dla całego przebiegu
(`phpunit.xml`).

---

## 9. Trwały storage uploadów — dysk lokalny lokalnie, GCS na Cloud Run

Dwa pola `FileUpload` w panelu administratora (`FisheryResource.map_image_path`,
`FisheryResource.gallery_images`) zapisują pliki na dysku wskazanym konfiguracją, nie na sztywno.
**Lokalnie** to dysk `public` (`storage/app/public`, symlink `public/storage`). **Na Cloud Run**
system plików kontenera jest **efemeryczny per instancja** — znika przy każdym wdrożeniu, przy
każdym scale-to-zero i zimnym starcie (`staging` chodzi z `min_instances 0`, więc dzieje się to
tego samego dnia co upload), a przy więcej niż jednej instancji plik zapisany przez instancję A
jest niewidoczny dla żądania obsłużonego przez instancję B. Awaria jest **cicha**: upload się
udaje, panel pokazuje sukces, obrazek przestaje się otwierać dopiero po restarcie.

**Rozwiązanie: bucket GCS fundamentu** (`gcp-foundation`, moduł `modules/app-storage`,
ADR-0013/ADR-0014, cross-repo). Fundament dostarcza bucket, grant `roles/storage.objectAdmin` dla
runtime SA **na tym jednym buckecie** (Application Default Credentials z metadata servera Cloud
Run — **zero kluczy JSON**) i publiczny odczyt (`allUsers` → `roles/storage.objectViewer`).
Repozytorium aplikacji dostarcza pakiet Composera (`spatie/laravel-google-cloud-storage`),
konfigurację dysku `gcs` i to, żeby oba pola `FileUpload` faktycznie za nią podążały.

Zmienne środowiskowe:

| Zmienna | Lokalnie | Cloud Run |
|---|---|---|
| `FILESYSTEM_DISK` | `public` | `gcs` |
| `FILAMENT_FILESYSTEM_DISK` | (nieustawiona, domyślnie `public`) | `gcs` |
| `GOOGLE_CLOUD_STORAGE_BUCKET` | nieużywana | output `storage_bucket` z fundamentu |

Nazwa bucketa **nie jest sekretem** — zwykła zmienna wdrożenia:
`terraform -chdir=environments/{staging,prod} output lowiska` → `storage_bucket`.

### Strażnik startowy — zamiast odwróconego fallbacku

`'default' => env('FILESYSTEM_DISK', 'local')` zostaje bez zmian — dysk lokalny jest bezpieczną
wartością domyślną dla środowiska najmniej kontrolowanego (maszyny deweloperskie, CI, pakiet
testów). Realne ryzyko cichej utraty danych adresuje **strażnik startowy**
(`AppServiceProvider::assertUploadDiskIsSafe()`), nie odwrócenie fallbacku: poza `local`/`testing`
aplikacja **odmawia startu**, jeśli dysk uploadów rozwiązuje się do sterownika `local` — pęka przy
starcie kontenera, w logach wdrożenia, zanim ktokolwiek zdąży wgrać plik.

⚠️ **Sprawdzenie dotyczy rozwiązanego sterownika, nie samej wartości zmiennej** — ten sam wzorzec
co bramka bazy danych w `tests/TestCase.php` (ADR-001). Strażnik **nie** wymaga dodatkowo
niepustego `GOOGLE_CLOUD_STORAGE_BUCKET`: pusty bucket przy sterowniku `gcs` ujawni się głośno
przy pierwszym uploadzie (błąd klienta GCS), co jest innym rodzajem awarii niż cicha utrata danych
na dysku `local`.

Regresja pilnowana testami: `tests/Unit/UploadDiskGuardTest.php` (sam warunek, bez rozruchu
aplikacji) oraz `tests/Feature/FisheryFileUploadTest.php` (oba pola `FileUpload` realnie podążają
za konfiguracją — dowód przez przełączenie dysku na `gcs` w trakcie testu, nie tylko sprawdzenie
zachowania przy domyślnym dysku deweloperskim).

---

## 10. Bramka bezpieczeństwa w CI — SCA, SAST, skan sekretów

`.github/workflows/deploy.yml` ma **dwa joby**:

```
security ──(needs)──> deploy
```

Kierunek zależności jest celowy: czerwony krok w `security` sprawia, że `deploy` **w ogóle nie
startuje** — to jest mechanizm „zatrzymania wdrożenia", nie sama kolejność kroków. Job `security`
**nie dostaje** `id-token: write` ani sekretów chmurowych (`permissions: contents: read`), bo nie
rozmawia z GCP — uruchamia się przed uwierzytelnieniem.

Ten sam komplet kontroli obowiązuje dla obu wyzwalaczy (`push` na `dev` → staging, tag `v*` →
prod). SCA/SAST/sekrety dotyczą **kodu**, nie środowiska docelowego; podatność w zależności nie
przestaje nią być na produkcji.

### Trzy warstwy

| Warstwa | Narzędzie | Co blokuje |
|---|---|---|
| SCA | `composer audit --locked` + `roave/security-advisories` | zależność o znanej podatności |
| SAST | PHPStan + Larastan, poziom 5 | **nowe** błędy analizy statycznej |
| Sekrety | gitleaks (pinowany + SHA-256) | poświadczenia w historii gita |

**SCA** działa dwutorowo: `roave/security-advisories` (w `require-dev`) to metapakiet bez kodu,
który przez wpisy `conflict` **fizycznie uniemożliwia** `composer install`/`update` z podatną
zależnością — także lokalnie, bez CI. `composer audit --locked` w CI jest drugą, blokującą
warstwą. Dependabot (natywny na GitHubie) alertuje, ale nie blokuje — uzupełnia, nie zastępuje.

## 11. Pierwsze konto administratora na środowisku wdrożonym

`php artisan MakeAdmin <imię> <nazwisko> <e-mail>` tworzy konto **i** generuje/nadaje mu komplet
uprawnień Shielda (zadanie 008) — na świeżo wdrożonym środowisku (staging albo prod) trzeba je
jednak uruchomić **ręcznie**, na przykład przez `gcloud run jobs execute` albo doraźny exec do
kontenera. **`deploy.yml` tego nie robi automatycznie** — świadomie odłożone poza zakres zadania
008, bo to decyzja operacyjna (kto i kiedy zakłada pierwsze konto), nie techniczna.

⚠️ Bez tego kroku świeżo wdrożone środowisko ma dokładnie ten sam objaw, który zadanie 008
naprawiło lokalnie: zero uprawnień w bazie, dopóki `MakeAdmin` (albo `shield:generate`) nie
zostanie uruchomione choć raz.

**SAST** stosuje strategię **„ratchet"**: `phpstan-baseline.neon` zamraża naruszenia istniejące
w chwili włączenia bramki, więc CI czerwienieje **wyłącznie na nowe**. ⚠️ Baseline **zmniejsza
się** w kolejnych zadaniach — nigdy nie regeneruj go hurtem, bo regeneracja ukrywa świeżo
wprowadzony błąd razem ze starym długiem.

**Sekrety** skanuje gitleaks po **pełnej historii** (`gitleaks git .`), nie po katalogu roboczym.
To celowe: w repozytorium liczy się to, co jest w commitach. Skan katalogu łapałby dodatkowo pliki
niewersjonowane (`.env` z prawdziwymi lokalnymi sekretami, `storage/**`), których i tak nie da się
wypchnąć — stąd `.gitleaks.toml` wyłącza je z ręcznego `gitleaks dir`.

⚠️ Wersja gitleaks jest **pinowana i weryfikowana sumą SHA-256**. `latest` byłoby niekontrolowaną
zmianą w łańcuchu dostaw i źródłem nagłych, niezwiązanych ze zmianą czerwonych buildów.

### Hook lokalny — miękka warstwa przed commitem

```bash
git config core.hooksPath .githooks   # jednorazowo, per klon
```

`.githooks/pre-commit` uruchamia `gitleaks protect --staged`. ⚠️ Hook **przepuszcza** commit, gdy
gitleaks nie jest zainstalowany lokalnie — twardą bramką jest CI, nie hook; inaczej brak narzędzia
na czyjejś maszynie blokowałby pracę. `.gitattributes` wymusza `eol=lf` dla `.githooks/**`, bo
skrypt powłoki z CRLF kończy się błędem `bad interpreter: /bin/sh^M` (host deweloperski to Windows).

### Czego bramka NIE łapie

To jest **dolna, mechaniczna warstwa** siatki — łapie to, co masowe i tanie do przeoczenia.
Nie zastępuje przeglądu merytorycznego (`/review-implementation`, skill `security-audit`):
w audycie bliźniaczego projektu narzędzia nie wykryły **żadnego** z ustaleń wysokiej istotności,
bo wszystkie były błędami logiki. Podatność z zadania 002 (zatrucie hosta przez `trustProxies`
bez maski) też nie jest niczym, co złapałby PHPStan czy gitleaks.
