# Konwencje: strona publiczna (portal wędkarza)

Obowiązuje przy zmianach w `routes/**`, widokach Breeze i `resources/views/**` poza
`resources/views/filament/**` — w szczególności w portalu wędkarza (`resources/views/portal/**`,
`app/Http/Controllers/Portal/**`, `App\Services\Portal*`).

Zadania źródłowe: 029, 031, 032, 033, 034. Uzasadnienia: [ADR-020](../adr/ADR-020-tailwind-portalu-i-potok-zasobow.md)
(potok stylów), [ADR-021](../adr/ADR-021-schemat-adresow-portalu.md) (schemat adresów).
Specyfikacja: [portal-v3](../project/mockups/portal-v3/README.md).

---

## 1. Portal niczego nie liczy

- **Cena pobytu, sprzedawalność, zasady sprzedaży i dostępność pochodzą z warstwy oferty** (`StayOffer`,
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
- **„Cena od" pochodzi wyłącznie z `PriceFrom`** ([`cennik.md`](cennik.md) §5) — karta i box ją tylko
  formatują przez `AmountFormatter::forVisitor()` („od 70 zł / os. / doba"); `null` = „cennik
  w przygotowaniu".
- **Puste pole się nie pokazuje** — ani etykieta, ani „brak". Dane strony łowiska składa
  `PortalFisheryPage` i to ona odfiltrowuje pozycje bez wartości; widok niczego nie sprawdza.
- **Treść z edytora operatora** (opis, dojazd, rekordy) wychodzi wyłącznie przez `Str::sanitizeHtml()`
  (`PortalFisheryPage::html()`), stylowana klasą `.prose-portal` z `portal.css`.

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

## 5. Strona łowiska

- **Zakładki w JEDNYM dokumencie HTML** — „Mapa i terminy" (domyślna), „Szczegóły", „Cennik"
  i „Dokumenty"; treść wszystkich jest w HTML-u i indeksuje się pod adresem łowiska (027 pkt 9.4).
  Kotwice zależą od języka i mają jeden dom: `PortalRoutes::FISHERY_TABS` (`#szczegoly` / `#details`,
  `#cennik` / `#pricing`, `#dokumenty` / `#documents`); pierwsza zakładka nie zostawia kotwicy
  w adresie. ⚠️ **Nie rozbijaj zakładek na osobne adresy** — czwarty segment rozszerzyłby schemat
  z ADR-021, a każda zakładka potrzebowałaby własnego canonical i `hreflang`. Nowa zakładka to kolejna
  pozycja tej samej tablicy i kolejny panel; pasek zakładek przewija się poziomo na wąskim ekranie.
- **Zakładka „Cennik" — odczyt konfiguracji, nie wycena.** Dane układa `PortalPriceList` (przez
  `PortalFisheryPage::pricing()`): stawki z okresami (bez dat = „cały rok", przyszły okres oznaczony),
  dopłaty pod nazwami łowiska z warunkiem (`PriceRule::conditionText()`), przedsprzedaż z tego samego
  wyciągu co nad kalendarzem (`FisheryRulesSummary`), usługi z jednostką, zasięgiem i wymaganymi cechami.
  Horyzont: **od dziś, bez górnej granicy** (przyszłe sezony widać). Nie pokazuje się: stawka zakończona,
  zawieszona i martwa (`PricingConfigurationAudit::deadRates($dziś)` — jedna implementacja z kalendarzem
  panelu), dopłata zawieszona i zakończona, usługa nieaktywna albo przypięta wyłącznie do stanowisk
  poza sprzedażą. ⚠️ **„Obowiązkowa" to własność przypięcia usługi do stanowiska**, nie usługi — przy
  części stanowisk widać „obowiązkowa na stanowiskach: …". **Bez przykładu wyceny**: cenę terminu liczy
  kalendarz, a przycisk `[data-tab-open]` do niego prowadzi. Jawny wyjątek od „portal pyta wyłącznie
  `StayOffer`" — [`cennik.md`](cennik.md) §5.
- **Zakładka „Dokumenty": dla każdego rodzaju WYŁĄCZNIE wersja obowiązująca dziś**
  (`PortalFisheryPage::documents()` → `FisheryDocuments::current()`), w kolejności rodzajów:
  regulamin, polityka prywatności, inne. Rodzaj bez wersji obowiązującej **nie ma sekcji**; łowisko
  bez żadnej — zakładka mówi to wprost. Wersji zaplanowanych i archiwalnych się nie pokazuje ani nie
  zapowiada. Treść jest w HTML-u, zwinięta w `<details>` (indeksowana, działa bez skryptu) i wychodzi
  przez `html()`; tytuł i data „obowiązuje od" są widoczne zawsze.
- ⚠️ **Plural z `trans_choice` w portalu EN wychodzi po polsku** — bez `lang/en.json` klucz z liczbą
  mnogą spada na fallback (`pl`). Nowy tekst z liczbą dawaj dwoma zwykłymi kluczami (`__()`), jak
  `PriceRule::conditionText()`.
- **Przełącza je `resources/js/portal.js`** — wyłącznie w układzie portalu, bez Alpine. Kontrakt
  znaczników: `[data-tabs]`, `[data-tab-link]`, `[data-tab-panel]`, a odsyłacz do innej zakładki
  wewnątrz treści to **przycisk `[data-tab-open]`, nie kotwica** — przełącza bez przewijania (kotwica
  powodowała skok strony) i jest ukryty bez skryptu. ⚠️ **Bez skryptu wszystkie treści
  muszą być widoczne** (jedna pod drugą) — nie chowaj paneli klasą w HTML-u, chowa je dopiero skrypt.
- **Box z ceną i kontaktem** jest ten sam w każdej zakładce (`portal.partials.fishery-box`) i **stoi
  w miejscu** (nie jest przyklejony przy przewijaniu). Bez telefonu: e-mail łowiska albo nic — bez tekstu zastępczego; pasek „Zadzwoń"
  przyklejony do dołu telefonu istnieje tylko przy telefonie. Linki zewnętrzne: `target="_blank"
  rel="noopener"`, tylko wypełnione.
- **Kolejność na telefonie:** nazwa → zakładki → mapa → [kalendarz] → cena i telefon → opis → zdjęcia.
  Zdjęcia nad nazwą tylko na szerszym ekranie.
- **Stanowiska: jedno w wierszu, tylko w sprzedaży, porządek naturalny etykiet** (2, 8, 15 — `strnatcasecmp`), z grupami,
  pojemnością „do N łowiących" i cechami **filtrowalnymi** z wypowiedzianą wartością (flaga „nie"
  i brak wartości się nie pokazują). Pod listą — grupy: nazwa, stanowiska grupy w sprzedaży i opis
  (HTML z edytora, przez `html()`); grupa bez stanowiska w sprzedaży się nie pokazuje.
- **„Łowisko w sieci": adres w Fisherya (link), potem strona WWW, potem Facebook** — dwa ostatnie
  tylko wypełnione.
- **Obrazek mapy** z dysku uploadów z konfiguracji (`filament.default_filesystem_disk`), bez przybijania
  dysku ([`panel-admina.md`](panel-admina.md) §1).

## 6. Kalendarz strony łowiska

- **Mechanizm: GET z parametrami + stopniowe ulepszenie** ([ADR-022](../adr/ADR-022-mechanizm-interakcji-portalu.md)).
  Każdy przełącznik to zwykły link (`rel="nofollow"`, `data-calendar-link`); bez skryptu działa
  przeładowaniem, a `portal.js` pobiera ten sam adres z nagłówkiem `X-Portal-Fragment: calendar`
  i podmienia wyłącznie `[data-calendar]` (`portal.partials.calendar`). ⚠️ Nie dokładaj Livewire
  do stron publicznych i nie trzymaj stanu kalendarza poza adresem.
- **Stan w adresie, nazwy parametrów zależne od języka** — jeden dom: `PortalRoutes::CALENDAR_PARAMS`
  (PL `tydzien`/`lowiacych`/`grupa`/`cecha`/`doba`/`st`, EN `week`/`anglers`/`group`/`feature`/`night`/`pos`).
  Wartości grup i cech to slugi z nazw (`slug-cechy:slug-opcji` dla wyboru); nieznane i błędne
  wartości spadają do domyślnych. Canonical i `hreflang` — bez parametrów (`PortalRoutes::alternates()`);
  przełącznik języka niesie stan pod nazwami drugiego języka (`PortalRoutes::switchUrls()`).
- **Przełączniki zamiast grupowania:** grupa jedna naraz (stanowisko w kilku grupach widać w każdej;
  aktywna grupa i aktywna cecha kliknięte ponownie się wyłączają),
  cechy filtrowalne łączone przez I (trzeci stan nie spełnia, cechy liczbowe nie są przełącznikiem);
  liczba przy grupie i cesze = stanowiska w sprzedaży w bieżącym wyborze.
  ⚠️ **„Wszystkie" to reset filtrów:** zawsze liczba WSZYSTKICH stanowisk w sprzedaży, czyści naraz
  grupę i cechy, aktywne tylko bez żadnego filtra; jest także przy łowisku z samymi cechami.
- **Układ: kalendarz na CAŁĄ szerokość**, pod mapą i boxem (siatka CSS: na telefonie kolejność z HTML —
  mapa → kalendarz → cena i telefon → opis). ⚠️ **Siatka bez stałej wysokości i bez własnego
  przewijania** — wysokość wynika z liczby stanowisk, a nagłówek dni przykleja się do okna. Kontener
  z `overflow` przyciągnąłby `sticky` do siebie i włączał przewijanie; w jednej kolumnie obok boxa
  siedem dób się nie mieściło.
- **Wiersze: stanowiska w sprzedaży**, naturalna kolejność etykiet; stanowisko o mniejszej pojemności
  niż wybrana liczba łowiących to komunikat na cały wiersz i nie jest pytane o werdykty.
  ⚠️ Filtrowanie idzie PRZED siatką — `SaleCalendar::grid(..., $onlyPositionIds)` — portal nie liczy
  werdyktów dla wierszy, których nie pokaże.
- **Komórka i podpowiedź:** „cena · długość", „z dopłatą", „zacznij pt 12.06", krótki powód dla
  wędkarza (pełny ze słownika w `title`). Podpowiedź z rozbiciem otwiera się: myszą na najechanie,
  klawiaturą na `:focus-visible`, dotykiem na fokus wyłącznie przy `hover: none`. ⚠️ Nie wracaj do
  zwykłego `:focus-within` — kliknięcie myszą zostawiało dymek otwarty. `portal.js` przypina dymek
  do okna (`position: fixed`), żeby nic go nie przycinało.
  Na telefonie — pasek dób, lista stanowisk dla doby (`doba`) i karta stanowiska (`st`).
- **Wyciąg zasad ma jeden dom — `FisheryRulesSummary`:** pełny nad siatką, skrócony na telefonie
  i linijka na karcie strony głównej (`cardLine()`). Pusta wartość parametru nie trafia do wyciągu.

## 7. Breeze po zadaniu 031

- **Pulpitu Breeze (`/dashboard`) nie ma.** Po logowaniu, rejestracji, weryfikacji adresu i 2FA
  użytkownik trafia do panelu — jedna reguła `PanelHome::urlFor()` ([`autoryzacja.md`](autoryzacja.md) §6).
  `redirect()->intended()` zostaje: cel „zamierzony" nadal wygrywa.
- `/` należy do portalu. Widoki logowania i profilu Breeze zostają do etapu 4 (konta wędkarzy).
