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
   (`base → vendor → assets → dev/prod`, ADR-002). To otwiera opcję, której projekt źródłowy nie
   miał: uruchomienie SCA/SAST jako **etapu budowania Dockera**, reużywającego `base`, zamiast
   jako natywnych kroków na biegaczu GitHub Actions z `shivammathur/setup-php`. Obie drogi
   działają — wybór jest otwartym pytaniem niżej, nie założeniem.
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
- Jeśli krok działa na biegaczu GitHub Actions (nie jako etap Dockera — patrz otwarte pytania),
  ma ustawiać `XDEBUG_MODE: off` — obraz `ubuntu-latest` ma domyślnie załadowany Xdebug, co
  kilkukrotnie spowalnia analizę statyczną bez pożytku.

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
  strukturalnie (kolejność kroków w `deploy.yml`: bramka przed `docker build`/uwierzytelnieniem
  do GCP) oraz, jeśli to możliwe w sesji, realnym przebiegiem CI po wypchnięciu (decyzja o pushu
  należy do użytkownika, nie do `/implement-task`).
- Skanery sekretów dopasowują **strukturę i entropię**, nie „prawdziwość" wartości — test wartością
  w rodzaju `12345` przejdzie na zielono i będzie wyglądał na zepsutą bramkę. Do testu użyć
  wartości o kształcie prawdziwego poświadczenia (np. para klucz/sekret AWS), nigdy realnego
  sekretu.

## Kryteria akceptacji

- [ ] `roave/security-advisories` jest w `require-dev`, `composer install` schodzi czysto.
- [ ] `composer audit --locked` jest **blokującym** krokiem w `deploy.yml` i przechodzi na zielono
      (ewentualne istniejące advisory spłacone w obrębie constraintów).
- [ ] `phpstan.neon` z poziomem 5, pinem `phpVersion: 80300` sprzężonym z `Dockerfile` i
      `composer.json` → `config.platform.php`, z baseline'em; `phpstan analyse` przechodzi na
      zielono lokalnie.
- [ ] Krok PHPStan w CI czerwienieje **wyłącznie na nowe** błędy — udowodnione celowym
      naruszeniem, nie samą konfiguracją.
- [ ] Błędy `non-ignorable` (jeśli wystąpią) naprawione, nie obejściem.
- [ ] `.gitleaks.toml` w repo; krok gitleaks w CI z pinowaną, zweryfikowaną wersją, skanujący
      realną treść (log potwierdza liczbę przeskanowanych plików/bajtów, nie „0 plików").
- [ ] **Test negatywny przeszedł**: podrzucony fikcyjny sekret o właściwym kształcie daje czerwony
      krok **i pominięty deploy** — udokumentowane w zadaniu.
- [ ] Hook `.githooks/pre-commit` w repo, z `eol=lf` w `.gitattributes` i instrukcją aktywacji.
- [ ] Pełny pakiet testów (`docker compose exec app php artisan test`) jest zielony —
      **w Łowiskach to twarde kryterium, nie odroczone** (patrz różnica 3 w „Opisie problemu"):
      stan wyjściowy jest już w pełni zielony, więc bramka nie ma prawa wprowadzić regresji.
- [ ] Zielony przebieg CI po pierwszym wypchnięciu zmian **potwierdzony przez użytkownika** —
      `/implement-task` nie pushuje ani nie commituje; to kryterium domyka się poza sesją.

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
- `deploy.yml` ma **jeden job** (`deploy`) i dwa niezależne wyzwalacze (`push` na `dev`, tagi
  `v*`). Bramka ma rozróżniać te ścieżki tylko tam, gdzie to ma znaczenie (SCA/SAST/sekrety
  dotyczą kodu, nie środowiska docelowego, więc prawdopodobnie **nie muszą** się różnić między
  push i tagiem — do potwierdzenia w implementacji).
- Uwierzytelnianie do GCP idzie przez Workload Identity Federation (`id-token: write`, krok
  `google-github-actions/auth@v2`). Krok bezpieczeństwa **nie potrzebuje sekretów chmurowych i nie
  ma ich dostawać** — ma stać przed krokiem `auth`, nie po nim.
- Obraz produkcyjny powstaje z celu `prod` jednego `Dockerfile`; dodanie zależności
  deweloperskich (`roave/*`, `larastan/*`) **nie może** sprawić, że trafią do obrazu
  produkcyjnego — cel `vendor` już dziś robi `composer install --no-dev`, więc ryzyko jest
  strukturalnie niskie, ale warto to potwierdzić po zmianie.
- `larastan/laravel` musi wspierać Laravel `^12.0` — do zweryfikowania w implementacji (numer
  wersji nie kopiowany z projektu źródłowego bez sprawdzenia, bo to inny moment w czasie).

## Rozstrzygnięcia

<!-- Punktowe decyzje ustalone przy /review-task: wygląd, copy, próg, nazwa, umiejscowienie.
     Wiążą implementację tak samo jak decyzje z ADR-ów, ale nie mają zasięgu poza zadaniem.
     Format: decyzja + jednozdaniowe uzasadnienie. -->
- 

## Powiązane ADR-y

<!-- Numery ADR-ów podjętych dla tego zadania (uzupełnia /review-task).
     Tylko decyzje spełniające trzyskładnikowe kryterium z CLAUDE.md —
     reszta idzie do „Rozstrzygnięcia" powyżej. -->
- 

## Otwarte pytania (dla `/review-task`)

- **SCA/SAST jako etap Dockera czy jako natywne kroki na biegaczu GitHub Actions?** Łowiska mają
  (inaczej niż projekt źródłowy) jeden wieloetapowy `Dockerfile` z gotowym celem `base`
  (PHP 8.3 + Composer) i `vendor` (zależności). Wariant A: nowy cel `security` w `Dockerfile`
  (`FROM base AS security`, `composer install` **z** dev-zależnościami, `RUN composer audit
  --locked && vendor/bin/phpstan analyse`), wywoływany w CI jako `docker build --target security .`
  — zero duplikacji instalacji PHP/Composera na biegaczu, ale dokłada cel do pliku, który dziś
  jest czysto `dev`/`prod`. Wariant B: `shivammathur/setup-php` + `composer install` bezpośrednio
  w kroku `deploy.yml`, tak jak w projekcie źródłowym — sprawdzony wzorzec, ale duplikuje
  instalację zależności PHP względem tego, co i tak dzieje się w `docker build`. Decyzja wiąże
  kształt `Dockerfile` i `deploy.yml` na dłużej — zasięg poza to zadanie, koszt odwrócenia
  (przeniesienie logiki między plikami) niebagatelny, uzasadnienie niebanalne. **Kandydat na
  ADR**, nie rozstrzygnięcie w treści — do potwierdzenia przy `/review-task` względem
  trzyskładnikowego kryterium z `CLAUDE.md`.
- **Czy gitleaks skanuje też w ramach etapu Dockera, czy wyłącznie jako natywny krok?** Gitleaks
  nie potrzebuje PHP/Composera — naturalnie pasuje jako krok niezależny od wyboru powyżej, ale
  warto to potwierdzić razem z poprzednią decyzją, żeby bramka miała jeden spójny kształt.
- **Czy krok bezpieczeństwa ma być osobnym jobem z `needs:` zamiast kroków w istniejącym jobie
  `deploy`?** Projekt źródłowy zostawił to w jednym jobie (awaria kroku domyślnie przerywa cały
  job — wystarcza do „zatrzymania wdrożenia"). Ten sam argument stosuje się tutaj identycznie;
  wstępna rekomendacja to powtórzenie tego wzorca, ale to kwestia punktowa, nie ADR.