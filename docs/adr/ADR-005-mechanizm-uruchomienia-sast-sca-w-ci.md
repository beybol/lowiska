# ADR-005 — SCA/SAST w CI: natywny biegacz GitHub Actions, nie etap Dockera

- **Status:** accepted
- **Data:** 2026-08-15
- **Zadanie:** [007 — Bramka bezpieczeństwa w CI: SAST, SCA i skan sekretów](../tasks/implemented/007-bramka-bezpieczenstwa-w-ci-sast-sca-sekrety.md)

## Kontekst

Zadanie 007 dokłada do `.github/workflows/deploy.yml` blokującą bramkę: `composer audit --locked`
(SCA) i PHPStan/Larastan (SAST), obie uruchamiane przed wdrożeniem (job `security`, od którego
zależy job `deploy` przez `needs: [security]` — rozstrzygnięcie zadania 007). Trzeba ustalić,
**czym fizycznie te dwa kroki są uruchamiane**.

Łowiska mają (inaczej niż bliźniaczy projekt źródłowy, PunktySzczepień, gdzie ten sam wzorzec
wdrożono i zmierzono w zadaniu `00054`) **jeden wieloetapowy `Dockerfile`** ustalony w zadaniu 004
(`base → vendor → assets → dev/prod`, ADR-002), z gotowym celem `base` (PHP 8.3, rozszerzenia,
Composer) i `vendor` (`composer install --no-dev`, dla obrazu produkcyjnego). To otwiera opcję,
której projekt źródłowy nie miał: uruchomienie SCA/SAST jako **kolejnego celu tego samego
`Dockerfile`**, zamiast jako natywnych kroków na biegaczu.

Decyzja wiąże kształt **dwóch plików na dłużej** (`Dockerfile`, `deploy.yml`) i ustala **wzorzec**,
którym pójdzie każda przyszła kontrola wymagająca PHP w CI (nie tylko te dwie z zadania 007) —
odwrócenie po fakcie oznacza przeniesienie logiki między plikami, nie edycję jednej linii.

## Alternatywy

### Opcja A — natywny biegacz: `shivammathur/setup-php` + `composer install`

Job `security` instaluje PHP 8.3 na runnerze `ubuntu-latest` (`shivammathur/setup-php`,
`php-version: '8.3'`, `extensions: pdo_mysql, mbstring, exif, pcntl, bcmath, gd, zip, intl, soap`
— ten sam zestaw co w `Dockerfile`), robi `composer install` (z zależnościami deweloperskimi) i
uruchamia `composer audit --locked` oraz `vendor/bin/phpstan analyse` jako osobne, nazwane kroki.

**Zalety:**
- **Wzorzec sprawdzony w działaniu** — dokładnie to zrobił i zmierzył projekt źródłowy
  (PunktySzczepień, zadanie `00054`): zero-day nieznanych problemów specyficznych dla tego
  podejścia.
- **Osobne, czytelne kroki w logu CI** — awaria `composer audit` i awaria `phpstan analyse`
  pokazują się jako dwa różne, nazwane kroki w interfejsie GitHub Actions, nie jako jedna linia
  w logu `docker build`.
- **Zależności deweloperskie (`roave/*`, `larastan/*`) nigdy nie dotykają warstw Dockera** —
  instalują się wyłącznie w efemerycznym środowisku biegacza, zero teoretycznego ryzyka, że
  trafią do cache'u obrazu albo do warstwy współdzielonej z celem `prod`.
- `setup-php` cache'uje instalację PHP/rozszerzeń międzybiegowo (`actions/cache` wbudowany
  w akcję) — powtórne uruchomienia są szybkie bez zarządzania cache'em samodzielnie.

**Wady:**
- **Duplikuje deklarację wersji PHP i rozszerzeń** względem `Dockerfile` — czwarte miejsce
  (obok `Dockerfile`, `composer.json` → `config.platform.php`, `phpstan.neon`), które trzeba
  zmieniać razem przy podniesieniu wersji PHP.
- `composer install` na biegaczu instaluje zależności **od zera**, niezależnie od tego, co
  `docker build` i tak zainstaluje chwilę później w celu `vendor` — praca robiona dwa razy
  w tym samym przebiegu CI.

### Opcja B — nowy cel `security` w `Dockerfile`, uruchamiany przez `docker build --target security`

`FROM base AS security` w `Dockerfile`, instalujący zależności **z** deweloperskimi
(`composer install` bez `--no-dev`) i uruchamiający `composer audit --locked` oraz
`phpstan analyse` jako instrukcje `RUN` — niepowodzenie któregokolwiek przerywa `docker build`
z niezerowym kodem wyjścia. Job `security` w CI sprowadza się do jednej komendy:
`docker build --target security .`.

**Zalety:**
- **Zero duplikacji** — PHP, rozszerzenia i Composer pochodzą z tego samego celu `base`, którego
  i tak używają cele `dev`/`prod`/`vendor`. Wersja PHP ma jedno źródło prawdy mniej do
  synchronizacji (nie licząc pinu w `phpstan.neon`, koniecznego niezależnie od wyboru).
- Reużywa cache warstw Dockera między uruchomieniami CI (przy skonfigurowanym cache'u
  rejestru/BuildKit) — potencjalnie szybsze powtórne przebiegi niż instalacja od zera na
  biegaczu.

**Wady:**
- **Awaria `composer audit`/`phpstan` wygląda w logu CI jak awaria budowania obrazu** — jeden
  strumień wyjścia `docker build`, trudniejszy do szybkiego zdiagnozowania niż nazwany krok
  GitHub Actions; traci się rozróżnienie „co konkretnie padło" bez przewijania logu budowania.
- **`Dockerfile` przestaje być czysto o kształcie obrazu** (`dev`/`prod`, ADR-002) i zaczyna nieść
  też logikę CI/tooling — zaciera granicę, którą zadanie 004 świadomie ustaliło (`bootstrap/app.php`
  ma wyłącznie okablowanie frameworka, nie logikę biznesową; ten sam duch dotyczy `Dockerfile` —
  ma opisywać, jak zbudować obraz, nie jak zweryfikować jego jakość).
- **Teoretyczne ryzyko dla warstw cache'u**: zależności deweloperskie (`roave/*`, `larastan/*`)
  trafiają do warstwy Dockera nowego celu `security`, nawet jeśli ten cel nigdy nie jest tagowany
  ani publikowany. Przy współdzielonym cache'u warstw między celami (typowe dla BuildKit) rośnie
  ryzyko, że coś z tej warstwy przecieknie do innego celu przy przyszłej, nieostrożnej zmianie
  kolejności instrukcji — ryzyko małe, ale nieobecne w Opcji A.
- **Wzorzec niesprawdzony** — ani ten projekt, ani projekt źródłowy nie mają doświadczenia
  z uruchamianiem SAST/SCA jako celu Dockera; Opcja A ma za sobą pełne wdrożenie i pomiar.

## Rekomendacja

**Opcja A — natywny biegacz GitHub Actions.**

Trzy argumenty przesądzają:

1. **Sprawdzony wzorzec bije teoretyczną elegancję.** Projekt źródłowy przeszedł już przez to
   dokładnie raz, ze zmierzonym wynikiem (m.in. odkrycie i naprawę rozjazdu platformy PHP —
   błąd, którego Łowiska już nie popełnią, bo `config.platform.php` jest przypięty od początku).
   Powtórzenie tego wzorca zamiast wynajdywania nowego zmniejsza ryzyko nieznanych efektów
   ubocznych w code path, którego nikt jeszcze nie przetestował.
2. **Czytelność awarii w CI ma wartość operacyjną.** Gdy bramka zaczerwieni się za pół roku,
   osoba diagnozująca problem korzysta z tego, że „SCA" i „SAST" są osobnymi, nazwanymi krokami
   — nie z jednego zbiorczego `docker build`, w którym trzeba przewijać log budowania obrazu,
   żeby znaleźć właściwą linijkę.
3. **`Dockerfile` zostaje wyłącznie o kształcie obrazu**, zgodnie z duchem ustalonym w zadaniu 004
   (rozdział odpowiedzialności między plikami: obraz kontra proces weryfikacji jakości kodu).
   Dokładanie celu `security` zaciera tę granicę dla oszczędności, która w praktyce jest niewielka
   (jednorazowa instalacja PHP na biegaczu, cache'owana przez samą akcję `setup-php`).

**Konsekwencja dla gitleaks:** gitleaks nie potrzebuje PHP/Composera, więc niezależnie od tej
decyzji jest naturalnym, osobnym krokiem na biegaczu w tym samym jobie `security` — ta decyzja
go nie dotyczy wprost, ale utrwala job `security` jako miejsce, gdzie mieszkają **wszystkie**
kontrole niewymagające zbudowanego obrazu.

**Koszt przyjęty świadomie:** czwarte miejsce pinu wersji PHP (obok `Dockerfile`,
`config.platform.php`, `phpstan.neon`) w kroku `setup-php`. Zapisane wprost w `CLAUDE.md`
(sekcja „Bezpieczeństwo w CI", zadanie 007) jako coś, co zmienia się razem z pozostałymi trzema
— nie osobno.

## Decyzja
Decyzja: A

Uzasadnienie: Sprawdzony wzorzec.
