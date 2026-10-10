# 038 — Poprawki wizualne po testach manualnych portalu i formularza łowiska

> Założone 01.10.2026 po testach manualnych zadań 031–037 (etap 1–2 portalu wędkarza).
> Wzorzec wyglądu: [`portal-v3.html`](../project/mockups/portal-v3/portal-v3.html) (układ) i
> [`fisherya-design.html`](../project/design/fisherya-design.html) (wygląd v1 — wg README portal-v3
> obowiązuje **bez zmian**).

## Opis problemu

Strona łowiska działa, ale w porównaniu z makietami jest „płaska": ramki bez tła zlewają się z tłem
strony, kalendarz nie ma kolorów nagłówka, box z ceną nie odcina się od strony, dane tabelaryczne nie
mają nagłówków kolumn, ramki nie mają cieni. Przegląd strony z makietą wykazał kilka dalszych
rozjazdów (U6–U11). Formularz danych łowiska w obu panelach jest niewygodny: sztywne dwie kolumny
zostawiają dużo pustego miejsca, a galeria w połowie szerokości rośnie w pionie.

### Przyczyna główna (U2–U4 mają JEDNO źródło)

Makieta rysuje portal **na białym tle** (`.browser{background:#fff}`), a ramki informacyjne na
`--surface` (#F7FAFA): `.rules`, `.infobox`, `.calgrid .hd`, opisy grup, `.mgh`. Implementacja ustawia
`bg-surface` na `<body>` w `portal/layout.blade.php` — więc każdy element z `bg-surface` ma **dokładnie
kolor strony**. Sprawdzone w przeglądarce (`getComputedStyle`): tło body = `rgb(247,250,250)` i taki
sam kolor mają wyciąg zasad nad kalendarzem i box z ceną. Klasy CSS są w zbudowanym arkuszu — to nie
błąd potoku Tailwinda, tylko tła.

## Uwagi z testów i wynik przeglądu

### Zgłoszone przez autora

| # | Uwaga | Stan w kodzie | Makieta |
|---|---|---|---|
| U1 | Brak wierszy nagłówkowych w tabelach | Cennik (stawki, dopłaty, usługi) i Szczegóły → Stanowiska to listy `<ul>` we wszystkich szerokościach | **Desktop:** Cennik to `table.spec` z `<thead>`. **Telefon (390 px):** Cennik to lista wierszy (nazwa + kwota, opis pod spodem) — implementacja użyła układu z telefonu także na desktopie. Stanowiska: lista w 2 kolumnach bez nagłówka |
| U2 | „Terminy i ceny" — wyciąg zasad w ramce bez tła | `border-line bg-surface` na tle `bg-surface` | `.rules` — tło `surface` na białej stronie |
| U3 | Kalendarz bez kolorów tła komórek / nagłówka | nagłówek dni i wiersz „Pakiety" `bg-white`, cena w komórce `text-ink`, siatka tylko z poziomych linii | `.hd` tło `surface`, nazwa dnia drobna `faint`; „Pakiety" na `surface`; komórka z ceną biała, tekst `b600`; linie `line2` także pionowe; kolumna stanowisk z prawą linią `line`. v1 ma dodatkowo `.hd.we` (weekend `a100`/`a800`) — v3 go nie używa |
| U4 | Box „cena od" bez tła | `bg-surface` na tle `bg-surface` | `.infobox` — tło `surface` + ramka |
| U5 | Ramki bez cieni | cień tylko na hover karty strony głównej | v1: box ceny/rezerwacji `--sh-2`, dymek rozbicia `--sh-2`, karta łowiska na hover `--sh-2`. portal-v3 cieni na ramkach nie rysuje, ale nie zmienia wyglądu v1 |
| A1 | Formularz łowiska — sztywne 2 kolumny | patrz „Formularz łowiska — stan" niżej | brak makiety formularza |

### Znalezione przy przeglądzie (dodatkowe)

| # | Rozjazd | Stan | Makieta / oczekiwane |
|---|---|---|---|
| U6 | **Pasek zakładek ma pionowy pasek przewijania** (strzałki ⌃⌄ po prawej stronie, widoczne w Chrome na Windows) | `nav[role=tablist]` ma `overflow-x-auto`, a zakładki `-mb-px` — treść ma 35 px przy 34 px wysokości pola, więc `overflow-y` (z automatu `auto`) pokazuje suwak | pasek zakładek bez suwaka; przewijanie wyłącznie poziome na wąskim ekranie |
| U7 | Brak legendy pod kalendarzem | brak | `.legend`: biały — „Cena pobytu od tej doby", `b50` — „Doba w środku pakietu — zacznij wcześniej", kreskowanie `a100/a200` — „Niedostępne — powód od łowiska" |
| U8 | Wyciąg zasad to jeden ciąg tekstu | `FisheryRulesSummary::line()` skleja pozycje w string, widok go wypisuje | pozycje osobno, **etykieta pogrubiona** (`Doba` 15:00–15:00 · `Weekend` … · `Sezon` …), separator `·` w kolorze `faint` |
| U9 | Nawigacja tygodnia w osobnym wierszu, gołe strzałki, zakres dat na środku całej szerokości | wiersz pod przełącznikami | w wierszu nagłówka „Terminy i ceny", po prawej: przyciski `‹` `›` jako `btn-s btn-sm` (biały, ramka) i pogrubiony zakres między nimi |
| U10 | Przełącznik „Łowiących" i aktywny filtr | okrągłe chipy, aktywny `b700` | „Łowiących" jako przełącznik segmentowy (`.seg`, aktywny `b800`); chip aktywny grupy/cechy `b800`, liczba przy chipie `opacity .7` |
| U11 | Duże zdjęcie nagłówka pobiera wariant 1920 px | `sizes="(min-width: 1152px) 1152px"` przy kafelku ~564 px szerokości (układ 1+2) | `sizes` zgodne z faktyczną szerokością kafelka — nie wizualne, ale waga strony (strona-publiczna.md §5) |

### Formularz łowiska — stan (A1, ze zrzutów admina i właściciela)
- Wszystkie pola `fisheryDetailComponents()` leżą w domyślnej siatce 2 kolumn: sekcja „Adres łowiska"
  (z edytorem dojazdu i podglądem mapy) w lewej połowie, prawa pusta na wysokość całej sekcji.
- „Opis" na pełną szerokość, ale wciśnięty między adres a kontakt.
- „Dane łowiska" miesza trzy tematy: akwen (powierzchnia, głębokości, metody, ryba dominująca, rekordy),
  wymagania wobec wędkarza (karta, wędki, no-kill, ogniska) i rozliczenia (waluta, IBAN) — jedna wysoka
  kolumna obok krótkiej sekcji kontaktu.
- „Udogodnienia" i „Dostępne ryby" — listy wyboru w jednej kolumnie każda.
- **Mapa i galeria po pół szerokości**; podgląd FilePond (`integrated`) pokazuje każde zdjęcie galerii
  w pełnym rozmiarze kolumny, jedno pod drugim — 5 zdjęć to kilka ekranów przewijania.
- Panel właściciela: w pierwszym wierszu „Firma" i „Nazwa łowiska" (bez sluga i „wprowadził"); długa
  nazwa firmy jest ucięta.

## Wymagania

### Portal — tło i ramki (U2, U4, U5)
- **Strona portalu na białym tle**: `<body>` w `portal/layout.blade.php` → `bg-white`. Ramki
  informacyjne zostają na `bg-surface` i od tej chwili się odcinają.
- Przegląd **każdego** `bg-white` w widokach portalu: elementy, które w makiecie są białe na białym
  (`.card`), zostają białe z ramką `border-line`; elementy, które w makiecie są na `surface`,
  przechodzą na `bg-surface` (m.in. wiersz „Pakiety", nagłówek listy na telefonie).
- Strona główna i strony informacyjne — sprawdzić po zmianie tła, czy żadna sekcja nie traci
  kontrastu (karty łowisk na liście).
- **Cienie (R2):** box z ceną i kontaktem (`fishery-box`) → `shadow-2`; ramka kalendarza (siatka
  desktop i lista na telefonie) → `shadow-1`. Pozostałe ramki bez cienia; karta łowiska na stronie
  głównej zostaje z `hover:shadow-2`.

### Portal — kalendarz (U3, U7–U10)
- Nagłówek dni: tło `bg-surface`, nazwa dnia drobna, `text-faint`, wielkie litery (`.hd .dw`).
- Komórka nagłówka „Stanowisko" i wiersz „Pakiety" — tło `bg-surface`.
- Komórka z ceną: tekst ceny `text-b600` (`.c-free`), hover `bg-b50` z obwódką `b400`.
- Siatka: linie `border-line2` poziome **i pionowe**; kolumna nagłówków wierszy z prawą linią
  `border-line`.
- Legenda pod siatką (U7) — trzy pozycje jak w makiecie; pozycja „zacznij wcześniej" tylko, gdy
  w widocznym tygodniu jest pakiet, kreskowanie — tylko, gdy jest niedostępność z powodu od łowiska.
- Wyciąg zasad (U8): pozycje z pogrubioną etykietą i separatorem `faint`. `FisheryRulesSummary`
  już zwraca pozycje po kluczach — widok składa je sam zamiast `line()`; tekst i kolejność pozycji
  bez zmian (ten sam wyciąg jest w Cenniku). Wersja zwinięta na telefonie bez zmian.
- Nawigacja tygodnia (U9) w wierszu nagłówka sekcji, po prawej, razem z przełącznikiem „Łowiących";
  na telefonie — wiersz pod nagłówkiem.
- „Łowiących" jako przełącznik segmentowy; aktywny chip i segment `bg-b800` (U10).
- **Weekend (R3): sobota i niedziela** wyróżnione w nagłówku siatki — tło `bg-a100`, tekst `text-a800`
  (`.hd.we` z v1); w pasku dni na telefonie ten sam akcent na kafelku dnia (tło `a100`, tekst `a800`),
  dzień wybrany bez zmian (`bg-b700`). Kolumny komórek z cenami nie są barwione — akcent tylko w nagłówku.
- Widok mobilny: nagłówek listy stanowisk na `bg-surface` (`.mgh`).

### Portal — tabele i listy (U1) — wg R4 (przyjęte)
- Jeden partial `portal.partials.spec-table` (kolumny + wiersze z warstwy danych): `<table>` z `<thead>`
  wg `table.spec` — nagłówek 11 px, wielkie litery, `text-faint`, linia `border-line`; wiersze
  `border-line2`, pierwsza kolumna pogrubiona, hover `bg-surface`.
- **Poniżej `sm` ta sama tabela układa się w wiersze listy** jak w makiecie telefonu: nazwa i kwota
  w jednej linii (`flex justify-between`), pozostałe kolumny drobnym `text-muted` pod spodem; `<thead>`
  ukryty wizualnie (zostaje dla czytników ekranu). Jeden dokument HTML — bez dublowania treści.
- **Cennik:** „Stawka za łowiącego" — *Okres · Cena · Uwagi* (osoba towarzysząca jako osobny wiersz);
  „Dopłaty" — *Dopłata · Kwota · Kiedy*; „Usługi dodatkowe" — *Usługa · Cena · Gdzie i co jest
  potrzebne* (plakietka „obowiązkowa" przy nazwie).
- **Szczegóły → Stanowiska:** *Stanowisko · Grupa · Łowiących · Cechy*; kolumna bez żadnej wartości
  w łowisku (np. brak grup) się nie pokazuje. Kolejność naturalna etykiet bez zmian.
- Zostają listami (nie są danymi tabelarycznymi): opisy grup, dokumenty, „Łowisko w sieci".

### Portal — pozostałe (U6, U11)
- Pasek zakładek: `overflow-y-hidden` (albo podkreślenie aktywnej zakładki bez `-mb-px`) — bez
  pionowego suwaka w żadnej przeglądarce.
- `sizes` zdjęć nagłówka zgodne z rzeczywistą szerokością kafelka w każdym z układów (1, 2, 1+2).

### Panel — formularz danych łowiska (A1)
**Jedna kolumna sekcji na pełnej szerokości**, każda sekcja z własną siatką. Kolejność i nazwy sekcji
odpowiadają zakładce „Szczegóły" portalu — operator wie, gdzie trafi pole.

| # | Sekcja | Pola i siatka wewnątrz |
|---|---|---|
| 1 | **Podstawowe** (bez ramki sekcji) | admin: firma, nazwa · slug, „wprowadził" — 2 kolumny; właściciel: firma, nazwa — 2 kolumny |
| 2 | **Opis łowiska** | opis (edytor) — pełna szerokość |
| 3 | **Adres i dojazd** | 2 kolumny: lewa — województwo, miejscowość, ulica + numer (obok siebie, 3:1), kod; prawa — podgląd mapy (wysokość jak lewa kolumna). Pod spodem „Wskazówki dojazdu" na pełną szerokość |
| 4 | **Kontakt i adresy w sieci** | 3 kolumny: telefon, e-mail, godziny kontaktu · strona WWW, Facebook |
| 5 | **Akwen** | 4 kolumny: powierzchnia, śr. głębokość, maks. głębokość, ryba dominująca · typy łowiska i metody połowu — listy wyboru w 4 kolumnach · dostępne ryby — lista wyboru w 4 kolumnach · rekordy (edytor) na pełną szerokość |
| 6 | **Zanim przyjedziesz** | 4 kolumny: karta wędkarska, wędki w cenie, no-kill, ogniska |
| 7 | **Udogodnienia** | lista wyboru w 4 kolumnach |
| 8 | **Mapa łowiska** | pełna szerokość, podgląd ograniczony wysokością (~320 px) |
| 9 | **Galeria zdjęć** | **pełna szerokość**, kafelki w siatce (`panelLayout('grid')`, ~4 w rzędzie, stała wysokość podglądu), przeciąganie i `appendFiles()` bez zmian |
| 10 | **Rozliczenia** | waluta, IBAN — 2 kolumny |

- Na wąskim ekranie (< `md`) każda siatka spada do 1 kolumny (Filament: `columns(['default' => 1, 'md' => n])`).
- Zmiana obejmuje **wszystkie** miejsca używające `fisheryDetailComponents()`: edycję w obu panelach
  i ostatni krok kreatora (`CreateFishery::fisheryStep()`). Definicje pól bez zmian — tylko
  rozmieszczenie (żadnych zmian walidacji, `live()`, widoczności pól, uprawnień).
- ⚠️ Pola adresu mają `live(onBlur: true)` dla podglądu mapy (zadanie 012) — przeniesienie do `Grid`
  nie może zmienić ścieżki stanu (`street`, nie `address.street`): kontenery układu bez `statePath()`.

### Panel — podgląd „Dane łowiska" w tym samym układzie (R5)
Dziś `ManageFishery::infolist()` ma cztery sekcje w innej kolejności i nazwach niż formularz
(„Dane łowiska", „Portal", „Kontakt i linki", „Adres łowiska") i pokazuje tylko część danych —
brak opisu, dojazdu, akwenu poza powierzchnią, zasad dla wędkarza, udogodnień, ryb, mapy, galerii
i rozliczeń.

- **Podgląd ma te same sekcje, w tej samej kolejności, z tymi samymi nazwami i tą samą siatką co
  formularz** (tabela A1) — pole w podglądzie stoi tam, gdzie w edycji.
- **Układ ma jeden dom — enum `App\Enums\FisherySection`** (R6): przypadki w kolejności sekcji z tabeli A1
  (`Basic`, `Description`, `Address`, `Contact`, `Water`, `AnglerRules`, `Conveniences`, `Map`, `Gallery`,
  `Billing`), metody `label()` i `columns()` (oraz informacja, czy sekcja ma ramkę — `Basic` bez ramki).
  Formularz i podgląd budują się pętlą po `FisherySection::cases()` z mapą sekcja → pola formularza /
  wpisy podglądu. Pola formularza i wpisy podglądu zostają osobnymi definicjami (to różne komponenty
  Filamenta), ale układa je ten sam enum.
- **Test `FisherySectionTest`:** każda sekcja enumu ma pola w formularzu i wpisy w podglądzie (brak w którymś
  widoku = czerwony), a kolejność sekcji w obu widokach to kolejność `cases()`.
- Podgląd pokazuje **każde pole formularza** tylko do odczytu:
  - pole puste → „Nie podano" (jak dziś kontakt); flagi trzystanowe → „Tak / Nie / Nie podano";
  - treści z edytora (opis, dojazd, rekordy) → HTML przez `Str::sanitizeHtml()`;
  - listy wyboru (typy, metody, ryby, udogodnienia) → plakietki;
  - adres → ten sam widok podglądu mapy co w formularzu (`filament.forms.map-preview`);
  - mapa łowiska i galeria → miniatury z wariantów `FisheryImages::url()` (oryginał nie trafia do HTML-a,
    ADR-023), galeria w siatce kafelków w kolejności z panelu, pierwsze oznaczone jako okładka;
  - IBAN — pełny numer (operator i admin widzą dane, które sami wpisali).
- **Dane tylko z podglądu** (nie są polami formularza) trafiają do sekcji „Podstawowe": status
  w portalu (`published_at`), adres strony (slug, z dzisiejszą podpowiedzią dla właściciela) i liczba
  stanowisk w sprzedaży (liczona, jak dziś — zadanie 030).
- Akcja „Edytuj" w nagłówku bez zmian; granica „podgląd ≠ formularz" z `ManageFishery` zostaje.

## Kryteria akceptacji
- [ ] Na stronie łowiska wyciąg zasad, box z ceną i opisy grup mają widoczne tło `surface` na białej
      stronie (desktop i 390 px).
- [ ] Box z ceną ma `shadow-2`, ramka kalendarza `shadow-1`.
- [ ] Nagłówek kalendarza i wiersz „Pakiety" na `surface`, cena w komórce `b600`, linie pionowe,
      sobota i niedziela w nagłówku na `a100`/`a800` (także w pasku dni na telefonie), legenda pod
      siatką, wyciąg zasad z pogrubionymi etykietami, nawigacja tygodnia w wierszu nagłówka.
- [ ] Cennik i Stanowiska: na desktopie tabele z `<thead>`, na 390 px wiersze listy jak w makiecie
      telefonu, bez poziomego przewijania strony.
- [ ] Pasek zakładek bez pionowego suwaka (Chrome na Windows).
- [ ] Strona główna i strony informacyjne po zmianie tła — bez utraty kontrastu (przegląd ręczny).
- [ ] Formularz łowiska w układzie z tabeli A1 w edycji (admin, właściciel) i w kreatorze; galeria
      na pełną szerokość w siatce kafelków; zapis formularza działa jak przed zmianą.
- [ ] Podgląd „Dane łowiska" ma te same sekcje, kolejność i siatkę co formularz i pokazuje każde jego
      pole; układ sekcji zdefiniowany w jednym miejscu.
- [ ] Testy z zakresu niżej zielone; **pełny pakiet odroczony** na `/review-implementation` (Krok 1).

## Zakres testów
- **Tier:** T2 — zależności
- **Uruchamiamy:** `docker compose exec app php artisan test --filter="FisheryPageTest|FisheryPricingTabTest|FisheryDocumentsTabTest|PortalCalendarTest|FisheryPortalDataTest|AdminPanelTest|OwnerPanelTest|FisheryWizardTest|FisheryWizardEntryPointsTest|FisheryPublicationTest|FisheryAnglerRulesTest|FisheryGalleryTest|FisheryMapPreviewTest|PortalSlugReservationTest|FisherySectionTest"`
- **Uzasadnienie:** zmiany są w widokach portalu i w rozmieszczeniu pól formularza; testy strony
  łowiska sprawdzają treść HTML (nagłówki tabel i wyciąg zasad mogą zmienić asercje), testy paneli
  i kreatora — że formularz nadal się renderuje i zapisuje; `FisheryPublicationTest` i
  `FisheryPortalDataTest` sprawdzają stronę „Dane łowiska" (status w portalu, slug, stanowiska w sprzedaży);
  `FisheryAnglerRulesTest`, `FisheryGalleryTest`, `FisheryMapPreviewTest`, `PortalSlugReservationTest` wypełniają
  i zapisują formularz łowiska (przestawiane pola). `FisherySectionTest` — nowy (R6). Pełny pakiet odroczony do
  `/review-implementation`. Bez wyzwalaczy T3 (widoki, rozmieszczenie pól, nowy enum — bez migracji, polityk
  i providerów).

## Zakres wyłączeń
- Zmiany treści, kolejności i logiki danych portalu (`PortalFisheryPage`, `PortalPriceList`,
  `PortalCalendar`, `FisheryRulesSummary`) poza podziałem na kolumny i pozycje.
- Nowe pola formularza łowiska, zmiany walidacji pól.
- Reguła daty wejścia w życie dokumentu — **bez zmian** (autor wycofał zmianę „od dziś", 01.10.2026).
- Testy przeglądarkowe zrzutów ekranu (zadanie 035).

## Zmiany dokumentacji
- [ ] `docs/conventions/strona-publiczna.md` — §4: portal na białym tle, ramki informacyjne na
      `surface` (`bg-surface` na body to błąd); §5: Cennik i Stanowiska jako `spec-table` (tabela na
      desktopie, wiersze listy na telefonie) — przepisać regułę „Stanowiska: jedno w wierszu"; legenda
      i kolory kalendarza; pasek zakładek bez pionowego przewijania.
- [ ] `docs/conventions/panel-admina.md` — układ formularza łowiska w sekcjach i kolejność sekcji;
      niezmiennik: podgląd „Dane łowiska" i formularz układa ta sama lista sekcji.
- [ ] `docs/project/mockups/portal-v3/README.md` — odnotować: Stanowiska w tabeli z nagłówkiem.
- [ ] `CHANGELOG.md` — wpis w changelogu

## Ograniczenia techniczne
- Tailwind 4, tokeny z `portal.css` (`bg-surface`, `text-b600`, `shadow-2`) — bez wartości na sztywno
  w widokach; nowy partial w `resources/views/portal/`, nie w `components/` (strona-publiczna.md §4).
- Bez skryptu wszystkie panele zakładek nadal widoczne (strona-publiczna.md §5).
- Filament 5: `Section`, `Grid`, `->columns([...])`, `->columnSpanFull()`; nazwy pól bez zmian.

## Rozstrzygnięcia
- **R1 — Przyczyna U2–U4 to tło strony, nie brak klas.** Naprawa przez zmianę tła body, nie przez
  podbijanie kolorów ramek — zostajemy przy tokenach z makiety.
- **R2 — Cienie (decyzja autora 01.10.2026):** box z ceną `shadow-2` (jak v1), ramka kalendarza
  `shadow-1`. Inne ramki bez cienia — cień wyróżnia dwa elementy, z którymi wędkarz pracuje.
- **R3 — Weekend w nagłówku kalendarza (decyzja autora 01.10.2026): tak — sobota i niedziela**
  kalendarzowo (`.hd.we` z v1), bez piątku, żeby kolor nie sugerował reguły sprzedaży łowiska. Akcent
  wyłącznie w nagłówku dni — komórki z cenami zostają białe, bo kreskowanie `a100/a200` w komórce
  znaczy „niedostępne — powód od łowiska".
- **R4 — Tabele: `<table>` na desktopie, wiersze listy na telefonie, w jednym znaczniku** (decyzja
  autora 01.10.2026). Uzasadnienie: makieta ma dokładnie ten podział (desktop `table.spec`, telefon
  lista); dane cennika i stanowisk są tabelaryczne (te same atrybuty w każdym wierszu), więc `<th>`
  daje czytnikom ekranu nazwę kolumny przy każdej wartości; jeden znacznik przełączany CSS-em nie
  dubluje treści w HTML-u (indeksacja, strona-publiczna.md §5). Odrzucone: same listy z dorobionym
  „nagłówkiem" z `div`-ów (wygląda jak tabela, ale nią nie jest) i dwa osobne bloki desktop/telefon
  (podwójna treść w dokumencie).
- **R5 — Podgląd „Dane łowiska" w układzie formularza (decyzja autora 01.10.2026):** w tym zadaniu,
  te same sekcje, kolejność i siatka co w edycji, z kompletem pól. Jedna lista sekcji dla obu widoków,
  żeby następna zmiana formularza nie rozjechała podglądu.

- **R6 — Lista sekcji jako enum `FisherySection`** (decyzja autora 01.10.2026): kolejność = kolejność
  przypadków, `label()` / `columns()` jak w pozostałych enumach projektu (`DocumentType`, `PriceRuleKind`).
  Nie ADR: dotyczy jednego formularza i jednego podglądu, odwracalne bez migracji.
- **R7 — Zakres testów poprawiony przy `/review-task`:** filtr `FisheryRulesSummary`, `CreateFishery`
  i `ManageFishery` nie trafiał w żadną klasę testową; zastąpiony klasami, które realnie renderują
  i zapisują formularz łowiska oraz stronę łowiska.

## Powiązane ADR-y
- Brak — kwestie rozstrzygnięte w treści zadania (R1–R7). 
