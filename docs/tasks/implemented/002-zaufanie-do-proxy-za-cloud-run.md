# 002 — Zaufanie do proxy (`trustProxies`) dla wdrożenia za Cloud Run

## Opis problemu

`bootstrap/app.php` konfiguruje w `withMiddleware()` wyłącznie alias `two_factor` — nie ma
wywołania `trustProxies()`. Lokalnie ruch nie przechodzi przez proxy terminujące TLS
(`artisan serve` w kontenerze, `http://localhost:11000`), więc brak ten jest niewidoczny.

Na Cloud Run TLS kończy się na warstwie proxy Google, a do kontenera trafia zwykły HTTP —
z nagłówkami `X-Forwarded-Proto: https` i `X-Forwarded-For`. Bez zaufanego proxy Laravel widzi
połączenie jako `http` i:

- generuje adresy zasobów (`asset()`, `url()`, Vite, Filament) ze schematem `http://` na stronie
  serwowanej po `https://` → przeglądarka blokuje je jako **mixed content** → panele Filamenta
  renderują się **bez styli i bez JS**;
- adresy w mailach transakcyjnych (weryfikacja adresu e-mail, reset hasła, kod dwuskładnikowy)
  wychodzą jako `http://`;
- `$request->ip()` zwraca adres proxy, nie użytkownika — dotyczy m.in. limitowania prób logowania
  i wpisów `spatie/laravel-activitylog`.

Jest to udokumentowana pułapka platformy, na której stoi wdrożenie: trafiły w nią kolejno WorkSnap
i PunktySzczepień (patrz `docs/operations/worksnap-onboarding.md` i sekcja „gotchas" w repozytorium
`gcp-foundation`). Naprawa przed pierwszym wdrożeniem kosztuje jedną linijkę; diagnozowanie
„aplikacja wdrożona, ale bez styli" kosztuje sesję.

### ⚠️ Druga pułapka: naiwna naprawa tworzy podatność (audyt WorkSnapa, 2026-08-15)

**Nie kopiuj tej linijki z sąsiedniego projektu.** Audyt bezpieczeństwa WorkSnapa wykrył, że
`trustProxies(at: '*')` wywołane **bez argumentu `headers:`** przyjmuje domyślną maskę Laravela,
a ta zawiera **`X-Forwarded-Host`**. Przy `at: '*'` każdy klient jest zaufanym proxy, więc
**dowolny klient dyktuje host**, z którego Laravel buduje adresy absolutne. Skutek (waga: High,
CWE-644):

- Linki weryfikacji e-maila i resetu hasła powstają **z hosta żądania**, nie z `APP_URL` (`APP_URL`
  działa tylko w kontekście konsoli). Atakujący zamawia reset dla ofiary z podrobionym nagłówkiem,
  a ofiara dostaje **autentyczny, podpisany DKIM mail z prawdziwej domeny**, którego link oddaje
  token atakującemu.
- **Podpis URL-a nie chroni**: sygnatura liczona jest z `$request->url()`, które czyta ten sam
  podrobiony nagłówek — więc waliduje się poprawnie.
- W WorkSnapie potwierdzone empirycznie: żądanie z `X-Forwarded-Host: evil.tld` wyrenderowało
  w stronie `http://evil.tld/icon-512.png`.

Pełna analiza (osobne repozytorium):
`WorkSnap/docs/security/2026-08-15-zatrucie-hosta-i-obejscie-2fa-na-admin.md`.

**Stan Łowisk:** dziś **nie jesteśmy podatni** — w `bootstrap/`, `app/`, `config/` i `docker/` nie
ma w ogóle `trustProxies` ani `trustHosts`, więc Laravel nie ufa żadnemu proxy i `X-Forwarded-Host`
jest ignorowany. Podatność powstałaby **dopiero przy realizacji tego zadania**, gdyby wykonać je
naiwnie. Dlatego wymaganie niżej mówi o **jawnej masce**, a nie o gołym `at: '*'`.

## Wymagania

- W `bootstrap/app.php`, w bloku `withMiddleware()`, dodać `trustProxies` **z jawną maską
  nagłówków** — bez naruszania istniejącej rejestracji aliasu `two_factor`:

  ```php
  use Illuminate\Http\Request;

  $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR
      | Request::HEADER_X_FORWARDED_PORT
      | Request::HEADER_X_FORWARDED_PROTO);
  ```

  ⚠️ **Argument `headers:` jest obowiązkowy, nie opcjonalny.** Maska zachowuje wykrywanie HTTPS
  za proxy (jedyny powód, dla którego `trustProxies` jest tu potrzebne) i odcina
  `X-Forwarded-Host` oraz `X-Forwarded-Prefix`. Pominięcie go wprowadza podatność opisaną wyżej.
- Dopisać przy tej linii komentarz wyjaśniający, **dlaczego maska jest jawna** — w WorkSnapie
  komentarz uzasadniał wyłącznie `X-Forwarded-Proto`, a kod ufał całej domyślnej masce; rozjazd
  między komentarzem a zachowaniem był tam bezpośrednią przyczyną podatności.
- Zachować dotychczasowe zachowanie w środowisku lokalnym i testowym (żaden istniejący test nie
  może zmienić wyniku).
- Odnotować w dokumentacji operacyjnej, dlaczego zaufanie jest ustawione na `*` i co jest realnym
  zabezpieczeniem tej konfiguracji — uzasadnienie w [ADR-003](../../adr/ADR-003-zaufanie-do-proxy-i-maska-naglowkow.md),
  w dokumentacji wystarczy niezmiennik plus odsyłacz.
- Test regresyjny w `tests/Feature/TrustedProxyHeadersTest.php` (nazwa wiążąca — patrz
  „Rozstrzygnięcia"), obejmujący cztery przypadki z kryteriów akceptacji.

## Kryteria akceptacji

- [x] `bootstrap/app.php` zawiera `trustProxies` wewnątrz `withMiddleware()` **z jawną maską
      `headers:`** (bez `HEADER_X_FORWARDED_HOST` i `HEADER_X_FORWARDED_PREFIX`).
- [x] Żądanie z nagłówkiem `X-Forwarded-Proto: https` daje `$request->isSecure() === true`
      oraz `url()` / `asset()` ze schematem `https://` — pokryte testem.
- [x] `$request->ip()` odczytuje wartość z `X-Forwarded-For` zamiast adresu proxy — pokryte testem.
- [x] **`X-Forwarded-Host: evil.tld` NIE zmienia adresów absolutnych** (`url()`, `asset()`) —
      pokryte testem.
- [x] **`X-Forwarded-Host: evil.tld` NIE zmienia adresów podpisanych** — pokryte testem przez
      rzeczywisty link weryfikacji e-maila (`VerifyEmail`), nie przez syntetyczny `signedRoute`;
      to jest ten przypadek, w którym podpis nie chroni.
- [x] **Weryfikacja negatywna wykonana i opisana**: po tymczasowym dopisaniu
      `Request::HEADER_X_FORWARDED_HOST` do maski dwa testy od `X-Forwarded-Host` **padły**
      (`url('/')` zwróciło `http://evil.tld`, link weryfikacji zawierał `evil.tld`), a test od
      `X-Forwarded-Proto` **pozostał zielony**. Opisane w
      `docs/security/2026-08-15-trustproxies-bez-maski-naglowkow.md`.
- [x] **Pełny pakiet testów (`docker compose exec app php artisan test`) jest zielony** —
      **z zastrzeżeniem odziedziczonym z zadania 004**: 53 przeszły, 3 czerwone (`AdminPanelTest`,
      `OwnerPanelTest` — przyczyna: locale Filamenta, niezwiązana z tym zadaniem). Zero nowych
      regresji; wszystkie 4 testy tego zadania zielone.

## Zakres testów

- **Tier:** T3 — pełny pakiet
- **Uruchamiamy:** `docker compose exec app php artisan test`
- **Uzasadnienie:** zadanie zmienia `bootstrap/app.php`, który jest na **liście obowiązkowych
  wyzwalaczy T3** w `CLAUDE.md` (w Laravelu 12 mieszkają tam middleware, routing i obsługa
  wyjątków). T3 **nie jest tu przedmiotem wyboru** — middleware zaufanego proxy siada na każdym
  żądaniu, więc każda ścieżka funkcjonalna w pakiecie jest ścieżką dotkniętą. Dodatkowo zadanie
  dokłada własny test na przepisywanie schematu i adresu IP.

## Zakres wyłączeń

- **Nie** zmieniamy pozostałej konfiguracji `withMiddleware()` (alias `two_factor` zostaje).
- **Nie** wprowadzamy wymuszania HTTPS (`URL::forceScheme`, przekierowanie 301) — to inne
  rozwiązanie tego samego objawu i, przy poprawnym `trustProxies`, zbędne.
- **Nie** zmieniamy `APP_URL` ani konfiguracji wdrożenia — to należy do konfiguracji środowiska,
  nie do repozytorium.
- **Nie** dotykamy nagłówków bezpieczeństwa serwera — po zadaniu 004 mieszkają w `docker/Caddyfile`
  (obraz produkcyjny stoi na FrankenPHP; `.docker/vhost.conf` i Apache już nie istnieją).
- **Nie** dodajemy `trustHosts(at: [...])` jako drugiej warstwy. Rozważone i świadomie odrzucone
  w WorkSnapie: lista hostów produkcyjnych wywraca środowisko lokalne i pakiet testów (hosty
  `localhost:8000`, `lowiska-app:8000`), a **jawna maska nagłówków zamyka podatność samodzielnie**.
  Jeśli kiedyś wracać — z listą zależną od środowiska, osobnym zadaniem.

## Zmiany dokumentacji

- [x] `docs/conventions/` — bez nowego pliku; niezmiennik jest platformowy, nie należy do żadnej
      powierzchni aplikacji z tabeli w `CLAUDE.md`
- [x] `docs/operations/obraz-produkcyjny.md` — nowa sekcja 7: dlaczego `trustProxies` jest
      wymagane, objaw jego braku, dlaczego maska nagłówków jest jawna, odsyłacze do ADR-003
      i notatki w `docs/security/`
- [x] `docs/security/` — założony; `2026-08-15-trustproxies-bez-maski-naglowkow.md` opisuje
      mechanizm, dlaczego wzorzec trafił do treści zadania, naprawę i wynik weryfikacji negatywnej
- [x] `README.md` — bez zmian
- [x] `CLAUDE.md` — bez zmian
- [x] `CHANGELOG.md` — wpis w sekcji „Bezpieczeństwo"

## Ograniczenia techniczne

- Laravel 12 — konfiguracja proxy żyje w `bootstrap/app.php`, nie w klasie
  `App\Http\Middleware\TrustProxies` (ten wzorzec zniknął po Laravelu 10; nie przywracaj go).
- Docelowe środowisko: Cloud Run w `europe-west1`, TLS terminowany przez front Google, adresy
  proxy **nie są stałą pulą** — konfiguracja musi działać bez listy adresów IP.
- Środowisko lokalne (Docker Compose, `artisan serve` w kontenerze, host `localhost:11000`, bez TLS)
  musi działać bez zmian — po zadaniu 004 Apache nie występuje w żadnym z celów obrazu.
- Pakiet testów biegnie na MySQL-u w schemacie `lowiska_test` (ADR-001), a bramka
  w `tests/TestCase.php` przerywa przebieg wskazujący inną bazę — nowy test musi to respektować
  (zwykły test funkcjonalny, bez własnego połączenia).

## Rozstrzygnięcia

<!-- Punktowe decyzje ustalone przy /review-task: wygląd, copy, próg, nazwa, umiejscowienie.
     Wiążą implementację tak samo jak decyzje z ADR-ów, ale nie mają zasięgu poza zadaniem.
     Format: decyzja + jednozdaniowe uzasadnienie. -->
- **Nazwa klasy testowej: `tests/Feature/TrustedProxyHeadersTest.php`** — ta sama co w WorkSnapie,
  żeby przy porównywaniu obu repozytoriów nie trzeba było szukać odpowiednika.
- **Powstaje katalog `docs/security/`** i trafia do niego notatka o tym, że wzorzec przejęty
  z bliźniaczych projektów był podatny. Powód: rejestr znalezisk bezpieczeństwa ma mieć jedno
  miejsce, osobne od instrukcji obsługi w `docs/operations/`; przyda się przy kolejnych przebiegach
  `/security-audit`.
- **Weryfikacja negatywna jest częścią zadania, nie sugestią** — jej wynik ma zostać opisany
  w notatce w `docs/security/`, żeby przy następnej zmianie w tym miejscu było wiadomo, że testy
  faktycznie badają to, co deklarują.
- **Brak drugiego składnika w panelu `/owner` NIE wchodzi do tego zadania.** To osobna powierzchnia
  (providery paneli) i osobny problem, wykryty przy okazji tego przeglądu; odłożony do upgrade'u
  na Laravel 13 + najnowszego Filamenta, gdzie MFA jest częścią pakietu. Odnotowane w
  [`TODO.md`](../../../TODO.md).

## Powiązane ADR-y

<!-- Numery ADR-ów podjętych dla tego zadania (uzupełnia /review-task).
     Tylko decyzje spełniające trzyskładnikowe kryterium z CLAUDE.md —
     reszta idzie do „Rozstrzygnięcia" powyżej. -->
- [**ADR-003 — Zaufanie do proxy: `at: '*'` wyłącznie z jawną maską nagłówków**](../../adr/ADR-003-zaufanie-do-proxy-i-maska-naglowkow.md)
  — **status: accepted.** Opcja A (`at: '*'` + maska `HEADER_X_FORWARDED_FOR | _PORT | _PROTO`)
  wdrożona w `bootstrap/app.php`.

## Otwarte pytania — zamknięte przy `/review-task` (2026-08-15)

- ~~**`at: '*'` czy lista adresów?**~~ → **[ADR-003](../../adr/ADR-003-zaufanie-do-proxy-i-maska-naglowkow.md)**.
  Rozstrzygnięcie wymaga zapisania **razem z maską nagłówków**, bo to maska — a nie zakres adresów —
  jest tu realnym zabezpieczeniem; sama lista IP nie usunęłaby zaufania do `X-Forwarded-Host`.
- ~~**Czy problem realnie występuje?**~~ → **Tak, ale jako przygotowanie, nie naprawa.** Aplikacja
  nie działa jeszcze produkcyjnie, natomiast obraz `prod` (FrankenPHP, ADR-002) istnieje, a zadania
  001–003 zakładają Cloud Run z domenami `fisherya.com` / `staging.fisherya.com`. Zmiana ma być
  gotowa **przed** pierwszym wdrożeniem — po nim objawem jest panel bez styli.
- ~~**Czy wymuszać HTTPS w produkcji?**~~ → **Nie w tym zadaniu.** Pytanie było już odpowiedziane
  w „Zakresie wyłączeń": `URL::forceScheme` i przekierowanie 301 to inne rozwiązanie tego samego
  objawu, przy poprawnym `trustProxies` zbędne. Zostaje konfiguracji środowiska.
