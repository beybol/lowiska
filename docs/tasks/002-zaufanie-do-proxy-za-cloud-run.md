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

## Wymagania

- W `bootstrap/app.php`, w bloku `withMiddleware()`, dodać `$middleware->trustProxies(at: '*');`
  bez naruszania istniejącej rejestracji aliasu `two_factor`.
- Zachować dotychczasowe zachowanie w środowisku lokalnym i testowym (żaden istniejący test nie
  może zmienić wyniku).
- Odnotować w dokumentacji operacyjnej, dlaczego zaufanie jest ustawione na `*` i co jest realnym
  zabezpieczeniem tej konfiguracji (patrz otwarte pytanie niżej).

## Kryteria akceptacji

- [ ] `bootstrap/app.php` zawiera `trustProxies` wewnątrz `withMiddleware()`.
- [ ] Żądanie z nagłówkiem `X-Forwarded-Proto: https` daje `$request->isSecure() === true`
      oraz `url()` / `asset()` ze schematem `https://` — pokryte testem.
- [ ] `$request->ip()` odczytuje wartość z `X-Forwarded-For` zamiast adresu proxy — pokryte testem.
- [ ] **Pełny pakiet testów (`./test.sh`) jest zielony** — tier T3, patrz niżej.

## Zakres testów

- **Tier:** T3 — pełny pakiet
- **Uruchamiamy:** `./test.sh`
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
- **Nie** dotykamy nagłówków bezpieczeństwa w `.docker/vhost.conf`.

## Zmiany dokumentacji

- [ ] `docs/conventions/` — bez nowego pliku; niezmiennik jest platformowy, nie należy do żadnej
      powierzchni aplikacji z tabeli w `CLAUDE.md`
- [ ] `docs/operations/docker.md` — akapit o wdrożeniu za proxy terminującym TLS: dlaczego
      `trustProxies` jest wymagane i jaki jest objaw jego braku (mixed content, panel bez styli)
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
- Czy w konsekwencji tej zmiany aplikacja powinna przestać być uruchamialna bez proxy
  terminującego TLS (np. wymuszenie `https` w produkcji), czy zostawiamy to konfiguracji środowiska?
