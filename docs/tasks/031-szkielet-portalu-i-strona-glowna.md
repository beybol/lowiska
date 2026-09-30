# 031 — Szkielet portalu, strona główna i strony statyczne

> **Etap 1, zadanie 4** ([roadmapa](../project/ROADMAPA.md)). Założone 30.09.2026.
> Specyfikacja: [portal-v3](../project/mockups/portal-v3/README.md) §1, §1.1, §5, §6.1, §8;
> makiety: [`portal-v3.html`](../project/mockups/portal-v3/portal-v3.html) — „Strona główna",
> „Dla łowisk", „Adresy stron", „Wersja mobilna". Wymaga 029 (Tailwind 4) i 030 (slug, publikacja).

## Opis problemu

Aplikacja nie ma publicznej części dla wędkarza — pod `/` stoi powitanie Breeze. Portal potrzebuje
szkieletu, na którym staną strona łowiska (032) i kalendarz (033): układu z nawigacją i stopką,
dwóch języków z **różnymi adresami stron w każdym języku**, schematu adresów łowiska, strony głównej
z listą łowisk, landingu dla łowisk, stron statycznych (na razie z treścią zastępczą), faviconu,
podstaw SEO i statystyk odwiedzin (Umami).

## Wymagania

### Układ i język
- Układ portalu (Blade + wejście stylów portalu z 029) z nagłówkiem i stopką wg §1.1 i makiety:
  „Łowiska", „Jak to działa", „Dla łowisk", przełącznik PL · EN; stopka w pięciu grupach ze zdaniem
  „Oferty w portalu pochodzą od łowisk…". Wersja mobilna: znak i menu ☰, stopka w dwóch kolumnach.
- **Prefiks języka w każdym adresie** (`/pl/…`, `/en/…`); `/` → 302 na wersję z cookie, bez niego
  z nagłówka przeglądarki, domyślnie PL. Wybór języka ma **jedną implementację**, wspólną dla `/`
  i krótkiego adresu łowiska `/{slug}` (Rozstrzygnięcie 6); middleware języka portalu podpięty na **grupie tras w `routes/`**.
- **Adresy stron statycznych zależne od języka** (treści będą pisane osobno, nie tłumaczone):

  | Strona | PL | EN |
  |---|---|---|
  | Dla łowisk (landing) | `/pl/dla-lowisk` | `/en/for-fisheries` |
  | Jak to działa | `/pl/jak-to-dziala` | `/en/how-it-works` |
  | Jak układamy listę | `/pl/jak-ukladamy-liste` | `/en/listing-rules` |
  | Kontakt | `/pl/kontakt` | `/en/contact` |
  | Regulamin portalu | `/pl/dokumenty-prawne/regulamin` | `/en/legal/terms` |
  | Polityka prywatności | `/pl/dokumenty-prawne/polityka-prywatnosci` | `/en/legal/privacy` |
  | Pliki cookie | `/pl/dokumenty-prawne/pliki-cookie` | `/en/legal/cookies` |
  | Zgłoś nielegalną treść (DSA) | `/pl/dokumenty-prawne/zglos-nielegalna-tresc` | `/en/legal/report-content` |

  Przełącznik języka prowadzi na **odpowiednik bieżącej strony** w drugim języku (ta sama nazwa
  trasy, inny slug), nie na stronę główną. Slugi są zdefiniowane w jednym miejscu.
- Interfejs z plików `lang/` (PL i EN); treści operatora jednojęzyczne (D8).

### Adresy łowiska (§6.1)
- Trasy łowiska: canonical `/{język}/{slug-województwa}/{slug}`, 301 przy złym segmencie
  województwa, 404 dla nieznanego sluga i dla łowiska nieopublikowanego.
  `/{język}/{slug-województwa}` → 302 na stronę główną.
- **Krótki adres wprost pod domeną: `/{slug}`** (ADR-021, opcja B) → 302 na canonical w języku
  użytkownika; adres z wielkimi literami → 301 na wersję małymi literami; nieznany slug → 404 portalu.
  Obsługuje go **`Route::fallback()`**, nie trasa `/{slug}` w `routes/web.php` — fallback jest zawsze
  ostatni, niezależnie od kolejności rejestracji tras pakietów (Filament, Livewire, Breeze). Bez
  drugiego wejścia `/l/{slug}`.
- **Ochrona sluga łowiska przed kolizją z trasami** — jedna metoda „czy slug jest wolny" w `PortalSlugs`,
  wołana przy nadawaniu automatycznym, przy ręcznej zmianie przez admina i przez `portal:check-slugs`:
  1. kształt: `PortalSlugs::PATTERN` (bez kropki — pliki w `public/` są bezpieczne) **i minimum 3 znaki**
     (kody języków są bezpieczne);
  2. **lista nazw zastrzeżonych na sztywno**: katalogi `public/` (`build`, `css`, `js`, `fonts`, `images`,
     `storage`, `vendor`), panele (`admin`, `owner`), trasy systemowe i uwierzytelniania (`api`, `login`,
     `logout`, `register`, `verify`, `verify-email`, `password`, `forgot-password`, `reset-password`,
     `confirm-password`, `profile`, `auth`, `dashboard`, `up`, `livewire`, `filament`) oraz rezerwa
     (`app`, `www`, `mail`, `static`, `assets`);
  3. **pierwsze segmenty zarejestrowanych tras** (`Route::getRoutes()`, nie parsowanie `routes/web.php` —
     np. prefiks Livewire z haszem `livewire-4b1cb1fa` zmienia się między wersjami).
  Nadawanie automatyczne przy kolizji → **sufiks** (`admin-2`); ręczna zmiana → **błąd walidacji**
  z informacją, że adres jest zajęty przez aplikację.
- **Komenda `portal:check-slugs`** — sprawdza wszystkie slugi łowisk w bazie tą samą metodą i wypisuje
  kolizje; w `deploy.yml` uruchamiana po migracjach (obok `admins:sync`) **bez zatrzymywania
  wdrożenia** (kod wyjścia 0, ostrzeżenie w logu). Istniejące slugi z 030 przechodzą to sprawdzenie
  w migracji danych tego zadania; kolizja dostaje sufiks.
- W tym zadaniu strona pod adresem kanonicznym to **zaślepka** (nazwa łowiska w układzie portalu);
  treść dostarcza 032.
- **ADR schematu adresów** — [ADR-021](../adr/ADR-021-schemat-adresow-portalu.md), Decyzja: B.
  Trasy stron statycznych rejestrowane **przed** trasą łowiska (ten sam kształt trzech segmentów).

### Strona główna i landing
- **Strona główna** wg makiety: hasło, lista **wszystkich opublikowanych łowisk alfabetycznie**
  (polska kolacja), karta: zdjęcie okładkowe (w tym zadaniu zaślepka z makiety), nazwa, akwen,
  województwo, liczba stanowisk w sprzedaży, no-kill; link „Jak układamy listę". Bez wyszukiwarki,
  mapy, filtrów i banera dla łowisk. **„Cena od" dochodzi w 032, linijka zasad w 033.**
- **Landing „Dla łowisk"** wg makiety (treść z makiety, e-mail i telefon bez formularza).
- Pozostałe strony statyczne — **treść zastępcza** („Treść w przygotowaniu") w układzie portalu.

### Pozostałe
- **Favicon** i ikony aplikacji z `docs/project/design/logo/favicon/` — w portalu **oraz w obu panelach**
  (Rozstrzygnięcie 9): pliki w `public/` (m.in. `favicon.ico`, `favicon.svg`, `apple-touch-icon-180.png`,
  ikony 192/512 i manifest), portal linkuje je w `<head>` układu, a `AdminPanelProvider`
  i `OwnerPanelProvider` dostają `->favicon(asset('favicon.svg'))`. Widoki Breeze — ten sam zestaw
  w `layouts/app` i `layouts/guest`. Dzisiejszy `public/favicon.ico` (Laravel) zostaje zastąpiony.
- **SEO:** `<title>` i opis strony, `link rel="canonical"`, `hreflang` PL/EN, `sitemap.xml` (strony
  statyczne i łowiska opublikowane w obu językach), `robots.txt`; **poza produkcją `noindex`**.
- **Strona 404 portalu** z listą łowisk (Rozstrzygnięcie 7).
- **Umami** — osadzenie skryptu istniejącej instancji: `UMAMI_SCRIPT_URL` i `UMAMI_WEBSITE_ID`
  → `config/services.php` (`services.umami`), skrypt **wyłącznie w układzie portalu** (nie w panelach
  ani w widokach Breeze), **wyłącznie gdy obie wartości są ustawione**. Wdrożenie (Rozstrzygnięcie 4):
  `deploy.yml` przekazuje obie zmienne **tylko dla środowiska produkcyjnego** (`ENV=prod`), jako
  literały — nie sekrety, trafiają jawnie do HTML-a: `UMAMI_SCRIPT_URL=https://umami.eadmin.pl/script.js`,
  `UMAMI_WEBSITE_ID=3fe6e9c6-3a96-48eb-b619-bac7b048131a`. Znacznik:
  `<script defer src="…" data-website-id="…"></script>`. Na stagingu, lokalnie i w testach skrypt się
  nie ładuje.
- **SEO — `robots.txt` dynamiczny** (Rozstrzygnięcie 3): trasa Laravela; na produkcji zezwala
  i podaje `Sitemap: {APP_URL}/sitemap.xml`, poza produkcją `Disallow: /`. Usunięcie
  `public/robots.txt` i wpisu `/robots.txt` z `@static` w `docker/Caddyfile`. Poza produkcją
  dodatkowo `<meta name="robots" content="noindex">` w układzie portalu.
- **Bez banera zgód na pliki cookie** (Rozstrzygnięcie 2); strona „Pliki cookie" (treść zastępcza)
  wymienia ciasteczka techniczne: sesja, CSRF, język.

### Koniec `/dashboard` Breeze (Rozstrzygnięcie 1)
- Trasa `/dashboard` i widok `dashboard.blade.php` znikają razem z `welcome.blade.php`.
- **Po zalogowaniu, weryfikacji adresu, potwierdzeniu hasła, rejestracji, 2FA i logowaniu Google**
  użytkownik trafia do panelu: `is_admin` → `/admin`, pozostali → `/owner`. Regułę niesie **jedna
  metoda** (np. `App\Services\PanelHome::urlFor(User)`), wołana ze wszystkich dzisiejszych wystąpień
  `route('dashboard')`: `AuthenticatedSessionController`, `ConfirmablePasswordController`,
  `EmailVerificationNotificationController`, `EmailVerificationPromptController`,
  `RegisteredUserController`, `VerifyEmailController`, `SocialAuthController`, `TwoFactorController`
  (gałąź domyślna). `redirect()->intended()` zostaje — cel „zamierzony" nadal wygrywa.
- Nawigacja widoków Breeze (`layouts/navigation.blade.php`, logo i „Dashboard") prowadzi do tego
  samego adresu panelu.
- Plik konwencji **`docs/conventions/strona-publiczna.md`** (wskazany w `CLAUDE.md`, nie istnieje)
  z pierwszymi niezmiennikami (§8 specyfikacji + adresy zależne od języka).

## Kryteria akceptacji

- [ ] Wszystkie adresy z tabeli działają w obu językach; przełącznik języka prowadzi na odpowiednik.
- [ ] `/` przekierowuje na wersję językową; schemat adresów łowiska (canonical, 301, krótki adres
      `/{slug}` z 302, wielkie litery → 301, 404 dla nieopublikowanego i nieznanego) pokryty testami
      funkcjonalnymi; panele i logowanie działają obok krótkiego adresu.
- [ ] Slug łowiska nie może zająć nazwy z listy zastrzeżonej ani pierwszego segmentu zarejestrowanej
      trasy, ani mieć mniej niż 3 znaki — nadanie automatyczne dostaje sufiks, ręczna zmiana błąd walidacji.
- [ ] `portal:check-slugs` wypisuje kolizję i kończy się kodem 0; jest wołana w `deploy.yml` po migracjach.
- [ ] Strona główna pokazuje tylko łowiska opublikowane, alfabetycznie.
- [ ] Canonical, `hreflang`, `sitemap.xml`, `robots.txt`; `noindex` poza produkcją.
- [ ] Umami: skrypt obecny w portalu przy ustawionej konfiguracji, nieobecny bez niej, w panelach
      i w widokach Breeze; `deploy.yml` przekazuje zmienne wyłącznie dla produkcji.
- [ ] Favicon widoczny w portalu, w obu panelach i w widokach Breeze.
- [ ] `robots.txt`: produkcja — zezwala i wskazuje `sitemap.xml`; poza produkcją — `Disallow: /`.
- [ ] `/dashboard` nie istnieje (404 portalu); po logowaniu hasłem, przez Google i po 2FA administrator
      trafia do `/admin`, pozostali do `/owner` — testy funkcjonalne każdej ścieżki.
- [ ] **Ręczna weryfikacja** strony głównej, landingu, stron statycznych i 404 na: telefonie
      (390 px — iOS Safari i Android Chrome, realne urządzenia albo emulacja), tablecie i desktopie;
      lista sprawdzonych ekranów i urządzeń w podsumowaniu zadania. Testy przeglądarkowe — zadanie 035.
- [ ] Pełny pakiet testów zielony (T3).

## Zakres testów

- **Tier:** T3 — pełny pakiet
- **Uruchamiamy:** `docker compose exec app php artisan test`
- **Uzasadnienie:** zmiana `docker/Caddyfile` (Rozstrzygnięcie 3), providerów obu paneli (favicon,
  Rozstrzygnięcie 9) i migracja danych slugów (ADR-021) — wyzwalacze T3 z `CLAUDE.md`; do tego
  przekierowania po logowaniu w kontrolerach uwierzytelniania i 2FA (Rozstrzygnięcie 1), na ścieżce
  każdego użytkownika. Middleware języka nadal **na grupie tras w `routes/`**, nie w `bootstrap/app.php`.

## Zakres wyłączeń

- Treść strony łowiska (032), kalendarz (033), zakładki Cennik i Dokumenty (034).
- „Cena od" (032) i linijka zasad na karcie (033).
- Prawdziwe zdjęcia i galeria (036) — w tym zadaniu zaślepki z makiet.
- Ostateczne treści stron statycznych i dokumentów prawnych (TODO-1, TODO-3 — prawnik).
- Formularz kontaktowy.
- Testy przeglądarkowe (035).
- Baner zgód na pliki cookie (Rozstrzygnięcie 2).
- Pozostałe porządki w Breeze (profil, widoki auth) — etap 4, razem z kontami wędkarzy.

## Zmiany dokumentacji

- [x] `docs/conventions/strona-publiczna.md` — **nowy plik**: portal nie liczy ceny ani
      sprzedawalności; adresy stron statycznych zależne od języka; trzeci stan parametrów nie jest
      „nie"; treści operatora jednojęzyczne; tylko łowiska opublikowane; Umami tylko w portalu;
      krótki adres przez `Route::fallback()`, nigdy trasa `/{slug}`; każda nowa trasa pierwszego
      poziomu → wpis na liście zastrzeżonej w `PortalSlugs`.
- [x] `docs/project/mockups/portal-v3/README.md` §1, §6.1 — polskie i angielskie slugi stron statycznych;
      krótki adres `/{slug}` zamiast `/l/{slug}` (ADR-021, opcja B).
- [x] `docs/conventions/panel-admina.md` §11 — slug: minimum 3 znaki, lista zastrzeżona, sprawdzenie
      routera, `portal:check-slugs`.
- [x] `docs/operations/obraz-produkcyjny.md` — zmienne Umami (literały w `deploy.yml`, tylko produkcja)
      i `robots.txt` poza `@static` Caddy'ego; `docs/operations/docker.md`, jeśli opisuje Caddyfile.
- [x] `docs/conventions/autoryzacja.md` §6 — docelowy adres po logowaniu i 2FA (jedna metoda).
- [x] `MANUAL.md` — akapit „Logowanie": dokąd prowadzi logowanie (panel admina / właściciela).
- [x] ADR-021 — link do zadania już jest; po implementacji sekcja „Aktualizacja", jeśli coś odbiegło.
- [x] `CHANGELOG.md` — wpis (publiczny portal: strona główna, strony informacyjne).

## Ograniczenia techniczne

- Tailwind 4 z wejścia portalu (029); bez styli Filamenta w portalu.
- Wielojęzyczność od początku (`CLAUDE.md`, „Język").
- Kod i nazwy tras po angielsku; **slugi adresów** — w języku strony.

## Rozstrzygnięcia

1. **`/dashboard` Breeze znika; po logowaniu — panel wg `is_admin`** (decyzja autora). Administrator
   trafia do `/admin`, pozostali do `/owner`; reguła w jednej metodzie wołanej ze wszystkich ścieżek
   uwierzytelniania. Powód: kont wędkarzy nie ma do etapu 4, a pulpit Breeze był ślepą uliczką.
2. **Bez banera zgód na cookie w etapie 1** — portal ustawia wyłącznie ciasteczka techniczne, a Umami
   działa bez cookie; strona „Pliki cookie" je wymienia. Do potwierdzenia przy TODO-1 (prawnik).
3. **`robots.txt` dynamiczny, trasą Laravela** — tylko tak wskazuje `sitemap.xml` pod adresem
   środowiska i zamyka indeksowanie poza produkcją; plik statyczny i cache „immutable" w Caddy znikają.
4. **Umami kompleksowo, wyłącznie produkcja** (decyzja autora) — instancja `https://umami.eadmin.pl`,
   witryna `3fe6e9c6-3a96-48eb-b619-bac7b048131a`; obie wartości wpisane w `deploy.yml` **tylko
   w gałęzi produkcyjnej** — są publiczne (widać je w HTML-u), więc literał jest prostszy niż
   Variables i nie wymaga ręcznego kroku. Staging ich nie dostaje, więc skrypt się tam nie ładuje.
5. **CSP: projekt nie ma nagłówka Content-Security-Policy** (Caddyfile ustawia `X-Content-Type-Options`,
   `X-Frame-Options`, `Referrer-Policy`) — hosta Umami nie ma gdzie dopisywać. Wprowadzenie CSP to
   osobny temat.
6. **Wybór języka:** cookie `locale` (ustawiane przy każdym wejściu na stronę z prefiksem) →
   `Accept-Language` (`Request::getPreferredLanguage(['pl', 'en'])`, brak dopasowania = PL) → PL.
   Jedna klasa dla `/` i krótkiego adresu `/{slug}`; middleware grupy tras ustawia `App::setLocale()`
   z prefiksu.
7. **404 portalu:** kontrolery portalu zwracają widok 404 portalu jawnie (nieznany slug, łowisko
   nieopublikowane), a nieznane adresy łapie `Route::fallback()` w `routes/web.php` — ten sam, który
   obsługuje krótki adres łowiska (ADR-021) — **bez zmian w `bootstrap/app.php`**. Panele Filamenta
   zostają przy własnej obsłudze błędów.
8. **`sitemap.xml` — trasa Laravela**, generowana przy żądaniu (dwa łowiska; bufor niepotrzebny):
   strony statyczne i opublikowane łowiska w obu językach, z `xhtml:link` `hreflang`.
9. **Favicon także w panelach admina i właściciela** (decyzja autora) — jeden zestaw plików w `public/`
   dla portalu, paneli i widoków Breeze; panele przez `->favicon()` w obu providerach, żeby karta
   przeglądarki pokazywała znak Fisheryi wszędzie, a nie domyślną ikonę Laravela.
10. **Nazwa łowiska ma co najmniej 3 znaki** (decyzja autora, w trakcie implementacji) — z nazwy powstaje
    slug, czyli krótki adres; `Fishery::MIN_NAME_LENGTH`, reguła w polu nazwy formularza (kreator
    i edycja, oba panele).

## Powiązane ADR-y

- [ADR-021 — Schemat adresów portalu wędkarza](../adr/ADR-021-schemat-adresow-portalu.md) — Decyzja: B.
- [ADR-020](../adr/ADR-020-tailwind-portalu-i-potok-zasobow.md) — wejście stylów portalu (Decyzja: A).

## Stan po implementacji (2026-10-01)

Zrealizowane w całości poza ręczną weryfikacją na urządzeniach (kryterium akceptacji — do wykonania
przez autora; w sesji brak przeglądarki). Odnotowane świadomie:

1. **Krótki adres i 404 portalu obsługuje jeden `Route::fallback()`** (`PortalController::fallback()`):
   jeden segment zgodny z wzorcem sluga → łowisko opublikowane → 302; wielkie litery → 301 na małe;
   wszystko inne → 404 portalu. Test pilnuje, że panele, `/login` i `/up` działają obok fallbacku.
2. **Województwo mogło zniknąć z opublikowanego łowiska** (klucz obcy `set null`, zadanie 030) — takie
   łowisko nie ma adresu kanonicznego, więc lista go pomija, a krótki adres daje 404. Blokady usunięcia
   używanego województwa nie dodano (poza zakresem; do rozważenia przy słownikach).
3. **„Akwen" na karcie** składa się z pierwszego rodzaju łowiska i powierzchni („Jezioro rynnowe 16 ha") —
   w danych nie ma osobnej nazwy akwenu („Jezioro Dobre" z makiety). Osobne pole — do decyzji przy 032.
4. **Telefon Fisheryi na landingu** jest opcjonalny (`config/portal.php`, `PORTAL_CONTACT_PHONE`) — numer
   jest „do ustalenia"; bez niego landing pokazuje e-mail `kontakt@fisherya.com`.
5. **Migracja danych slugów** (`2026_10_01_100000_resolve_fishery_slug_collisions_with_routes`) nie
   zmieniła żadnego sluga w bazie deweloperskiej — kolizji nie było.
6. **Relacje `Fishery::state()` i `fisheryTypes()` dostały generyki** — bez nich PHPStan zgłaszał pięć
   nowych błędów w kodzie portalu (`CLAUDE.md`, „Relacje Eloquenta deklaruj z generykami").
7. **Favicon w Caddy** — `/favicon.ico` zostaje w `@static` z cache „immutable" na rok: przeglądarka,
   która zapamiętała ikonę Laravela, pokaże nową dopiero po wygaśnięciu. Nieistotne przed pierwszym
   wdrożeniem produkcyjnym.
