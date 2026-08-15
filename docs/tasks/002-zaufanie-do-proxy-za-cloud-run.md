# 002 — Zaufanie do proxy (`trustProxies`) dla wdrożenia za Cloud Run

## Opis problemu

`bootstrap/app.php` konfiguruje w `withMiddleware()` wyłącznie alias `two_factor` — nie ma
wywołania `trustProxies()`. Aplikacja działa dziś lokalnie (Apache w kontenerze, ruch bez
terminacji TLS przed aplikacją), więc brak ten jest niewidoczny.

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
  zabezpieczeniem tej konfiguracji (patrz otwarte pytanie niżej).

## Kryteria akceptacji

- [ ] `bootstrap/app.php` zawiera `trustProxies` wewnątrz `withMiddleware()` **z jawną maską
      `headers:`** (bez `HEADER_X_FORWARDED_HOST` i `HEADER_X_FORWARDED_PREFIX`).
- [ ] Żądanie z nagłówkiem `X-Forwarded-Proto: https` daje `$request->isSecure() === true`
      oraz `url()` / `asset()` ze schematem `https://` — pokryte testem.
- [ ] `$request->ip()` odczytuje wartość z `X-Forwarded-For` zamiast adresu proxy — pokryte testem.
- [ ] **`X-Forwarded-Host: evil.tld` NIE zmienia adresów absolutnych** (`url()`, `asset()`) —
      pokryte testem.
- [ ] **`X-Forwarded-Host: evil.tld` NIE zmienia adresów podpisanych** (`URL::signedRoute`,
      `hasValidSignature`) — pokryte testem; to jest ten przypadek, w którym podpis nie chroni.
- [ ] **Weryfikacja negatywna wykonana i opisana**: po tymczasowym cofnięciu maski dwa testy
      od `X-Forwarded-Host` **padają**, a test od `X-Forwarded-Proto` **zostaje zielony**.
      Bez tego kroku nie wiadomo, czy testy w ogóle badają to, co mają badać.
- [ ] **Pełny pakiet testów (`docker compose exec app php artisan test`) jest zielony** — tier T3, patrz niżej.

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

- [ ] `docs/conventions/` — bez nowego pliku; niezmiennik jest platformowy, nie należy do żadnej
      powierzchni aplikacji z tabeli w `CLAUDE.md`
- [ ] `docs/operations/obraz-produkcyjny.md` — akapit o wdrożeniu za proxy terminującym TLS:
      dlaczego `trustProxies` jest wymagane, jaki jest objaw jego braku (mixed content, panel bez
      styli) **oraz dlaczego maska nagłówków jest jawna** (odsyłacz do analizy WorkSnapa)
- [ ] `docs/security/` — założyć katalog i przenieść tu notatkę o tym, że wzorzec z bliźniaczych
      projektów był podatny; to samo miejsce przyda się przy kolejnych znaleziskach
      z `/security-audit`
- [ ] `README.md` — bez zmian
- [ ] `CLAUDE.md` — bez zmian
- [ ] `CHANGELOG.md` — wpis w changelogu

## Ograniczenia techniczne

- Laravel 12 — konfiguracja proxy żyje w `bootstrap/app.php`, nie w klasie
  `App\Http\Middleware\TrustProxies` (ten wzorzec zniknął po Laravelu 10; nie przywracaj go).
- Docelowe środowisko: Cloud Run w `europe-west1`, TLS terminowany przez front Google, adresy
  proxy **nie są stałą pulą** — konfiguracja musi działać bez listy adresów IP.
- Środowisko lokalne (Docker Compose, Apache na porcie 8000, bez TLS) musi działać bez zmian.

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

- **`at: '*'` czy lista adresów?** `'*'` oznacza „ufaj nagłówkom `X-Forwarded-*` od kogokolwiek",
  co jest bezpieczne **tylko dlatego**, że na Cloud Run do kontenera nie da się dostać z pominięciem
  frontu Google. Alternatywa (lista zakresów) jest przy Cloud Run niewykonalna — adresy nie są
  stałe. Decyzja wiąże kod poza tym zadaniem i jej uzasadnienie nie wynika z samego kodu, więc jest
  kandydatem na ADR; obie bliźniacze aplikacje (WorkSnap, PunktySzczepień) używają `'*'`.
  ⚠️ **Audyt WorkSnapa (2026-08-15) rozstrzygnął część tego pytania:** samo `at: '*'` jest
  akceptowalne, ale **wyłącznie razem z jawną maską `headers:`** — to maska, a nie zakres adresów,
  jest tu realnym zabezpieczeniem. ADR (jeśli powstanie) powinien utrwalić **oba** elementy razem,
  bo rozdzielone tworzą dokładnie tę podatność.
- **Czy zweryfikowano, że problem realnie występuje?** Zadanie ma sens tylko wtedy, gdy aplikacja
  faktycznie stanie za terminacją TLS. Dziś: `docs/operations/obraz-produkcyjny.md` i zadania
  001–003 zakładają Cloud Run (`fisherya.com` / `staging.fisherya.com`), a obraz `prod` już
  istnieje. Aplikacja **nie działa jeszcze produkcyjnie**, więc jest to przygotowanie przed
  pierwszym wdrożeniem, nie naprawa działającego systemu — warto to potwierdzić przy `/review-task`.
- Czy w konsekwencji tej zmiany aplikacja powinna przestać być uruchamialna bez proxy
  terminującego TLS (np. wymuszenie `https` w produkcji), czy zostawiamy to konfiguracji środowiska?
