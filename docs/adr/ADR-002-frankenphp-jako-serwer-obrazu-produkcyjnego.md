# ADR-002 — FrankenPHP jako serwer aplikacyjny obrazu produkcyjnego

- **Status:** accepted
- **Data:** 2026-08-15
- **Zadanie:** [004 — Przebudowa lokalnego środowiska deweloperskiego na kontenery](../tasks/implemented/004-przebudowa-lokalnego-srodowiska-w-kontenerach.md)

## Kontekst

Zadanie 004 zastępuje dwa niemal identyczne pliki (`Dockerfile.dev`, `Dockerfile.prod`) jednym
wieloetapowym `Dockerfile`. Przy okazji trzeba rozstrzygnąć, **na czym stoi cel `prod`** — bo dzisiejszy
obraz produkcyjny jest niezgodny z platformą, na którą aplikacja ma trafić.

**Dzisiejszy `Dockerfile.prod` (`php:8.3-apache`) na Cloud Run nie zadziała bez przeróbki:**

- **`EXPOSE 80` i `apache2-foreground`** — Apache nasłuchuje na porcie wpisanym w konfigurację,
  a Cloud Run **wstrzykuje port zmienną `$PORT`** i oczekuje, że kontener się do niej dostosuje.
- **`.docker/vhost.conf` deklaruje `<VirtualHost *:80>`** na sztywno; obsługa `$PORT` wymaga
  dodatkowo `Listen ${PORT}` w `ports.conf` i podstawiania zmiennej w entrypoincie.
- **Apache w kontenerze to model wieloprocesowy** (prefork/mpm), zaprojektowany pod długo żyjącą
  maszynę, nie pod instancję skalowaną do zera i ubijaną `SIGTERM`-em. Bez procesu numer jeden
  poprawnie propagującego sygnały żądania w locie są przerywane przy każdym skalowaniu w dół.
- Obraz ma przy okazji trzy inne defekty, które zadanie 004 usuwa niezależnie od wyboru serwera:
  `composer install` **bez** `--no-dev` (biblioteki deweloperskie w produkcji), `npm run build || true`
  (nieudane budowanie zasobów przechodzi po cichu, a obraz i tak powstaje) oraz `chown -R` po całym
  drzewie w każdym budowaniu.

Kierunek wdrożenia jest już ustalony w zadaniach 001–003: **Cloud Run**, domeny `fisherya.com`
i `staging.fisherya.com`. Trasa kontroli stanu `/up` istnieje (`bootstrap/app.php`, `health: '/up'`).
Bliźniacze projekty (WorkSnap, PunktySzczepień) stoją na tej platformie na **FrankenPHP w trybie
classic** i ten układ jest w nich sprawdzony w działaniu.

Decyzja wiąże kształt `Dockerfile`, entrypointu produkcyjnego, plików ustawień PHP i przyszłego
potoku wdrożeniowego naraz — odwrócenie oznacza przepisanie wszystkich czterech.

## Alternatywy

### Opcja A — FrankenPHP w trybie classic, `tini` jako proces numer jeden

Jeden binarny serwer (Caddy z wbudowanym PHP), `docker/Caddyfile`, `docker/prod-entrypoint.sh`,
nasłuch na `$PORT`, kontrola stanu na `/up`.

**Zalety:**
- **Natywna obsługa `$PORT`** — dokładnie to, czego wymaga Cloud Run; bez podstawiania zmiennych
  w plikach konfiguracyjnych przy starcie.
- **Jeden proces, wiele wątków.** Model dopasowany do instancji skalowanej do zera: brak menedżera
  procesów potomnych, mniejszy ślad pamięciowy, krótszy zimny start.
- **Poprawna propagacja `SIGTERM`** przez `tini` — Cloud Run wysyła ten sygnał przy skalowaniu w dół,
  a instancja ma dokończyć żądania w locie zamiast je urwać.
- **HTTP/2 i HTTP/3 oraz obsługa statycznych zasobów** w tym samym procesie, bez drugiego serwera.
- **Zgodność z bliźniaczymi projektami** — ten sam kształt obrazu, entrypointu i `Caddyfile`;
  doświadczenie i poprawki przenoszą się między repozytoriami w obie strony.
- Otwiera drogę do trybu worker (Octane) bez zmiany serwera, gdyby wydajność tego wymagała.

**Wady:**
- **Nowa technologia w tym repozytorium** — inny plik konfiguracyjny (`Caddyfile`), inny model
  diagnostyki niż znany zespołowi Apache.
- Ekosystem mniejszy niż Apache/nginx; przy nietypowym problemie mniej gotowych odpowiedzi.
- Zachowania zależne od trybu (classic vs worker) bywają mylące — łatwo przypisać serwerowi problem
  wynikający z trybu.

### Opcja B — pozostanie przy Apache, doprowadzonym do zgodności z Cloud Run

Zostaje `php:8.3-apache`; entrypoint podstawia `$PORT` do `ports.conf` i `vhost.conf`, dochodzi
`tini` albo `exec` w entrypoincie dla poprawnej propagacji sygnałów.

**Zalety:**
- **Zero nowej technologii** — konfiguracja Apache'a jest w repozytorium i jest znana.
- Najmniejsza zmiana względem stanu obecnego; `.docker/vhost.conf` da się przenieść niemal wprost.
- Bardzo dojrzały ekosystem i dokumentacja.

**Wady:**
- **Model wieloprocesowy jest niedopasowany do platformy.** Menedżer procesów potomnych na instancji,
  która sama jest jednostką skalowania, to zdublowana warstwa — kosztuje pamięć i wydłuża zimny start.
- **`$PORT` trzeba obsłużyć podstawianiem tekstu w plikach konfiguracyjnych** przy każdym starcie;
  to działa, ale jest kruche i wymaga własnego testu.
- **Rozjazd z bliźniaczymi projektami zostaje na stałe** — każda poprawka obrazu robi się dwa razy,
  osobno dla dwóch układów.
- Zadanie 004 i tak przepisuje `Dockerfile` w całości, więc „mniejsza zmiana" jest złudna: różnica
  sprowadza się do tego, który plik konfiguracyjny piszemy od nowa.

### Opcja C — nginx + PHP-FPM w jednym kontenerze

Klasyczny układ produkcyjny, dwa procesy pod nadzorcą (`supervisord`), nginx nasłuchujący na `$PORT`.

**Zalety:**
- Najpowszechniejszy układ produkcyjny dla PHP — najwięcej materiałów i gotowych konfiguracji.
- Wyraźny rozdział serwera HTTP od wykonawcy PHP; osobne strojenie każdej warstwy.
- Dobrze udokumentowane zachowanie pod obciążeniem.

**Wady:**
- **Dwa procesy plus nadzorca w kontenerze bezstanowym** — Cloud Run zakłada jeden proces; nadzorca
  przejmuje `PID 1` i **propagacja `SIGTERM` do PHP-FPM staje się osobnym problemem do rozwiązania**.
- **Największa złożoność z trzech opcji**: `supervisord.conf` + konfiguracja nginx + konfiguracja
  `php-fpm`, wszystko z `$PORT` podstawianym w entrypoincie.
- Najdłuższy zimny start — dwa procesy do wystartowania, gniazdo między nimi do ustanowienia.
- Także rozjeżdża się z bliźniaczymi projektami, bez rekompensaty w postaci znajomości układu
  (nginx nie jest dziś w tym repozytorium używany, więc to również nowa technologia — tylko droższa).

## Rekomendacja

**Opcja A — FrankenPHP w trybie classic.**

Trzy argumenty przesądzają:

1. **Dopasowanie do platformy jest tu kryterium technicznym, nie estetycznym.** `$PORT`, jeden proces
   i czysta propagacja `SIGTERM` to trzy twarde wymagania Cloud Run. Opcja A spełnia je natywnie;
   opcje B i C spełniają je **przez obejścia w entrypoincie**, z których każde jest kolejnym miejscem
   do zepsucia i przetestowania.
2. **„Mniejsza zmiana" w opcji B jest pozorna.** Zadanie 004 i tak zastępuje oba pliki jednym
   wieloetapowym `Dockerfile` i usuwa `.docker/` w całości. Nie wybieramy więc między „zostawić
   a przepisać", tylko między dwoma sposobami napisania obrazu od nowa — a przy równym koszcie
   wygrywa układ zgodny z platformą.
3. **Zgodność z bliźniaczymi projektami ma wymierną wartość.** WorkSnap i PunktySzczepień mają ten
   układ przetestowany na tej samej platformie; przeniesienie go tutaj oznacza, że diagnostyka,
   poprawki i wiedza o pułapkach są wspólne dla trzech repozytoriów zamiast rozjeżdżać się na dwa
   warianty.

**Świadome odroczenie:** rekomendacja obejmuje **wyłącznie tryb classic**, nie tryb worker (Octane).
Tryb worker trzyma aplikację w pamięci między żądaniami i wymaga przeglądu stanu współdzielonego
(statyczne właściwości, singletony, kontener usług) — to osobne zadanie z własnym sprawdzeniem,
nie rzecz do dołożenia przy przebudowie środowiska. Przejście z classic na worker nie wymaga zmiany
serwera ani obrazu, więc nic tu nie zamykamy.

**Zależność, o której musi wiedzieć implementacja:** entrypoint produkcyjny wykonuje `config:cache`,
`route:cache` i `view:cache` — to unieszkodliwia `env()` w kodzie aplikacji. **Zadanie 001 musi być
zrealizowane wcześniej albo równolegle**, inaczej pierwsze wdrożenie po tej zmianie po cichu zepsuje
`CSOService` i adres administratora. Ta zależność obowiązuje niezależnie od wybranej opcji — dotyczy
zapisywania konfiguracji do pamięci podręcznej, nie serwera.

## Decyzja

**Opcja A — cel `prod` stoi na FrankenPHP w trybie classic, z `tini` jako procesem numer jeden.**
Decyzja podjęta przez autora zadania 2026-08-15.

Konsekwencje wiążące implementację zadania 004:

- **Apache znika z obu celów.** `Dockerfile.dev` i `Dockerfile.prod` zastępuje jeden wieloetapowy
  `Dockerfile` (`base` → `vendor` → `assets` → `dev` / `prod`); `.docker/vhost.conf` wraz z całym
  katalogiem `.docker/` zostaje usunięty, a pliki pomocnicze trafiają do `docker/` (bez kropki).
- **Powstają:** `docker/Caddyfile`, `docker/prod-entrypoint.sh` oraz pliki ustawień PHP dla produkcji
  (`opcache` z `validate_timestamps=0`).
- **Nasłuch na wstrzykiwanym `$PORT`**, kontrola stanu na istniejącej trasie `/up`
  (`bootstrap/app.php`, `health: '/up'`). Żadnego portu wpisanego na sztywno.
- **`tini` jako `PID 1`** — poprawna propagacja `SIGTERM` przy skalowaniu w dół na Cloud Run.
- **Zasoby front-endu i zależności PHP wchodzą z etapów `assets` i `vendor`**, nie są budowane
  w warstwie produkcyjnej. Przy okazji znikają trzy defekty dzisiejszego obrazu: `composer install`
  bez `--no-dev`, `npm run build || true` (maskujące nieudane budowanie zasobów) oraz `chown -R`
  po całym drzewie.
- **Wyłącznie tryb classic.** Tryb worker (Octane) jest świadomie odroczony — wymaga przeglądu stanu
  współdzielonego między żądaniami i jest osobnym zadaniem. Przejście na worker nie wymaga zmiany
  serwera ani obrazu, więc nic nie zostaje tu zamknięte.
- **Zależność od zadania 001 obowiązuje:** entrypoint produkcyjny wykonuje `config:cache`,
  `route:cache` i `view:cache`, co unieszkodliwia `env()` w kodzie aplikacji. Bez zrealizowanego
  zadania 001 pierwsze wdrożenie po tej zmianie po cichu zepsuje `CSOService` i adres administratora.
- **Wdrożenie pozostaje poza zakresem** — zadanie 004 dostarcza obraz zdolny działać na Cloud Run,
  nie potok wdrożeniowy ani konfigurację platformy.
