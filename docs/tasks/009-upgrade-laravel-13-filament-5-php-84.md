# 009 — Upgrade do Laravel 13, Filament 5.x i PHP 8.4+

## Opis problemu

Stack projektu jest dziś zamrożony na: Laravel `^12.0` (zainstalowane v12.66.0), Filament `^3.3`
(zainstalowane v3.3.54), PHP `^8.2` (przypięte na `8.3.0` w czterech miejscach — patrz
„Ograniczenia techniczne"). W międzyczasie ukazały się kolejne wersje major: **Laravel 13**
(17.03.2026, wymaga min. PHP 8.3), **Filament 4** (przepisane Forms/Infolists na wspólny
„Schema", wymaga Tailwind v4 dla własnych motywów) i **Filament 5** (wydany krótko po 4 —
wyłącznie wsparcie Livewire v4, bez zmian w API zasobów/formularzy względem 4). Chcemy podnieść
framework, panel administracyjny i interpreter PHP do najnowszych stabilnych wersji, a przy okazji
zweryfikować i podnieść wszystkie pozostałe zależności Composera do ich najnowszych stabilnych
wersji zgodnych z nowym stackiem.

**Kontekst obniżający stawkę:** aplikacja **nie działa jeszcze produkcyjnie** (patrz `CLAUDE.md`) —
nie ma prawdziwych użytkowników ani aktywnych sesji do zachowania. Decyzje, które gdzie indziej
byłyby ostrożnościowe (np. inwalidacja sesji przy zmianie serializacji), tutaj są tanie.

**Dlaczego to nie jest jedna prosta zmiana `composer.json`:**

1. **Ścieżki upgrade'u są sekwencyjne, nie da się przeskoczyć wersji.** Laravel: 12→13 wprost
   (Laravel Boost/`/upgrade-laravel-v13` albo ręcznie wg oficjalnego przewodnika). Filament: **nie
   ma** narzędzia 3→5 wprost — trzeba przejść `filament/upgrade` dla 3→4 (skrypt automatyczny +
   ręczne poprawki), dopiero potem podnieść constraint do `^5.0` (to drugie jest w praktyce
   bezobsługowe: Filament 5 nie zmienia API względem 4, dodaje tylko wsparcie Livewire v4).
2. **Filament 3→4 ma realne, ręczne złamania API**, których nie załatwi sam skrypt
   `vendor/bin/filament-v4`: `Grid`/`Section`/`Fieldset` przestają domyślnie rozciągać się na
   całą szerokość (`->columnSpanFull()`), `columnSpan()` domyślnie celuje w `lg` zamiast tablicy
   breakpointów, stan pól enum zawsze zwraca instancję enuma (nie samą wartość), filtry tabel są
   domyślnie odroczone (`deferFilters`), `unique()` domyślnie ignoruje bieżący rekord inaczej niż
   w v3, parametry URL-i paneli zmieniają nazwy (`activeRelationManager` → `relation`,
   `tableFilters` → `filters`, `tableSort` → `sort`). Każdy z 13 zasobów w `app/Filament/Resources/`
   i strony w `app/Filament/Owner/Pages/` trzeba przejrzeć pod tym kątem.
3. **PHP 8.3 jest dziś przypięte w czterech miejscach naraz** (`Dockerfile`, `composer.json` →
   `config.platform.php`, `phpstan.neon` → `phpVersion`, `.github/workflows/deploy.yml` → krok
   `setup-php`) i `CLAUDE.md` wymaga zmiany wszystkich naraz **oraz przegenerowania**
   `phpstan-baseline.neon` — samo podniesienie jednego miejsca zostawia trzy inne w rozjeździe.
4. **Kilka zależności ma własne pułapki niezależne od Laravela/Filamenta** — patrz „Analiza ryzyka
   pakietów" niżej. Najważniejsza: `barryvdh/laravel-debugbar` **zmienił nazwę pakietu** na
   `fruitcake/laravel-debugbar` (stary pakiet to dziś już tylko przekierowanie).

## Analiza ryzyka pakietów

Stan zweryfikowany przez research w sierpniu 2026 (WebSearch/WebFetch na packagist.org,
filamentphp.com, laravel.com/docs/13.x, GitHub); dokładne wersje docelowe **zweryfikuj ponownie
w chwili implementacji** — ekosystem porusza się szybciej niż ten dokument.

| Pakiet | Dziś | Cel | Ryzyko | Uwaga |
|---|---|---|---|---|
| `laravel/framework` | `^12.0` (v12.66.0) | `^13.0` | 🟡 średnie | „Zero breaking changes" w opisie wydania **nie znaczy zero pracy** — patrz „Zmiany Laravel 13" niżej. |
| `filament/filament` (+`forms`/`tables`/`infolists`/`actions`/`notifications`/`widgets`/`support`) | `^3.3` (v3.3.54) | `^5.0` | 🔴 wysokie | Realny koszt leży w kroku 3→4 (patrz wyżej); 4→5 jest bezobsługowe. Wymaga `filament/upgrade` jako tymczasowej zależności dev. |
| `bezhansalleh/filament-shield` | `^3.3` | `^4.0` | 🟡 średnie | Wersja 4.x wspiera zarówno Filament 4, jak i 5. Zmiana architektury pakietu między 3.x a 4.x (patrz issue `bezhanSalleh/filament-shield#604` — problemy zgłaszane przy migracji) — przetestować generowanie uprawnień i politykę `RolePolicy` po migracji, nie zakładać automatycznej zgodności z zadaniem 008. |
| `bezhansalleh/filament-language-switch` | `^3.1` | `^5.0` | 🟢 niskie | Stabilne 5.0.0 (2026-06-27) wspiera `^4.0\|^5.0`. Renderowanie przepisane na konteksty (topbar/sidebar/user-menu) — większość configów v4 działa bez zmian, ale przełącznik jest widoczny w obu panelach, więc warto zerknąć wzrokowo po migracji. |
| `php` (interpreter + `config.platform.php`) | `^8.2`, platform `8.3.0` | `^8.4`, platform `8.4.x` | 🟡 średnie | Laravel 13 wymaga min. 8.3; **8.4 rekomendowane** zamiast 8.5 — 8.5 jest bardzo świeże (wydane niedawno) i część ekosystemu zgłaszała problemy tuż po jego wydaniu (m.in. deprecacja `PDO::MYSQL_ATTR_SSL_CA`). Przypięcie w czterech miejscach (patrz „Ograniczenia techniczne"). |
| `barryvdh/laravel-debugbar` | `^3.15` | — (zamiana) | 🟡 średnie | **Pakiet zmienił nazwę** na `fruitcake/laravel-debugbar` (v4.4.1, wspiera Laravel `^11\|^12\|^13`, PHP `^8.2`). `barryvdh/laravel-debugbar` istnieje dziś wyłącznie jako przekierowanie — `composer.json` ma się zmienić na nową nazwę pakietu, nie tylko podnieść wersję. |
| `laravel/breeze` | `^2.3` | `^2.4` (v2.4.2) | 🟡 średnie | Wspiera `^11\|^12\|^13` i dostał w 2.4.2 poprawkę specyficzną dla Laravel 13 (usunięcie importu `bootstrap.js`). **Pakiet jest w praktyce w trybie utrzymaniowym** — strona projektu kieruje do nowych starter-kitów Laravel zamiast dalszego rozwoju Breeze. Ryzyko niskie technicznie, ale warto to świadomie odnotować: przyszła migracja auth poza Breeze to osobny temat. |
| `laravel/tinker` | `^2.10.1` | `^3.0` | 🟡 średnie | Oficjalny przewodnik Laravel 13 wymienia to wprost jako zależność do podniesienia razem z frameworkiem. |
| `pestphp/pest` (+`pest-plugin-laravel`, `pest-plugin-arch`, `pest-plugin-mutate`) | `^3.8` | `^4.0` | 🟡 średnie | Pest 4 wymaga **PHP 8.3+** i biegnie na PHPUnit 12 (podnieś `phpunit/phpunit` razem, jeśli jest wymieniony bezpośrednio). Wtyczki `pest-plugin-watch` i `pest-plugin-faker` są **zarchiwizowane** — nie dotyczy tego projektu (nie są używane), ale nie próbuj ich podnosić. Testy ze snapshotami nie występują w tym projekcie — pominąć krok `--update-snapshots` z oficjalnego przewodnika. |
| `larastan/larastan` | `^3.10` | najnowsza `^3.x` | 🟢 niskie | Larastan 3.x już wspiera Laravel 13 i PHP 8.3+; sam upgrade to głównie przegenerowanie `phpstan-baseline.neon` pod nowy `phpVersion`. |
| `gusapi/gusapi` | `^6.3` | najnowsza `^6.x` (≥6.3.2, zweryfikuj wyżej) | 🟢 niskie | 6.3.x ma już poprawkę pod PHP 8.4 (`stream_context_set_option` → `stream_context_set_options`). Zależy od `ext-soap` — upewnić się, że obraz Dockera na nowym PHP nadal instaluje `soap` (już jest w liście `install-php-extensions`). |
| `laravel/socialite` | `^5.22` | najnowsza `^5.x` | 🟢 niskie | Dokumentacja Laravela istnieje już dla 13.x, brak sygnałów o niezgodności. |
| `spatie/laravel-activitylog`, `spatie/laravel-flare`, `spatie/laravel-google-cloud-storage` | `^4.10`/`^3.0`/`^2.4` | najnowsze zgodne | 🟢 niskie | Spatie utrzymuje szerokie widełki Laravela; `laravel-flare` deklaruje wsparcie od Laravel 11 wzwyż. Bez sygnałów o problemach z Laravel 13 — zweryfikować przy `composer update`, nie tylko na słowo. |
| `spatie/laravel-permission` (zależność `filament-shield`) | pociągana pośrednio | najnowsza zgodna z Shieldem 4.x | 🟢 niskie | Nie jest bezpośrednią zależnością `composer.json`, ale wersja podniesie się razem z `filament-shield`. |
| `roave/security-advisories` | `dev-latest` | bez zmian | ⚪ brak ryzyka | Metapakiet bez kodu — zawsze wskazuje najnowszy stan advisory. |
| Reszta `require-dev` (`laravel/pint`, `laravel/pail`, `laravel/sail`, `mockery/mockery`, `nunomaduro/collision`, `fakerphp/faker`) | różne | najnowsze stabilne | 🟢 niskie | Standardowe podniesienie w ramach `composer update`; brak sygnałów o niezgodności z Laravel 13 w żadnym z research'owanych źródeł. |

### Zmiany Laravel 13 wymagające przeglądu kodu (z oficjalnego przewodnika)

- **CSRF middleware przemianowany**: `VerifyCsrfToken` → `PreventRequestForgery` (stary alias
  zostaje jako deprecated). Sprawdzić `bootstrap/app.php` i wszystkie miejsca odwołujące się do
  starej nazwy klasy wprost (np. w testach wykluczających middleware).
- **`cache.serializable_classes`**: nowy klucz konfiguracji, domyślnie `false` — jeśli
  aplikacja nie przechowuje obiektów PHP w cache (do zweryfikowania), nic nie trzeba dopisywać.
- **`session.serialization`**: nowy szkielet aplikacji ustawia `json` zamiast `php` (twardnienie
  przeciw atakom deserializacji). Zmiana z `php` na `json` unieważnia aktywne sesje — **tutaj
  bez znaczenia**, bo aplikacja nie ma jeszcze prawdziwych użytkowników. Rekomendacja: przyjąć
  `json` od razu, nie zostawiać `php` „dla bezpieczeństwa sesji", którego i tak nie ma kogo chronić.
- **`Illuminate\Queue\Events\JobAttempted`**: `$exceptionOccurred` (bool) → `$exception`
  (obiekt/`null`) — sprawdzić, czy coś w projekcie nasłuchuje tego eventu (raczej nie, do
  potwierdzenia).
- **`Illuminate\Queue\Events\QueueBusy`**: `$connection` → `$connectionName`.
- Pozostałe zmiany z przewodnika (paginacja Bootstrap 3, polimorficzne tabele pivot, drobne
  kontrakty) — przejrzeć listę „Low/Very Low Impact" na `laravel.com/docs/13.x/upgrade`, ale
  **nie kopiować jej tutaj w całości**: przewodnik jest źródłem prawdy, ten plik ma tylko
  wskazać, że trzeba go przeczytać, nie go duplikować.

### Filament: brak własnego motywu upraszcza sprawę

Zweryfikowane w kodzie: projekt **nie publikuje** własnego motywu Filamenta
(`php artisan make:filament-theme` nigdy nie było uruchomione — brak `resources/css/filament/**`).
Jedyna customizacja to `public/css/filament-extend.css`, zarejestrowany jako **surowy** zasób CSS
przez `FilamentAsset::register([Css::make(...)])` w `AppServiceProvider::boot()` — dwie reguły
celujące w gotowe klasy Filamenta (`.fi-ac.grid`, `.fi-btn-icon svg`), bez żadnej dyrektywy
Tailwind. **Wymóg „Tailwind v4 dla własnych motywów" z przewodnika Filamenta 4 tego projektu więc
nie dotyczy** — nie trzeba tworzyć motywu ani migrować `filament-extend.css` na `@source`/`@theme`.
Sam plik przechodzi bez zmian.

⚠️ Osobna, niepowiązana obserwacja z tego samego researchu: `package.json` ma już dziś
`@tailwindcss/vite: ^4.0.0` w `devDependencies`, obok właściwego `tailwindcss: ^3.1.0` — a
`vite.config.js` w ogóle nie używa wtyczki `@tailwindcss/vite`. To martwa zależność, niezwiązana
z Filamentem (front-end publiczny/Breeze używa klasycznego pipeline'u `@tailwind base/components/
utilities` + `tailwind.config.js`). Poza zakresem tego zadania — patrz „Zakres wyłączeń"
i „Rozstrzygnięcia" (ustalone przy `/review-task`).

## Wymagania

- **Kolejność wykonania** (nie da się przeskoczyć etapów):
  1. Laravel `^12.0` → `^13.0` (najpierw framework, zanim ruszy się Filament — Filament 4/5
     wymaga min. Laravel 11.28, więc kolejność Laravel→Filament jest bezpieczna; odwrotna
     kolejność ryzykowałaby chwilowy stan niezgodny).
  2. `laravel/tinker` → `^3.0`, `pestphp/pest` (+wtyczki) → `^4.0`, `phpunit/phpunit` → `^12.0`
     (jeśli wymieniony wprost — dziś ciągniony pośrednio przez Pest).
  3. Filament 3→4: `composer require filament/upgrade:"^4.0" -W --dev`, `vendor/bin/filament-v4`,
     potem `composer require filament/filament:"^4.0" -W --no-update && composer update`.
     Ręczny przegląd 13 zasobów (`app/Filament/Resources/**`) i stron panelu właściciela
     (`app/Filament/Owner/Pages/**`) pod kątem złamań API wypisanych w „Analizie ryzyka".
  4. Filament 4→5: podniesienie constraintu na `^5.0`, uruchomienie automatycznego skryptu
     upgrade'u (opisanego jako bezobsługowy w oficjalnym przewodniku) — bez oczekiwanych zmian
     w kodzie zasobów.
  5. `bezhansalleh/filament-shield` → `^4.0`, `bezhansalleh/filament-language-switch` → `^5.0`.
  6. `barryvdh/laravel-debugbar` → zamiana na `fruitcake/laravel-debugbar` w `composer.json`
     (nie tylko podniesienie wersji istniejącego wpisu).
  7. PHP `^8.2` → `^8.4`, `config.platform.php` → wersja `8.4.x` — **w czterech miejscach naraz**
     (patrz „Ograniczenia techniczne").
  8. Przegenerować `phpstan-baseline.neon` pod nowy `phpVersion` (wymóg z `phpstan.neon`,
     komentarz przy tej linii).
  9. `composer update` na resztę zależności do najnowszych stabilnych w ramach nowych
     constraintów; `composer audit --locked` i `composer outdated --direct` jako dowód, że nic
     nie zostało w tyle bez powodu.
  10. Docker: `php:8.3-cli-bookworm` → `php:8.4-cli-bookworm`, `dunglas/frankenphp:1-php8.3` →
      `dunglas/frankenphp:1-php8.4` (oba w `Dockerfile`); krok `shivammathur/setup-php` w
      `deploy.yml` → `php-version: '8.4'`.
- **Każdy krok zweryfikowany uruchomieniem pełnego pakietu testów** przed przejściem do
  następnego — nie łączyć wszystkich zmian w jeden commit „na końcu", żeby błąd dało się
  przypiąć do konkretnego kroku.
- **`session.serialization`** ustawione na `json` (nowy domyślny szkielet Laravel 13) —
  bezpieczne w tym projekcie, bo brak aktywnych sesji produkcyjnych do zachowania.
- **Wszystkie odwołania do `VerifyCsrfToken`** w kodzie projektu (jeśli jakieś istnieją)
  zaktualizowane na `PreventRequestForgery`.
- **Zero regresji w zachowaniu paneli** — oba (`/admin`, `/owner`) mają wyglądać i działać jak
  dziś: te same zasoby widoczne, te same uprawnienia, te same akcje na rekordach.

## Kryteria akceptacji

- [x] `composer.json` wskazuje `laravel/framework: ^13.0` (v13.25.0), `filament/filament: ^5.0`
      (v5.7.6), `php: ^8.4`, `config.platform.php` zgodny z realnym interpreterem — **`8.4.24`,
      zweryfikowane w OBU obrazach** (dev `php:8.4-cli-bookworm` i prod
      `dunglas/frankenphp:1-php8.4` dają tę samą łatkę). Patrz „Wyniki weryfikacji", pkt 7.
- [x] `barryvdh/laravel-debugbar` zastąpiony `fruitcake/laravel-debugbar` (v4.4.1).
- [x] `composer audit --locked` — brak podatności.
- [x] `vendor/bin/phpstan analyse` — `[OK] No errors` na przegenerowanym baseline, poziom 5.
- [x] Oba panele renderują się poprawnie — `AdminPanelTest` i `OwnerPanelTest` zielone (2 + 18
      testów). ⚠️ Dodatkowo zweryfikowane sondą, że panel właściciela pokazuje **wyłącznie**
      `Dashboard`, `Companies`, `Fisheries` (bez `Countries`/`Users`), czyli że migracja nie
      rozszczelniła uprawnień.
- [x] `bezhansalleh/filament-shield` po migracji generuje uprawnienia poprawnie —
      `MakeAdminCommandTest` zielone. ⚠️ **Wymagało zmian**: `--minimal` → `--silent`
      w `MakeAdminCommand` (flaga zniknęła w Shieldzie 4). Patrz „Wyniki weryfikacji", pkt 3.
- [x] PHP 8.4 przypięte w **czterech** miejscach: `Dockerfile` (oba stage'e),
      `composer.json`, `phpstan.neon` (`80400`), `deploy.yml` (`8.4`).
- [x] `phpstan-baseline.neon` przegenerowany pod PHP 8.4: **36 wpisów / 47 błędów — dokładnie
      tyle samo co przed zmianą.** Surowy przebieg dawał przejściowo 48; nadwyżką był **realny
      błąd** (`Register::makeForm()`), naprawiony, nie zamrożony. Patrz „Wyniki weryfikacji", pkt 5.
- [x] `docker build --target prod` przechodzi; obraz startuje na PHP 8.4.24 z kompletem
      rozszerzeń (`soap`, `intl`, `opcache`, …).
- [x] Zakres testów (T3, pełny pakiet) zielony: **70 testów, 243 asercje**.
- [x] `vendor/bin/pint` — czysto (196 plików).

## Zakres testów

- **Tier:** T3 — pełny pakiet
- **Uruchamiamy:**
  ```bash
  docker compose exec app php artisan test
  ```
- **Uzasadnienie:** T3 nie jest tu przedmiotem wyboru — zadanie trafia jednocześnie w
  **`composer.json`**, **`Dockerfile`**, **`docker-compose.yml`** (obraz bazowy PHP) i pośrednio
  w providery paneli (zmiany API Filamenta 3→4 w zasobach). Dowolny pojedynczy filtr testowy
  nie wykryłby regresji, która ujawnia się dopiero przy realnym boocie całej aplikacji na nowym
  stacku.

## Zakres wyłączeń

- **Migracja publicznego front-endu (Breeze) z Tailwind v3 na v4** — Filament 5 tego nie wymaga
  (projekt nie ma własnego motywu Filamenta, patrz analiza wyżej), a to osobna migracja o innym
  charakterze (JS config → CSS-native config, dyrektywy `@tailwind` → `@import`). Zostaje jako
  temat na osobne zadanie.
- **Sprzątnięcie martwej zależności `@tailwindcss/vite` z `package.json`** — niezwiązane
  z tym zadaniem (dotyczy npm, nie composera), odnotowane jako obserwacja w analizie ryzyka.
  Ustalone przy `/review-task`, patrz „Rozstrzygnięcia".
- **`php artisan filament:upgrade-directory-structure-to-v4`** — opcjonalna reorganizacja
  katalogów zasobów/klastrów. Nie zmienia zachowania, tylko układ plików; pominięte, żeby nie
  mieszać zmiany zachowania (upgrade wersji) ze zmianą struktury katalogów w jednym zadaniu.
- **Migracja auth poza `laravel/breeze`** na nowy oficjalny starter-kit Laravela — Breeze nadal
  wspiera Laravel 13 technicznie (patrz analiza ryzyka); to osobna decyzja produktowa, nie
  wymuszona przez ten upgrade.
- **Zmiana strategii CI poza podniesieniem wersji PHP** — bramka z zadania 007 (SCA/SAST/sekrety)
  zostaje strukturalnie nietknięta, zmienia się wyłącznie wersja PHP w kroku `setup-php`.

## Zmiany dokumentacji

- [x] `CLAUDE.md` — tabela czterech miejsc zaktualizowana na `8.4`; opis stacku na „Laravel 13
      / Filament 5 / Pest 5". **Dopisano niezmiennik** o pełnej wersji z łatką
      w `config.platform.php` wraz z komendami weryfikującymi oba obrazy (patrz „Wyniki
      weryfikacji", pkt 7) — to reguła obowiązująca niezależnie od tematu zadania, więc jej
      miejsce jest w `CLAUDE.md`, nie w konwencjach.
- [x] `docs/operations/obraz-produkcyjny.md` — `base` opisany jako PHP 8.4. Research nie ujawnił
      niczego specyficznego dla Filamenta 5/Livewire na Cloud Run, więc nowa podsekcja nie
      powstała.
- [x] `docs/conventions/panel-admina.md` — nowa sekcja „2. Formularze na Filamencie 5 (`Schema`)":
      wzorzec `form(Schema $schema)`, `recordActions`/`toolbarActions`, pułapka nazwy komponentu
      równej czytanemu kluczowi stanu oraz pułapka `getForms()`/`makeForm()`.
- [x] `docs/conventions/autoryzacja.md` — nowe sekcje 2 i 3: format kluczy uprawnień jako jedno
      źródło prawdy + `policies.generate => false`. Regułę o `--minimal` **przepisano** na
      `--silent`, nie dopisano obok.
- [x] ~~`docs/conventions/panel-wlasciciela.md`~~ — **plik nadal nie istnieje** (powierzchnia
      oznaczona ⛏️ w `CLAUDE.md`). Zadanie nie ustaliło niezmiennika wyłącznego dla
      `app/Filament/Owner/Pages/**`: pułapki z migracji dotyczą zasobów współdzielonych przez
      oba panele, więc trafiły do `panel-admina.md`. **Nie zakładam pustego pliku na zapas.**
- [x] `README.md` — nagłówek zaktualizowany na „Laravel 13 … Filament 5".
- [x] `CHANGELOG.md` — wpis w „Zmienione" (nowsze wydanie frameworka/panelu/PHP, bez zmiany
      dla użytkownika) oraz dwa w „Poprawione" (zniknięte pola rejestracji, wywracająca się
      strona dodawania firmy) — obie awarie były realne i user-facing.

## Ograniczenia techniczne

- Laravel 13 wymaga **min. PHP 8.3**; ten projekt celuje w **8.4** (patrz „Rozstrzygnięcia" —
  8.5 świadomie odrzucone jako zbyt świeże).
- **PHP jest przypięte w czterech miejscach i zmienia się WYŁĄCZNIE razem** (zasada z `CLAUDE.md`,
  sekcja „Bezpieczeństwo w CI"): `Dockerfile` (`php:8.X-cli-bookworm` w stage `base`,
  `dunglas/frankenphp:1-phpX` w stage `prod`), `composer.json` (`config.platform.php`),
  `phpstan.neon` (`phpVersion`), `.github/workflows/deploy.yml` (krok `setup-php`).
  Podniesienie wersji **wymaga też przegenerowania** `phpstan-baseline.neon` — Composer
  rozwiązuje zależności względem wersji interpretera, na którym akurat działa, nie względem
  `require.php`, więc rozjazd w tym miejscu potrafi wpuścić do locka pakiety niewdrażalne
  w kontenerze (dokładnie ten scenariusz uderzył już bliźniaczy projekt, patrz `CLAUDE.md`).
- Ścieżka upgrade'u Laravela jest **sekwencyjna** — nie da się przeskoczyć z 12 na 13 pomijając
  kroki pośrednie, gdyby projekt kiedyś zaległ (dziś jest już na 12, więc krok jest pojedynczy).
- Filament **nie ma** narzędzia migrującego wprost z 3.x do 5.x — wymaga przejścia przez pakiet
  `filament/upgrade` (3→4), potem podniesienia constraintu (4→5).
  `filament/upgrade` jest zależnością **tymczasową**: usunąć z `require-dev` po zakończeniu
  migracji, nie zostawiać na stałe.
- Testy uruchamiane **wyłącznie** przez `docker compose exec app php artisan test` — pakiet biegnie
  na MySQL-u w schemacie `lowiska_test`, chronionym pięcioma warstwami izolacji (`CLAUDE.md`,
  ADR-001).
- Panele są dwa (`admin`, `owner`) — każdy trzeba zweryfikować osobno po migracji Filamenta,
  zasoby panelu właściciela to podzbiór zasobów panelu administratora (patrz zadanie 008,
  „Rozstrzygnięcia"), ale strona `VerifyCompany` jest unikalna dla panelu właściciela i wymaga
  własnej weryfikacji.

## Wyniki weryfikacji (implementacja, 2026-08-15)

Stan końcowy: **Laravel 13.25.0 · Filament 5.7.6 · Shield 4.3.1 · language-switch 5.0.0 ·
Pest 5.1.1 · PHP 8.4.24**. Pakiet testów **70 zielonych (243 asercje)**, PHPStan `[OK] No errors`,
`composer audit --locked` czysty, obraz `prod` buduje się i startuje.

### 1. Oficjalny codemod Filamenta 3→4 jest zepsuty (obejście udowodnione jako bezstratne)

`vendor/bin/filament-v4` (z `filament/upgrade` v4.12.6) przerywał się w ~55% z
`ProcessPool: Process "…" not found`, a po wymuszeniu trybu jednowątkowego pokazał prawdziwą
przyczynę: jego konfiguracja rectora rejestruje `AddInterfaceByTraitRector`, którą
`rector/rector` 2.6.2 **twardo wycofał** (rzuca wyjątkiem zamiast ostrzegać). Pakiet deklaruje
`rector/rector: ^2.0`, więc sam ściąga wersję, z którą nie działa.

Obejście: kopia konfiguracji bez tej jednej reguły. **Bezstratność udowodniona, nie założona** —
reguła dokłada interfejs `HasActions` klasom używającym `InteractsWithForms`/`InteractsWithInfolists`/
`InteractsWithTable`, a `grep` po `app/` zwraca **zero** takich klas (cały kod Filamenta dziedziczy
po klasach bazowych zamiast składać traity). Po obejściu codemod przeszedł 109/109 plików
i zmienił 50. Krok 4→5 (`filament/upgrade` 5.7.6) nie zmienił **ani jednego** pliku — potwierdza
to dokumentację, że v4→v5 nie rusza API aplikacji.

### 2. Kroków 1 i 2 z „Wymagań" nie da się rozdzielić

`pestphp/pest-plugin-laravel` v3.2.0 twardo wymaga `laravel/framework ^11.39.1|^12.9.2`, więc
Laravel 13 i Pest muszą wejść w **jednej** rezolucji. Plan zakładał osobne kroki — to było
błędne założenie, nie błąd wykonania.

⚠️ Przy okazji: `roave/security-advisories` zablokował Laravela 13 **poniżej 13.12** (trzy
advisory: `PKSA-m5cs-t1y6-qpcs` — podszywanie się pod podpisane URL-e, oraz dwa CRLF-injection
w regule walidacji e-maila, m.in. CVE-2026-48019). Zainstalowana 13.25.0 jest powyżej progu.
To zadziałało dokładnie tak, jak miało — bramka z zadania 007 nie wpuściła podatnej wersji.

### 3. Shield 3→4 to przepisanie konfiguracji i **zmiana formatu nazw uprawnień**

Największa niespodzianka zadania; „Analiza ryzyka" wyceniła to na 🟡, realnie było 🔴.

- Cały `config/filament-shield.php` ma nowy schemat (`auth_provider_model` z tablicy na string,
  `permission_prefixes` → `permissions`, `entities` → `shield_resource.tabs`, `generator.option`
  → `policies.generate`/`permissions.generate`). Bez migracji aplikacja **nie wstaje**.
- `super_admin.name` przetrwało, więc zadanie 008 nie wymagało zmian koncepcyjnych — ale
  `shield:generate` stracił `--minimal` (jest `--silent`), co wywracało `MakeAdminCommand`.
- **Format kluczy uprawnień zmienił się nieodwracalnie**: `view_any_fishery::type` →
  `view_any:fishery_type`. Zapisu z 3.x **nie da się odtworzyć konfiguracją** (separator `_` jest
  zabroniony przy case'ach snake). Wymusiło to mechaniczną migrację **233 literałów w 17 plikach**
  (14 polityk + `Helper::addOwnerRole()` + `tests/TestCase.php` + `MakeAdminCommandTest`).

⚠️ **Ta zmiana jest niewidoczna dla zwykłego pakietu testów** — `tests/TestCase.php` zakłada
uprawnienia ręcznie, z listy literałów, więc panele świeciłyby na zielono nawet przy politykach
pytających o nieistniejące nazwy (czyli przy odebranym dostępie do wszystkiego). Dlatego powstał
`tests/Feature/ShieldPermissionNamesTest.php`, który porównuje literały z `app/Policies/**`
z **faktycznym** wynikiem `shield:generate`. Test zweryfikowano negatywnie: podmiana jednego
literału na `view_any:fishz` czerwieni go z nazwą pliku.

### 4. Rekursja bez dna w formularzu firmy (ponad 6 GB, ginący proces)

`OwnerPanelTest` wywracał się `Segmentation fault`. Po wyłączeniu pcov okazało się, że to nie
segfault, tylko **wyczerpanie pamięci** — i to nie „duże zużycie", bo limit 6 GB też padał.

Przyczyna: `Placeholder::make('error')` w `CompanyResource`, którego `->content()` czytał
`$get('error')` — czyli **własny** klucz stanu. Do Filamenta 3 nieszkodliwe; od 4/5 (wspólny
`Schema`) komponent odpytuje sam siebie w nieskończoność. Zmiana nazwy na `cso_error_message`
sprowadziła stronę z ponad 6 GB (OOM) do **26 MB i statusu 200**. Reguła trafiła do
`docs/conventions/panel-admina.md`.

### 5. Cicho zniknięte pola rejestracji — wyłapane przez PHPStan, nie przez testy

Baseline urósł z 47 do 48 błędów. Nadwyżka to `Register::makeForm()` — metoda nie istnieje
w Filamencie 5. Skutek **nie był** wyjątkiem: nadpisany `getForms()` przestał być wołany,
`/admin/register` dalej zwracało **200**, ale renderowało wyłącznie domyślne pola rodzica —
z formularza zniknęły `surname`, `country_id` i `phone`.

Naprawione przez nadpisanie `form(Schema $schema)`. Po naprawie surowy przebieg wraca do **47
błędów**, więc baseline ma dokładnie tyle wpisów co przed zadaniem — **nowy dług nie został
zamrożony**. Powstał `tests/Feature/PanelRegistrationFormTest.php`, bo tej klasy awarii nie
widzi żaden test sprawdzający kod odpowiedzi.

### 6. Locale testów: `Accept-Language` klienta testowego bije `phpunit.xml`

`filament-language-switch` 5.x wprowadził middleware `SwitchLanguageLocale`, który wybiera locale
m.in. z nagłówka `Accept-Language`. Klient testowy Symfony wysyła domyślnie `en-us,en;q=0.5`,
więc panele renderowały się **po angielsku** wbrew `APP_LOCALE=pl` wymuszonemu w `phpunit.xml`
(zadanie 006). W angielskim `assertDontSee(__('Fish'))` pęka, bo „Fish" jest podciągiem
„Fisheries" (po polsku „Ryby" i „Łowiska" nie kolidują).

⚠️ **To nie jest regres aplikacji** — honorowanie `Accept-Language` jest pożądane w aplikacji
dwujęzycznej i w przeglądarce działa poprawnie. Brakowało wyłącznie tego, żeby pakiet testów
zadeklarował swój język; `tests/TestCase::setUp()` ustawia teraz `Accept-Language: pl`,
przywracając niezmiennik zadania 006.

### 7. `config.platform.php` był zaniżony i cicho blokował ekosystem

Pierwotne `8.4.0` (analogicznie do wcześniejszego `8.3.0`) blokowało `symfony/*` 8.1, które wymaga
PHP ≥ 8.4.1 — a przez to `pestphp/pest` 4.7.8, `nunomaduro/collision` 8.9.5, `phpunit` 12.5.33
i całego Pesta 5. `composer outdated` pokazywał je jako „dostępne", nie tłumacząc dlaczego nie
wchodzą.

Pin podniesiony do **`8.4.24`** — wartości zweryfikowanej w **obu** obrazach (dev i prod dają tę
samą łatkę), zgodnie z kryterium „zgodny z realnym interpreterem". Kierunek błędu ma znaczenie:
pin **zaniżony** tylko blokuje, pin **zawyżony** wpuszcza do locka pakiety niewdrażalne
w kontenerze. Niezmiennik i komendy weryfikujące dopisane do `CLAUDE.md`.

### 8. Czego świadomie NIE podniesiono

- **`spatie/laravel-activitylog` zostaje na 4.12.3** (dostępne 5.1.0). Po samej podmianie wersji
  aplikacja nie wstaje: `Trait "Spatie\Activitylog\Traits\LogsActivity" not found`, a trait jest
  używany w **trzynastu** modelach. To migracja o własnym ryzyku (dotyczy dziennika zmian, czyli
  danych audytowych), zasługująca na osobne zadanie, nie doklejenie do upgrade'u frameworka.
  Zweryfikowane empirycznie, nie odpuszczone na podstawie changeloga.
  ⚠️ **Sprostowanie (zadanie 010):** powyższy komunikat błędu odczytano wtedy jako „v5 usuwa
  trait". Lektura oficjalnego `UPGRADING.md` przy zakładaniu zadania 010 pokazała, że trait
  został **przeniesiony** (`Activitylog\Traits\` → `Activitylog\Models\Concerns\`), a nie usunięty.
  Decyzja o odroczeniu zostaje w mocy — zakres i tak obejmuje migrację schematu oraz danych
  dziennika i przepisanie `config/activitylog.php` — ale sam powód był opisany zbyt ostro.
- **`laravel/breeze` zostaje na 2.4.2** — najnowsze wydanie; pakiet jest w trybie utrzymaniowym,
  co odnotowano już w „Analizie ryzyka".

### 9. Zmiany w testach — zgłaszane wprost

Podczas upgrade'u zmiana testu jest podejrzana z definicji, więc obie odnotowuję:

- Usunięto `assertSee(__('Panel'))` z `AdminPanelTest` i `OwnerPanelTest` (3 wystąpienia).
  Nie istnieje klucz tłumaczenia `Panel`, więc asercja sprawdzała gołe słowo „Panel", które
  Filament 3 wypisywał w swoim znaczniku, a Filament 5 już nie — szczegół implementacyjny
  biblioteki, nie zachowanie aplikacji. Sondą potwierdzono, że **wszystkie dziewięć realnych
  etykiet nawigacji** nadal się renderuje; asercja negatywna w drugim teście celuje teraz
  w prawdziwą etykietę (`Companies`), więc jest **mocniejsza** niż była.
- Pakiet urósł z 68 do 70 testów: dwa nowe testy pilnują awarii, które przy tym upgradzie były
  ciche (nazwy uprawnień, pola formularza rejestracji).

## Rozstrzygnięcia

- **Cel: Filament 5.x, nie 4.x.** Ustalone przy `/create-task` (`AskUserQuestion`). Skoro i tak
  trzeba przejść przez ciężką migrację 3→4, doskoczenie do 5 jest dodatkowo tylko trywialnym
  krokiem (wyłącznie wsparcie Livewire v4, bez zmian API) — jedno zadanie zamiast dwóch
  rozdzielonych w czasie.
- **Docelowe PHP: 8.4, nie 8.5.** Ustalone przy `/create-task`. Laravel 13 wspiera oba, ale PHP
  8.5 jest bardzo świeżo wydany i część ekosystemu (w tym pakiety trzecie) zgłaszała problemy
  tuż po premierze; 8.4 ma pełne, sprawdzone wsparcie Laravela 13, Filamenta, Larastana
  i `gusapi/gusapi`. Podniesienie do 8.5 zostaje kandydatem na osobne, przyszłe zadanie, gdy
  ekosystem dojrzeje.
- **Narzędzia deweloperskie (`laravel/breeze`, `pestphp/pest` i wtyczki) w zakresie tego
  zadania, nie osobnego.** Ustalone przy `/create-task`. Leżą w `require-dev`, więc ryzyko dla
  produkcji jest niskie, a rozdzielenie na osobne zadanie tylko powielałoby ten sam przebieg T3
  dwa razy zamiast raz.
- **`session.serialization` → `json` od razu**, nie pozostawione na `php`. Uzasadnienie: nowy
  domyślny szkielet Laravela 13 tak zaleca ze względów bezpieczeństwa (twardnienie przeciw
  atakom deserializacji), a koszt („unieważnienie aktywnych sesji") jest tu zerowy — aplikacja
  nie działa jeszcze produkcyjnie.
- **`@tailwindcss/vite: ^4.0.0` w `package.json` zostaje poza zakresem tego zadania.** Ustalone
  przy `/review-task` (`AskUserQuestion`). To npm, nie composer, nieużywane przez
  `vite.config.js` i niezwiązane z Filamentem (projekt nie ma własnego motywu) — sprzątnięcie
  jest bezpieczne, ale miesza dwie niepowiązane osie zmian w jednym zadaniu i jednym przebiegu
  T3. Patrz „Zakres wyłączeń".
- **Jedno zadanie (009), nie rozbicie na serię mniejszych.** Ustalone przy `/review-task`.
  Dziesięć kroków jest mocno zależnych sekwencyjnie (Filamenta 4 nie da się wdrożyć bez
  Laravela 13, PHP 8.4 nie wymaga tego samego zestawu testów co reszta) — rozbicie na osobne
  zadania per etap powielałoby ten sam pełny pakiet T3 wielokrotnie bez realnej izolacji
  ryzyka; commity pośrodku (po każdym kroku z „Wymagań") dają wystarczającą granularność bez
  narzutu osobnych plików zadań.

## Powiązane ADR-y

<!-- Numery ADR-ów podjętych dla tego zadania (uzupełnia /review-task).
     Tylko decyzje spełniające trzyskładnikowe kryterium z CLAUDE.md —
     reszta idzie do „Rozstrzygnięcia" powyżej. -->
- **Brak.** Rozważone przy `/review-task` i odrzucone jako kandydaci na ADR:
  - **Sekwencjonowanie/rozbicie zadania** — nie spełnia kryterium (1) „zasięg poza zadaniem":
    to decyzja o metodologii wykonania tego jednego zadania (kolejność commitów), nie coś, co
    wiąże kod poza `MakeAdminCommand`-owym odpowiednikiem dla tego zadania — żaden przyszły plik
    nie musi „wiedzieć", że wybrano jeden PR zamiast serii.
  - **Cel Filament 4.x vs 5.x** — rozstrzygnięte już w treści zadania (patrz wyżej), ale
    sprawdzone tu pod kątem ADR i odrzucone: nie spełnia kryterium (2) „wysoki koszt
    odwrócenia" — krok 4→5 jest oficjalnie bezobsługowy (bez zmian API), więc cofnięcie się
    z 5 do 4 jest równie tanie jak jego wykonanie. Projekt nie ma też własnego kodu Livewire
    (zweryfikowane: brak `app/Livewire/**`, brak klas rozszerzających `Livewire\Component`),
    więc dodatkowe ryzyko „Filament 5 wymaga Livewire 4" nie ma czego uderzyć w tym kodzie.

## Otwarte pytania

- **Czy migracja 3→4 zasobów Filamenta ujawni nowy niezmiennik do zapisania w
  `docs/conventions/panel-admina.md` / `panel-wlasciciela.md`** (np. sposób pisania `Schema`
  zamiast osobnych `Form`/`Infolist`) — nie da się rozstrzygnąć przed implementacją, bo zależy od
  tego, co konkretnie zmieni się w 13 zasobach. **Celowo pozostawione otwarte** — zostawione do
  `/implement-task`, Krok 4, nie do rozstrzygnięcia na zgadywanie.
