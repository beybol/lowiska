# Konwencje: strona publiczna (portal wędkarza)

Obowiązuje przy zmianach w `routes/**`, widokach Breeze i `resources/views/**` poza
`resources/views/filament/**` — w szczególności w portalu wędkarza (`resources/views/portal/**`,
`app/Http/Controllers/Portal/**`, `App\Services\Portal*`).

Zadania źródłowe: 029, 031. Uzasadnienia: [ADR-020](../adr/ADR-020-tailwind-portalu-i-potok-zasobow.md)
(potok stylów), [ADR-021](../adr/ADR-021-schemat-adresow-portalu.md) (schemat adresów).
Specyfikacja: [portal-v3](../project/mockups/portal-v3/README.md).

---

## 1. Portal niczego nie liczy

- **Cena, sprzedawalność, zasady sprzedaży i dostępność pochodzą z warstwy oferty** (`StayOffer`,
  `SaleCalendar` — [`dostepnosc.md`](dostepnosc.md), [`cennik.md`](cennik.md)). Widok ani kontroler
  portalu nie składa odpowiedzi z niższych warstw i nie ma własnej reguły sprzedażowej — pierwsza taka
  reguła robi z portalu drugie źródło prawdy obok kalendarza podglądowego.
- **Lista pokazuje wyłącznie łowiska opublikowane** (`Fishery::published()`) **z województwem** — jeden
  dom: `PortalFisheries::listed()`. Kolejność: alfabetycznie po nazwie, polska kolacja (`Collator('pl_PL')`).
  ⚠️ Strona „Jak układamy listę" opisuje dokładnie tę kolejność; zmiana sortowania wymaga zmiany strony
  (obowiązek informacyjny o parametrach plasowania).
- ⚠️ **Trzeci stan parametru łowiska nie jest „nie".** `null` („nie podano") nie daje plakietki ani
  wypowiedzi w żadną stronę — no-kill pokazuje się wyłącznie przy jawnym `true`.
- **Treści operatora są jednojęzyczne** (D8): nazwa, opis, nazwy grup i dopłat idą po polsku także
  w wersji EN. Tłumaczy się wyłącznie interfejs (`lang/`).

## 2. Adresy i języki

- **Każda strona z treścią ma prefiks języka** (`/pl/…`, `/en/…`). Poza nim żyją wyłącznie wejścia
  przekierowujące (`/`, krótki adres łowiska) i trasy techniczne (`robots.txt`, `sitemap.xml`, panele,
  logowanie).
- **Strony informacyjne mają inny adres w każdym języku.** Slugi mają jeden dom — `PortalRoutes::PAGES`;
  trasa istnieje raz na język (`portal.page.{strona}.{język}`), a przełącznik języka prowadzi na
  **odpowiednik bieżącej strony** (`PortalRoutes::alternates()`), nie na stronę główną. Nie wpisuj
  adresu strony literałem w widoku — `PortalRoutes::pageUrl()`.
- **Wybór języka dla wejść bez prefiksu ma jedną implementację** — `PortalLocale::resolve()`: cookie
  `locale` → `Accept-Language` → PL. Cookie ustawia middleware `SetPortalLocale`, podpięty na **grupie
  tras w `routes/web.php`**, nie w `bootstrap/app.php` (panele mają własny przełącznik języka).
- **Adres kanoniczny łowiska** `/{język}/{slug-województwa}/{slug}`; łowisko szukane **wyłącznie po
  slugu**, zły segment województwa → 301. Buduje go wyłącznie `PortalRoutes::fisheryUrl()` — `null`,
  gdy łowisko nie ma województwa.
- ⚠️ **Strony informacyjne rejestruj PRZED trasą łowiska** — `/pl/dokumenty-prawne/regulamin` ma ten sam
  kształt trzech segmentów.

## 3. Krótki adres łowiska wprost pod domeną

- **`fisherya.com/{slug}` obsługuje `Route::fallback()`, nigdy trasa `/{slug}`** (ADR-021). Router bierze
  pierwsze dopasowanie, ale kolejności względem tras pakietów (Filament, Livewire, Breeze) nie
  kontrolujemy, a dopasowana trasa bez łowiska zwraca 404 **zamiast przepuścić żądanie dalej** —
  `/{slug}` przed Filamentem wyłączyłoby `/admin`. Fallback jest zawsze ostatni i obsługuje też 404
  portalu dla każdego nieznanego adresu.
- **Slug łowiska nie może zająć adresu aplikacji** — jedna reguła `PortalSlugs::collidesWithApplication()`:
  kształt `PortalSlugs::PATTERN` (bez kropki — pliki w `public/`), minimum 3 znaki (kody języków),
  lista `PortalSlugs::RESERVED` i pierwsze segmenty zarejestrowanych tras (z routera, nie z pliku —
  np. prefiks Livewire z haszem). Nadanie automatyczne dostaje sufiks, ręczna zmiana — błąd walidacji
  ([`panel-admina.md`](panel-admina.md) §11).
- ⚠️ **Nowa trasa pierwszego poziomu → wpis w `PortalSlugs::RESERVED`.** Router ją zobaczy, ale lista
  chroni też nazwy zajęte przed Laravelem (katalogi `public/`, które Caddy serwuje sam) i te, które
  zajmiemy później. Kierunek „nowa trasa kontra wydrukowany slug" łapie `portal:check-slugs` przy
  wdrożeniu — ostrzeżenie w logu, nie blokada; naprawa to zmiana nazwy NASZEJ trasy, nie sluga.
- **Nazwa łowiska ma co najmniej 3 znaki** (`Fishery::MIN_NAME_LENGTH`) — z niej powstaje slug.

## 4. Układ, style i skrypty zewnętrzne

- **Układ portalu to `portal.layout`** — ładuje wyłącznie wejście `resources/css/portal.css` (tokeny
  „Głębia", fonty hostowane u nas), nigdy stylów Filamenta ani Breeze (ADR-020). Nowy katalog widoków
  portalu → `@source` w `portal.css`, inaczej klasy nie dostaną CSS-u.
- ⚠️ **Widoki portalu żyją w `resources/views/portal/`, nie w `resources/views/components/`** — tamten
  katalog jest źródłem klas arkusza Breeze (`app.css`), więc komponent portalu wniósłby tam swoje klasy.
- **Umami wyłącznie w układzie portalu** i wyłącznie przy komplecie `services.umami` — panele i widoki
  Breeze go nie ładują. Wartości podaje tylko wdrożenie produkcyjne (`deploy.yml`); staging nie liczy
  własnego ruchu.
- **Poza produkcją portal jest `noindex`** — `<meta name="robots">` w układzie i `robots.txt` z
  `Disallow: /`. ⚠️ `robots.txt` i `sitemap.xml` są **trasami**, nie plikami w `public/` — plik
  statyczny serwer podałby przed Laravelem, dla każdego środowiska ten sam. Nie przywracaj
  `public/robots.txt`.
- **Portal ustawia wyłącznie ciasteczka techniczne** (sesja, `XSRF-TOKEN`, `locale`) i dlatego nie ma
  banera zgód. Strona „Pliki cookie" je wymienia — **nowe ciasteczko → wpis na tej stronie** i ponowna
  ocena potrzeby banera.
- **Favicon i ikony aplikacji: jeden zestaw w `public/`** (`partials.favicons` w portalu i Breeze,
  `->favicon()` w obu panelach).

## 5. Breeze po zadaniu 031

- **Pulpitu Breeze (`/dashboard`) nie ma.** Po logowaniu, rejestracji, weryfikacji adresu i 2FA
  użytkownik trafia do panelu — jedna reguła `PanelHome::urlFor()` ([`autoryzacja.md`](autoryzacja.md) §6).
  `redirect()->intended()` zostaje: cel „zamierzony" nadal wygrywa.
- `/` należy do portalu. Widoki logowania i profilu Breeze zostają do etapu 4 (konta wędkarzy).
