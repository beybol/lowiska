# 007 — Bramka bezpieczeństwa w CI: SAST, SCA i skan sekretów

## Opis problemu

`.github/workflows/deploy.yml` (jedyny workflow w repozytorium, commit „Pierwszy deploy") ma dziś
**jeden job `deploy`**, który buduje obraz (`docker build --target prod`) i wdraża na Cloud Run —
bez sprawdzenia zależności pod kątem znanych CVE, bez analizy statycznej i bez skanu sekretów.
Projekt ma warstwę przeglądu (`/review-implementation` z sekcją security, skill `security-audit`),
ale **żadnego automatycznego zabezpieczenia w CI** — każda z trzech klas problemów niżej jest
wychwytywana wyłącznie wtedy, gdy ktoś świadomie uruchomi przegląd. To zależy od dyscypliny,
nie od mechanizmu:

1. **Zależności.** Advisory publikują się niezależnie od commitów — `composer.lock` stoi
   w miejscu, a podatność pojawia się sama. Nic nie blokuje wdrożenia z podatną zależnością.
2. **Analiza statyczna.** Projekt nie ma PHPStana. Błędy typu „metoda deklaruje typ zwrotny,
   ale nic nie zwraca" przechodzą do wdrożenia, o ile nie trafi w nie test.
3. **Sekrety.** Między `git commit` a wypchnięciem klucza do repozytorium nie stoi nic.

Wzorzec ma zostać przeniesiony z bliźniaczego PunktSzczepień (zadanie `00054`, zaimplementowane
i zweryfikowane 2026-08-14), gdzie ten sam zestaw wdrożono i **zmierzono**. Trzy wyniki stamtąd
uzasadniają zakres tego zadania:

- Bramka `composer audit` w projekcie źródłowym ujawniła 57 advisory w 18 pakietach, nazbieranych
  bez jednej zmiany w `composer.lock`.
- Pierwsze uruchomienie PHPStana (poziom 5) wykryło realny błąd w kodzie — coś, co test nie
  wyłapał.
- Skan sekretów przechodził na zielono, zanim ktokolwiek sprawdził, czy w ogóle ma załadowane
  reguły — dopiero test negatywny to potwierdził.

Zadanie ma zbudować **dolną, mechaniczną warstwę** siatki. Nie zastępuje przeglądu merytorycznego:
w projekcie źródłowym narzędzia nie wykryły żadnego z ustaleń wysokiej istotności z audytu, bo
wszystkie były błędami logiki. Bramka ma łapać to, co masowe i tanie do przeoczenia.

### ⚠️ Trzy istotne różnice względem projektu źródłowego — nie kopiuj wzorca 1:1

1. **Struktura obrazu jest inna.** PunktySzczepień ma (najwyraźniej) osobne `Dockerfile.dev`/
   `Dockerfile.prod`; Łowiska mają od zadania 004 **jeden wieloetapowy `Dockerfile`**
   (`base → vendor → assets → dev/prod`, ADR-002). To otworzyło opcję, której projekt źródłowy nie
   miał: uruchomienie SCA/SAST jako **etapu budowania Dockera**, reużywającego `base`, zamiast
   jako natywnych kroków na biegaczu GitHub Actions z `shivammathur/setup-php`. Rozstrzygnięte
   w [ADR-005](../../adr/ADR-005-mechanizm-uruchomienia-sast-sca-w-ci.md) — rekomendacja: natywny
   biegacz.
2. **`config.platform.php` jest już przypięty.** Projekt źródłowy odkrył w trakcie wdrożenia,
   że Composer rozwiązuje zależności wobec wersji PHP interpretera, nie wobec `require.php`, co
   zepsuło pierwsze wdrożenie bramki (lock zawierał pakiety niewdrażalne na PHP z kontenera).
   Łowiska mają to już rozwiązane: `composer.json` → `config.platform.php = "8.3.0"`, zgodne
   z `Dockerfile:9` (`php:8.3-cli-bookworm`, cele `base`/`dev`) i `Dockerfile:100`
   (`dunglas/frankenphp:1-php8.3`, cel `prod`). **Nie trzeba tego odkrywać ponownie** — trzeba to
   utrzymać: pin PHP w `phpstan.neon` (`phpVersion: 80300`) ma iść w parze z tymi dwoma miejscami,
   nie z trzecim, oddzielnym ustaleniem.
3. **Pakiet testów jest w pełni zielony.** Projekt źródłowy miał w trakcie tego zadania zawieszenie
   testów z powodu transformacji wizualnej (48 znanych, nieregresyjnych niepowodzeń) i musiał to
   rozstrzygnąć jako „bramka rusza mimo to". W Łowiskach pełny pakiet jest dziś **w całości
   zielony** (64 testy, potwierdzone bieżącym przebiegiem w tej sesji) — nie ma potrzeby żadnego
   podobnego rozstrzygnięcia; kryterium akceptacji może wprost wymagać zielonego przebiegu.

## Wymagania

### 1. SCA — blokada podatnych zależności

- `roave/security-advisories` ma trafić do `require-dev` (constraint `dev-latest`). Pakiet jest
  metapakietem bez kodu — działa przez wpisy `conflict`, więc **fizycznie uniemożliwia**
  `composer install`/`update` z zależnością o znanej podatności. Ma działać także lokalnie,
  bez CI.
- Krok `composer audit --locked` ma być **blokującym** krokiem w `deploy.yml`. Dependabot
  (natywny na GitHubie, nie wymaga pliku konfiguracyjnego — repozytorium nie ma dziś
  `.github/dependabot.yml` i nie musi go dostać w tym zadaniu) **alertuje, ale nie blokuje** —
  oba mechanizmy mają się uzupełniać.
- Jeśli `composer audit` wykaże istniejące podatności, ich spłata jest **częścią tego zadania**:
  w pierwszej kolejności przez `composer update` w obrębie istniejących constraintów, bez
  podnoszenia majorów i bez zmian w `composer.json` (poza dodaniem samego `roave/*`). Podatności
  wymagające majora trafiają do osobnego zadania.

### 2. SAST — PHPStan + Larastan ze strategią „ratchet"

- `larastan/larastan` ma trafić do `require-dev`, w wersji zgodnej z Laravel 12 (ten sam major,
  co w projekcie źródłowym — Łowiska też stoją na Laravel 12).
- `phpstan.neon` ma mieć **poziom 5** jako świadomy start (ten sam poziom sprawdzony i
  skalibrowany w projekcie źródłowym), ze ścieżkami `app`, `database`, `routes`.
- **Strategia „ratchet"**: pierwsze uruchomienie generuje `phpstan-baseline.neon` zamrażający
  istniejące naruszenia; krok w CI czerwienieje **wyłącznie na nowe**. Baseline zmniejsza się
  w kolejnych zadaniach — nigdy nie jest regenerowany „hurtem", bo to ukrywa świeżo wprowadzony
  błąd.
- **Pin `phpVersion: 80300` w `phpstan.neon` jest obowiązkowy** i ma być sprzężony z
  `Dockerfile:9`/`Dockerfile:100` oraz z `composer.json` → `config.platform.php` — wszystkie trzy
  zmieniają się razem, nigdy pojedynczo (patrz różnica 2 wyżej).
- Błędy oznaczone przez PHPStana jako `non-ignorable` **nie dają się zamrozić w baseline** i muszą
  zostać naprawione, żeby bramka mogła być zielona.
- Krok działa na biegaczu GitHub Actions, nie jako etap Dockera (ADR-005) — ma ustawiać
  `XDEBUG_MODE: off`, bo obraz `ubuntu-latest` ma domyślnie załadowany Xdebug, co kilkukrotnie
  spowalnia analizę statyczną bez pożytku.

### 3. Skan sekretów — gitleaks

- Krok CI ma używać **pinowanej wersji** gitleaks, zweryfikowanej sumą kontrolną SHA-256 pobranego
  archiwum — nie `latest`. Wersję i sumę ustalić i zweryfikować w trakcie implementacji (nie
  kopiować numeru z projektu źródłowego bez własnej weryfikacji — to inny moment w czasie).
- `.gitleaks.toml` ma mieć `[extend] useDefault = true`. Allowlista — **wyłącznie** potwierdzone
  false-positive, każdy z komentarzem dlaczego to nie jest sekret. Wykluczyć `vendor/` i
  `node_modules/` (niewersjonowane, `.gitignore`), zostawić w zakresie skanu ścieżki wersjonowane
  (`public/build` — wersjonowany od zadania 004).
- Wyjątki allowlisty dopasowujące **wartość w kontekście linii** (nie samą wykrytą wartość) mają
  używać `regexTarget = "line"` — domyślnie gitleaks dopasowuje do samej wykrytej wartości.
- Hook `pre-commit` ma leżeć w wersjonowanym `.githooks/` (nie w `.git/hooks/`), aktywowanym przez
  `git config core.hooksPath .githooks`. Hook **przepuszcza** commit, gdy gitleaks nie jest
  zainstalowany lokalnie — twardą bramką jest CI, nie hook.
- `.gitattributes` ma wymuszać `eol=lf` dla `.githooks/**` — skrypt powłoki z CRLF kończy się
  błędem `bad interpreter: /bin/sh^M` (istotne na tym repozytorium: host deweloperski to Windows).
- **Skan pełnej historii** (nie tylko HEAD) — jeśli coś ujawni, rotacja trafia do osobnego zadania
  incident response (patrz „Zakres wyłączeń").

### 4. Weryfikacja bramki testem negatywnym

- Każdy z trzech mechanizmów ma zostać **udowodniony celowym błędem**, nie odczytaniem
  konfiguracji.
- Kryterium sukcesu to nie tylko czerwony krok, ale **zatrzymanie wdrożenia** — sprawdzone
  strukturalnie (job `deploy` ma `needs: [security]`, więc awaria joba `security` uniemożliwia
  start `deploy` niezależnie od kolejności kroków wewnątrz niego) oraz, jeśli to możliwe w sesji,
  realnym przebiegiem CI po wypchnięciu (decyzja o pushu
  należy do użytkownika, nie do `/implement-task`).
- Skanery sekretów dopasowują **strukturę i entropię**, nie „prawdziwość" wartości — test wartością
  w rodzaju `12345` przejdzie na zielono i będzie wyglądał na zepsutą bramkę. Do testu użyć
  wartości o kształcie prawdziwego poświadczenia (np. para klucz/sekret AWS), nigdy realnego
  sekretu.

## Kryteria akceptacji

- [x] `roave/security-advisories` jest w `require-dev` (`dev-latest`), `composer install` schodzi
      czysto (`composer validate` → „valid"; lock weryfikuje się na platformie 8.3).
- [x] `composer audit --locked` jest **blokującym** krokiem w jobie `security` i przechodzi na
      zielono — **18 advisory w 9 pakietach spłacone** `composer update` w obrębie constraintów,
      **bez zmiany `composer.json`** (patrz „Wyniki weryfikacji").
- [x] `phpstan.neon` z poziomem 5, pinem `phpVersion: 80300` sprzężonym z `Dockerfile` i
      `composer.json` → `config.platform.php`, z baseline'em; `phpstan analyse` → „No errors".
- [x] Krok PHPStan czerwienieje **wyłącznie na nowe** błędy — udowodnione **dwoma** celowymi
      naruszeniami (nowy plik + plik już obecny w baseline), patrz „Wyniki weryfikacji".
- [x] Błędy `non-ignorable` — **nie wystąpiły** na wejściu; wszystkie 47 dało się zamrozić.
- [x] `.gitleaks.toml` w repo; krok gitleaks w CI z wersją **8.30.1** pinowaną i zweryfikowaną
      sumą SHA-256 względem oficjalnego `checksums.txt` wydania; skan czyta realną treść
      (94 commity, 4,01 MB — nie „0 plików").
- [x] **Test negatywny przeszedł, w obu trybach** (`dir` i `protect --staged`) — z istotnym
      odkryciem po drodze, patrz „Wyniki weryfikacji". Zatrzymanie wdrożenia potwierdzone
      **strukturalnie** (`deploy` ma `needs: [security]` — zweryfikowane parsowaniem YAML-a).
- [x] Hook `.githooks/pre-commit` w repo (tryb `100755`), z jawnym `eol=lf` w `.gitattributes`
      i instrukcją aktywacji w `README.md`.
- [x] Pełny pakiet testów (`docker compose exec app php artisan test`) jest zielony — **64/64,
      221 asercji**, mimo aktualizacji ponad stu pakietów.
- [ ] Zielony przebieg CI po pierwszym wypchnięciu zmian **potwierdzony przez użytkownika** —
      `/implement-task` nie pushuje ani nie commituje; **to kryterium świadomie pozostaje otwarte**
      i domyka się poza sesją.

## Zakres testów

- **Tier:** T3 — pełny pakiet
- **Uruchamiamy:**
  ```bash
  docker compose exec app php artisan test
  ```
- **Uzasadnienie:** zadanie zmienia `composer.json`, czyli pozycję z listy „T3 obowiązkowy"
  w `CLAUDE.md`, a nowa zależność (`larastan/larastan`) wnosi własny bootstrap analizy. T3
  **nie jest tu przedmiotem wyboru**. W przeciwieństwie do zadania źródłowego (00054
  w PunktSzczepień), tutaj **nie ma** zawieszenia testów do respektowania — pełny pakiet ma
  wyjściowo przejść w całości i to jest wprost egzekwowalne kryterium akceptacji, nie tylko
  wymóg uruchomienia i udokumentowania stanu.

## Zakres wyłączeń

- **Psalm i analiza taint** — świadomie poza zakresem. W projekcie źródłowym skalibrowano to
  narzędzie i wykazano, że nie rozpoznaje sinków Laravela (`request()->input() → whereRaw()`,
  `DB::select()`) — ten sam stack (Laravel 12) oznacza tę samą ślepotę tutaj.
- **CodeQL** — nie wspiera PHP, więc darmowy SAST GitHuba nie wchodzi w grę niezależnie od
  widoczności repozytorium.
- **Zaplanowany (cykliczny) audyt zależności** — Dependabot alertuje natywnie w momencie
  publikacji advisory; nie potrzeba osobnego harmonogramu.
- **Testy-strażniki klas podatności** (np. skan `{!! !!}` w widokach Blade, weryfikacja
  autoryzacji przez refleksję nad `app/Policies/**`) — osobne zadanie. **Różnica względem
  projektu źródłowego:** ten miał „0 polityk i 0 wywołań `Gate::`" i wykluczał to wprost jako
  „nie ma czego strzec"; Łowiska mają **czternaście polityk i Shielda** (`CLAUDE.md`,
  „Autoryzacja"), więc powód wyłączenia jest inny — to realna, większa praca zasługująca na
  własne zadanie z własnym rozpoznaniem, nie na doklejenie przy okazji mechanicznej siatki.
- **Rotacja sekretów znalezionych w historii** — gdyby skan historii coś ujawnił, ma powstać
  osobne zadanie incident response, a znalezisko trafia do `docs/security/` (katalog już istnieje
  w tym repozytorium od zadania 002 — inaczej niż w projekcie źródłowym, gdzie decyzja „katalog
  nie jest potrzebny" wynikała z jego braku).
- **Podnoszenie poziomu PHPStana powyżej 5 i redukcja baseline'u** — kolejne zadania.
- **Dokumentowanie `deploy.yml` poza zakresem tej bramki** — plik jest dziś w całości
  nieudokumentowany w `docs/operations/`. To zadanie opisuje wyłącznie kroki bezpieczeństwa, które
  dokłada; pełny opis pipeline'u (sekrety, zmienne środowiskowe per `ENV`, struktura joba) to
  osobny, mniejszy dług dokumentacyjny, nie część tego zadania.

## Zmiany dokumentacji

- [ ] `docs/operations/obraz-produkcyjny.md` — nowa sekcja: trzy warstwy bramki (SCA/SAST/sekrety),
      gdzie stoją w `deploy.yml`, jak aktywować hook lokalnie.
- [ ] `README.md` — jednorazowa aktywacja hooka (`git config core.hooksPath .githooks`).
- [ ] `CLAUDE.md` — nowa sekcja „Bezpieczeństwo w CI" (analogicznie do istniejącej „Bezpieczeństwo
      bazy danych"): jakie warstwy działają, kiedy, i **sprzężenie pinu PHP w trzech miejscach**
      (`Dockerfile`, `composer.json` → `config.platform.php`, `phpstan.neon`).
- [ ] `CHANGELOG.md` — pominięte: zmiana dotyczy wyłącznie procesu CI/CD, niewidoczna dla
      użytkownika aplikacji (ten sam precedens co w zadaniu 004 dla zmian czysto deweloperskich).

## Ograniczenia techniczne

- Laravel 12, PHP 8.3 (`Dockerfile`, `config.platform.php`), Pest 3; testy uruchamiane
  **wyłącznie** przez `docker compose exec app php artisan test` — `test.sh` **nie istnieje**
  w tym repozytorium (usunięty w zadaniu 004) i nie ma prawa pojawić się w konfiguracji CI ani
  w opisie tego zadania.
- `deploy.yml` ma dziś **jeden job** (`deploy`) i dwa niezależne wyzwalacze (`push` na `dev`, tagi
  `v*`). Po tym zadaniu ma mieć **dwa joby** (`security`, `deploy` z `needs: [security]`) — patrz
  „Rozstrzygnięcia". Oba wyzwalacze dostają **ten sam** komplet kontroli bezpieczeństwa, bez
  różnicowania (patrz „Rozstrzygnięcia").
- Uwierzytelnianie do GCP idzie przez Workload Identity Federation (`id-token: write`, krok
  `google-github-actions/auth@v2`) — **wyłącznie w jobie `deploy`**. Job `security` **nie
  potrzebuje sekretów chmurowych i nie ma ich dostawać** — uruchamia się przed uwierzytelnieniem,
  nie po nim, i nie dziedziczy uprawnień `id-token: write`, jeśli nie zostaną mu jawnie nadane.
- Obraz produkcyjny powstaje z celu `prod` jednego `Dockerfile`; dodanie zależności
  deweloperskich (`roave/*`, `larastan/*`) **nie może** sprawić, że trafią do obrazu
  produkcyjnego — cel `vendor` już dziś robi `composer install --no-dev`, więc ryzyko jest
  strukturalnie niskie, ale warto to potwierdzić po zmianie.
- `larastan/laravel` musi wspierać Laravel `^12.0` — do zweryfikowania w implementacji (numer
  wersji nie kopiowany z projektu źródłowego bez sprawdzenia, bo to inny moment w czasie).

## Wyniki weryfikacji (wdrożenie, 2026-08-15)

### SCA

- Audyt przed zmianą: **18 advisory w 9 pakietach** (m.in. `symfony/yaml` — ReDoS i wyczerpanie
  stosu przy parsowaniu zagnieżdżonych struktur).
- Spłacone `composer update` w obrębie istniejących constraintów, **bez zmian w `composer.json`**
  i bez podnoszenia majorów. Największy ruch: `filament/filament` v3.3.32 → v3.3.54
  (wewnątrz `^3.3`); `laravel/framework` bez zmiany (v12.66.0). Żadna podatność nie wymagała
  majora, więc **nie powstaje osobne zadanie** na spłatę resztek.
- Audyt po zmianie: **brak advisory**. `roave/security-advisories` zainstalował się czysto, co
  samo w sobie potwierdza brak podatnych wersji — jego wpisy `conflict` zablokowałyby instalację.
- ⚠️ `composer update` pociągnął **zaktualizowane zasoby Filamenta** w `public/css/filament/**`
  i `public/js/filament/**` (skrypt `post-autoload-dump` → `filament:upgrade`). To wersjonowane
  pliki i konieczna konsekwencja aktualizacji pakietu — muszą wejść do commita razem z lockiem,
  inaczej front-end panelu rozjedzie się z wersją PHP pakietu.

### SAST

- Pierwsze uruchomienie: **47 błędów** na poziomie 5, zamrożonych w `phpstan-baseline.neon`
  (36 wpisów, bo część wpisów zbiera powtórzenia w tym samym pliku). **Żaden nie był
  `non-ignorable`** — cała pula dała się zamrozić, nie było czego naprawiać na wejściu.
  Redukcja baseline'u to osobne zadanie („Zakres wyłączeń").
- Ścieżki w baseline zapisały się **względnie, z ukośnikami zwykłymi** (`app/Filament/...`),
  więc plik wygenerowany na Windowsie dopasuje się na linuksowym biegaczu CI.
- **Test negatywny (ratchet) przeszedł.** Wprowadzone celowo dwa nowe błędy: jeden w pliku
  zupełnie nowym (`app/Services/RatchetProbe.php`), drugi w pliku, który **ma już wpisy
  w baseline** (`app/Services/CSOService.php`). Oba wykryte, kod wyjścia 1, a 47 zamrożonych
  błędów pozostało wyciszonych — to dowodzi, że baseline wycisza **pojedyncze błędy, nie całe
  pliki**, czyli że ratchet działa. Obie sondy usunięto, `git diff` potwierdził powrót do stanu
  wyjściowego.

### Skan sekretów

- ⚠️ **Pierwszy test negatywny dał wynik fałszywie negatywny — i to jest najważniejsze ustalenie
  tej weryfikacji.** Sonda z parą `AKIAIOSFODNN7EXAMPLE` / `wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY`
  **nie została wykryta**, bo to oficjalne wartości przykładowe z dokumentacji AWS, które gitleaks
  ma we wbudowanej allowliście. Wymaganie „użyj wartości o kształcie prawdziwego poświadczenia"
  jest więc **niewystarczające**: wartość musi być jednocześnie właściwego kształtu **i nie być
  znanym przykładem z dokumentacji**. Powtórka z wartościami **losowymi** dała trafienie
  (`generic-api-key`) i kod wyjścia 1.
- **Test negatywny przeszedł w obu trybach**: skan katalogu (tryb CI) i skan zmian zakolejkowanych
  (`protect --staged`, tryb hooka). Sonda została usunięta i **nigdy nie została zacommitowana**
  (`git restore --staged` + `rm`, potwierdzone czystym `git status`).
- **Skan pełnej historii jest czysty**: 94 commity, 4,01 MB, brak trafień. Nie ma czego rotować
  i **nie powstaje zadanie incident response** przewidziane w „Zakresie wyłączeń".
- Skan **katalogu roboczego** dawał 129 trafień — wszystkie w plikach **niewersjonowanych**:
  4 w `.env` (prawdziwe lokalne sekrety, `.gitignore`) i 125 w `storage/` (skompilowane widoki,
  cache). Stąd decyzja, żeby w CI skanować **historię gita** (`gitleaks git`), a nie katalog:
  w repozytorium liczy się to, co jest w commitach. `.env` **nie został** dodany do allowlisty
  świadomie — gdyby kiedyś trafił do repozytorium wbrew `.gitignore`, skan ma o tym krzyknąć.
- Wersja gitleaks przypięta na **8.30.1**, suma SHA-256 pobranego archiwum zweryfikowana
  względem oficjalnego `gitleaks_8.30.1_checksums.txt` z wydania (nie tylko policzona z tego,
  co się pobrało).

### Czego **nie** udało się potwierdzić empirycznie

Kryterium „czerwony krok **i pominięty deploy**" potwierdzono **strukturalnie, nie przebiegiem
CI**: `deploy` ma `needs: [security]` (zweryfikowane parsowaniem `deploy.yml` parserem YAML,
nie odczytem wzrokowym), a GitHub Actions nie startuje joba, którego zależność zakończyła się
niepowodzeniem. Prawdziwy przebieg CI wymaga wypchnięcia zmian, co jest decyzją użytkownika —
`/implement-task` nie commituje ani nie pushuje. **Do potwierdzenia po pierwszym wypchnięciu.**

⚠️ **Potwierdzone po pierwszym pushu (2026-08-15) — i naprawione tu, nie w zadaniu 008.**
Pierwszy realny przebieg `security` czerwienił się już na kroku „Install PHP dependencies",
zanim doszło do SCA/SAST/sekretów: `composer install` bez `--no-scripts` budzi framework
przez `post-autoload-dump` (`artisan package:discover`), a job nie miał ustawionego `APP_ENV`
ani `.env`. Laravel domyślnie rozwiązuje `APP_ENV` na `production`
(`config/app.php` → `env('APP_ENV', 'production')`), dysk uploadów bez `FILESYSTEM_DISK` — na
`local`; strażnik z zadania 005 (`AppServiceProvider::assertUploadDiskIsSafe()`) odmawia wtedy
startu aplikacji, bo poza `local`/`testing` dysk `local` oznacza utratę plików przy restarcie
kontenera. Ten sam wybuch spotkałby krok SAST — Larastan też budzi kontener aplikacji.
**To luka wspólna zadań 005 i 007**, nie awaria wersji PHP i nie skutek zadania 008 (008 nie
dotyka `deploy.yml` ani `AppServiceProvider`). Naprawiono jednym wierszem: `security` dostał
`APP_ENV: local` na poziomie joba (`.github/workflows/deploy.yml`) — job analizuje kod i nigdy
nie rozmawia z GCP, więc `local` jest tu poprawnym, a nie obchodzonym środowiskiem.
Zweryfikowane bezpośrednim wywołaniem `AppServiceProvider::assertUploadDiskIsSafe()` w kontenerze
`app`: `('production', 'local')` rzuca wyjątek (odtworzenie awarii), `('local', 'local')` — nie
(potwierdzenie poprawki).

## Rozstrzygnięcia

<!-- Punktowe decyzje ustalone przy /review-task: wygląd, copy, próg, nazwa, umiejscowienie.
     Wiążą implementację tak samo jak decyzje z ADR-ów, ale nie mają zasięgu poza zadaniem.
     Format: decyzja + jednozdaniowe uzasadnienie. -->
- **Osobny job `security` w `deploy.yml`, od którego zależy `deploy` przez `needs: [security]`.**
  Nie kroki wewnątrz istniejącego joba `deploy` (jak w projekcie źródłowym) — autor zadania
  wskazał wprost osobny job. **Kierunek zależności jest kluczowy i celowo zapisany bez
  dwuznaczności**: to `deploy` czeka na `security`, nie odwrotnie — inny kierunek sprawdzałby
  kod **po** wdrożeniu, co nie spełniałoby kryterium „zatrzymanie wdrożenia". Job `security` nie
  potrzebuje sekretów chmurowych ani `id-token: write` — stoi przed uwierzytelnieniem do GCP,
  nie po nim.
- **Ten sam komplet kontroli (SCA + SAST + skan sekretów) dla wyzwalacza `push` (staging) i dla
  `tag v*` (prod), bez różnicowania.** SCA/SAST/sekrety dotyczą kodu, nie środowiska docelowego —
  ten sam commit, ta sama analiza; różnicowanie zwiększyłoby złożoność bez korzyści, bo podatność
  w zależności nie przestaje być podatnością na produkcji.
- **Gitleaks jako natywny krok biegacza w jobie `security`**, niezależnie od mechanizmu SCA/SAST
  (ADR-005) — nie potrzebuje PHP/Composera, więc nie ma powodu wiązać go z żadną z dwóch opcji
  tamtej decyzji.

## Powiązane ADR-y

<!-- Numery ADR-ów podjętych dla tego zadania (uzupełnia /review-task).
     Tylko decyzje spełniające trzyskładnikowe kryterium z CLAUDE.md —
     reszta idzie do „Rozstrzygnięcia" powyżej. -->
- [**ADR-005 — SCA/SAST w CI: natywny biegacz GitHub Actions, nie etap Dockera**](../../adr/ADR-005-mechanizm-uruchomienia-sast-sca-w-ci.md)
  — **status: proposed, sekcja „Decyzja" do wypełnienia przez autora.** Rekomendacja: Opcja A
  (`shivammathur/setup-php` + `composer install` natywnie na biegaczu, nie nowy cel `security`
  w `Dockerfile`). Kwalifikuje się jako ADR, nie rozstrzygnięcie: zasięg wykracza poza to zadanie
  (ustala wzorzec dla każdej przyszłej kontroli PHP w CI), koszt odwrócenia jest wysoki
  (przeniesienie logiki między `Dockerfile` i `deploy.yml`), a uzasadnienie — dlaczego nie
  poświęcać czystości `Dockerfile` na rzecz oszczędności, która w praktyce jest niewielka — nie
  wynika z samego kodu. ⚠️ `/implement-task` zatrzyma się, dopóki sekcja Decyzja pozostaje pusta.

## Otwarte pytania — zamknięte przy `/review-task` (2026-08-15)

- ~~**SCA/SAST jako etap Dockera czy jako natywne kroki na biegaczu GitHub Actions?**~~ →
  **[ADR-005](../../adr/ADR-005-mechanizm-uruchomienia-sast-sca-w-ci.md)**, rekomendacja: natywny
  biegacz.
- ~~**Czy gitleaks skanuje też w ramach etapu Dockera, czy wyłącznie jako natywny krok?**~~ →
  **Natywny krok**, niezależnie od decyzji ADR-005 — patrz „Rozstrzygnięcia".
- ~~**Czy krok bezpieczeństwa ma być osobnym jobem z `needs:` zamiast kroków w istniejącym jobie
  `deploy`?**~~ → **Osobny job `security`**, `deploy` z `needs: [security]` — patrz
  „Rozstrzygnięcia". Kierunek zależności doprecyzowany przy `/review-task` (pierwsza wersja
  odpowiedzi była dwuznaczna co do kierunku `needs:`).
