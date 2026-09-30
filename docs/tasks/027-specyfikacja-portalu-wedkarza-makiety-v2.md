# 027 — Specyfikacja portalu wędkarza: makiety v2 uzgodnione z konfiguracją łowiska

> **Etap 1, zadanie 1** ([roadmapa](../project/ROADMAPA.md)). **Zadanie koncepcyjne — bez kodu.**
> Wynikiem są dokumenty i makiety, na których staną zadania implementacyjne etapów 1 i 2.
> Założone 24.09.2026 na podstawie przeglądu makiet portalu wobec konfiguracji z etapu 0.

**Makiety v1 zostają bez zmian:** [`docs/project/design/fisherya-design.html`](../project/design/fisherya-design.html)
(ekrany portalu 1–8, 17.08.2026). Ich **wygląd jest zaakceptowany** i obowiązuje w v2.
**Makiety v2** powstają w osobnym katalogu `docs/project/mockups/portal-v2/`.
**Makiety v3** (przegląd v2 z 30.09.2026, pkt 9) — `docs/project/mockups/portal-v3/`; ich specyfikacja
zastępuje specyfikację v2.

## Opis problemu

Makiety portalu powstały 17.08.2026 na podstawie `IDEA.md`, **przed** etapem 0. Konfiguracja
sprzedaży (M1–M8, zadania 014–021) ułożyła model inaczej, niż zakładały makiety: cena jest za osobę
za dobę i nie zależy od stanowiska, sprzedawany jest **pobyt** ze spoiwem dób, dopłaty są osobnymi
pozycjami pod nazwami łowiska, usługi mają jednostki, zasięg i wymagane cechy, a wiele rzeczy
pokazanych w makietach świadomie wypadło z zakresu (pozwolenia sezonowe × rezerwacje, klikalna
mapa, cena per stanowisko).

Wygląd makiet jest zaakceptowany, ale **ich warstwa informacji i logika rozjeżdżają się
z konfiguracją**. Budowanie portalu wprost na v1 powtórzyłoby błąd z zadania 018: przekomplikowany
albo sprzeczny model wychodzi dopiero na ekranie, gdy jest już drogi do poprawienia.

Dodatkowo roadmapa dzieli portal na etapy: **1** szkielet (publiczny, bez kont), **2** oferta
łowisk **bez dostępności**, **3** dostępność z rezerwacji operatora, **4** zakup. Makiety v1
pokazują wszystko naraz i nie mówią, co należy do którego etapu.

## Wymagania

### 1. Przypisanie ekranów i elementów do etapów

Specyfikacja przypisuje **każdy ekran i każdy istotny element** makiet do etapu 1, 2, 3 albo 4.
Punkt wyjścia:

| Ekran v1 | Etap | Co w etapie 1–2 |
|---|---|---|
| 1 · Strona główna | 1 (szkielet), 2 (treść) | wyszukiwarka po miejscu i typie łowiska, **bez terminu i liczby łowiących** (dostępność — etap 3); bez „Zaloguj / Załóż konto" |
| 2 · Wyniki wyszukiwania | 1–2 | lista łowisk z filtrami (pkt 5) i mapą; **bez trybu dostępności** po terminie (etap 3) |
| 3 · Strona łowiska | 2 | opis, zdjęcia, stanowiska, cennik, kalendarz (pkt 3), sekcja **„Zanim przyjedziesz"** z czterema parametrami z 021 (karta wędkarska, wędki w cenie, no-kill, zakaz ognisk; pusta wartość = „nie podano"); **bez panelu rezerwacji** — zamiast niego kontakt „rezerwacje telefonicznie" |
| 4 · Kalendarz stanowisk | 2 (bez zajętości), 3 (z zajętością) | publiczny odpowiednik kalendarza podglądowego (pkt 3) |
| 5 · Konfigurator rezerwacji | 4 | tylko lista znanych zmian (pkt 7), bez rysowania v2 |
| 6 · Finalizacja i konto | 4 | jw. |
| 7 · Potwierdzenie | 4 | jw. |
| 8 · Moje konto | 4 | jw. |
| nowy · Landing dla właścicieli łowisk | 1 | **jedna prosta strona** w szkielecie strony publicznej; makieta v2 — jeden ekran |

⚠️ **Ekranów etapu 4 nie przerysowujemy teraz** — ta sama zasada, z której wynika roadmapa: nie
projektujemy na zapas pod mechanizmy, których nie da się jeszcze zobaczyć. Specyfikacja zbiera dla
nich wyłącznie listę zmian wynikających z konfiguracji.

### 2. Ustalenia wiążące z przeglądu makiet v1 (24.09.2026)

| # | Rozjazd w v1 | Rozstrzygnięcie |
|---|---|---|
| 1 | Cena „za stanowisko", różne ceny na stanowiskach | semantyka i copy — w v2 cena za osobę za dobę, bez ceny per stanowisko (M3 „nie teraz") |
| 2 | Kalendarz sprzedaje doby, nie pobyty | semantyka — v2 mówi językiem warstwy oferty (pkt 3) |
| 3 | Rozbicie ceny bez dopłat, obniżki przedsprzedażowej i usług obowiązkowych | **v2 pokazuje dopłaty pod nazwami łowiska (D5), obniżkę przedsprzedażową i usługi obowiązkowe** wszędzie, gdzie pokazuje cenę |
| 4 | „Cena od" na liście bez definicji | **najniższa stawka za łowiącego za dobę**, bez dopłat, obniżki i usług (pkt 4) |
| 5 | Filtry mieszają udogodnienia, cechy stanowisk i usługi | **słowniki, cechy filtrowalne (≥ 1 stanowisko), karta i no-kill; bez usług** (pkt 5) |
| 6 | Status „Do potwierdzenia przez łowisko" | **usunięty** — dostępność pokazujemy dopiero, gdy łowisko wpisuje wszystkie rezerwacje (roadmapa, etap 3) |
| 7 | „Moje konto" z pozwoleniem sezonowym i limitem | **pomijamy** (P9) |
| 8 | Rezerwacja „Czeka na płatność · Zapłać" | odzwierciedlenie okna płatności — **pomijamy na tym etapie** |
| 9 | „Zgłoś problem — zwracamy całość" | **usunięty** — stroną umowy i podmiotem zwracającym jest łowisko |
| 10 | Klikalna mapa stanowisk | **nie robimy teraz**; mapa jako obrazek. Klikalna mapa trafia do listy „na później" w roadmapie |

### 3. Kalendarz w portalu — główna wizualizacja zasad sprzedaży

- **Zasady sprzedaży nie dostają osobnego widoku.** Pokazuje je kalendarz: sezon, weekend i święta
  sprzedawane w całości („zacznij 12.06"), minimum i maksimum pobytu, przedsprzedaż, horyzont,
  blokady z powodem widocznym dla wędkarza (016). Źródłem jest **warstwa oferty** — portal niczego
  nie liczy po swojemu (Z3, ADR-015), tak jak kalendarz podglądowy z 019.
- **Nad albo pod kalendarzem — krótki wyciąg najważniejszych zasad**, nie wszystko. Przykład:
  „Doba 15:00–15:00 · weekend tylko pt–nd w całości · majówka 30.04–03.05 tylko w całości ·
  sezon 01.03–31.12". Wariacje związane z usługami dodatkowymi pomijamy.
- **Usługi obowiązkowe są widoczne w kalendarzu** (przy stanowiskach, których dotyczą) —
  **obok ceny, nie doliczone do niej**: komórka pokazuje cenę z warstwy oferty, a przy niej
  np. „+ 30 zł pościel (obowiązkowa)". Usługi w warstwie oferty przyjdą w etapie 4 (decyzja z 020).
- **W etapie 2 kalendarz nie pokazuje zajętości** — bez rezerwacji w systemie każde stanowisko
  wyglądałoby na wolne (R4). Kalendarz mówi „co i za ile wolno kupić", a przy nim stoi kontakt
  „rezerwacje telefonicznie". Zajętość dochodzi w etapie 3 — v2 rysuje oba warianty.
- **Wersja mobilna kalendarza** (ok. 390 px) — v1 zostawiła ją jako osobny problem („Co dalej").
  Kalendarz jest główną wizualizacją etapu 2, więc v2 musi go rozwiązać.
- **Rozbicie ceny** w podpowiedzi albo przy wyborze terminu: stawka za łowiącego, osoba
  towarzysząca, dopłaty pod nazwami, obniżka przedsprzedażowa, usługi obowiązkowe (ustalenie 3;
  usługi obowiązkowe jako osobne pozycje obok kwoty z oferty, nie w jej sumie).
- **Kalendarz portalu i kalendarz podglądowy panelu mają wspólne dane, osobne widoki.** Oba czytają
  warstwę oferty i model siatki (`SaleCalendar`). Widok panelu to Filament z diagnostyką cennika;
  widok portalu — własny Tailwind (ADR-020), wersja mobilna, bez diagnostyki.
- **Wyciąg zasad zawiera kartę wędkarską i no-kill** (parametry łowiska z 021), obok doby, weekendu,
  świąt i sezonu.

### 4. Definicja „ceny od"

Lista łowisk, wyniki i karta łowiska pokazują jedną liczbę „od". **Rozstrzygnięte:**
- **najniższa stawka za łowiącego za dobę** w trwającym albo najbliższym sezonie — format
  „od 70 zł / os. / doba";
- **bez dopłat, bez obniżki przedsprzedażowej i bez usług** — liczba prosta i porównywalna między
  łowiskami (cena najkrótszego pobytu zależałaby od daty i minimum długości);
- liczy ją **jedna metoda w warstwie cennika** (Z3) — portal jej nie wylicza. Specyfikacja nazywa
  tę metodę i jej źródło (stawki `PriceRule`).

### 5. Filtry na liście łowisk

Nowy zestaw zbudowany wyłącznie z danych, które konfiguracja ma:
- typ łowiska (`FisheryType`), metody połowu (`FishingMethod`), udogodnienia łowiska (`Convenience`);
- **cechy stanowisk oznaczone jako filtrowalne** (`is_filterable`, 014) — **łowisko spełnia filtr,
  gdy ma co najmniej jedno stanowisko w sprzedaży z tą cechą**; cecha pusta nie spełnia filtra
  (trzeci stan, O11);
- **parametry łowiska z 021: karta wędkarska wymagana i no-kill** — wartość pusta („nie podano")
  nie spełnia żadnego wariantu filtra;
- filtry łączone przez **I**;
- ⚠️ **usług nie da się filtrować między łowiskami** — nazwy usług są dowolnym tekstem operatora
  (O21); „wypożyczenie łódki" z v1 wypada z filtrów;
- „cena za dobę" — wg definicji z pkt 4;
- główne parametry plasowania wyników (obowiązek informacyjny, `DECYZJE-I-TODO-BIZNESOWE.md` §2.1).

### 6. Dane łowiska do uzupełnienia

Przegląd modelu `Fishery` (24.09.2026) — makiety potrzebują danych, których konfiguracja nie ma.
Specyfikacja ustala kształt i źródło każdej z nich (implementacja w zadaniu etapu 1 albo 2):

| Brak | Po co | Rozstrzygnięte |
|---|---|---|
| **współrzędne łowiska** | „42 km od Ciebie", mapa wyników | **geokodowanie adresu z ręczną korektą** — kolumny szerokości i długości na łowisku; wybór geokodera w zadaniu implementacyjnym |
| **kontakt: telefon, e-mail, godziny telefonu** | etap 2 opiera się na „rezerwacje telefonicznie" | **na łowisku** — jedna firma prowadzi kilka łowisk z różnymi numerami; dane firmy tylko jako strona umowy |
| **adres strony (slug)** | indeksowalny adres strony łowiska | **generowany z nazwy przy utworzeniu, unikalny w portalu (sufiks przy kolizji), stały** — zmiana nazwy nie zmienia adresu; ręczną zmianę robi admin |
| **publikacja łowiska** | lista pokazuje tylko łowiska opublikowane | **flaga publikacji, którą ustawia właściciel**; przycisk ostrzega o brakach konfiguracji, ale ich nie wymusza |
| `positions_count` wpisywany ręcznie | liczba stanowisk na karcie łowiska | **kolumna znika**, liczba jest wyliczana ze stanowisk w sprzedaży |

Są już: adres, dojazd, opis, powierzchnia, głębokości, rekord, ryby i ryba dominująca, galeria,
mapa jako obrazek, typy, metody, udogodnienia, firma prowadząca.

⚠️ Treści operatora są jednojęzyczne (D8) — wersja EN portalu pokaże opisy łowisk po polsku.
Specyfikacja zapisuje to jako stan świadomy.

### 7. Ekrany etapu 4 — lista zmian (bez rysowania)

Do wykorzystania przy specyfikacji etapu 4:
- rozbicie ceny jak w ustaleniu 3; usługi z jednostkami (za dobę / za pobyt × liczba), zasięgiem
  i usługami obowiązkowymi;
- **polityka zwrotu dopiero w koszyku przed zakupem** (progi, stan „nieustawiona"); znikają
  obietnice „bezpłatnej anulacji" z listy i strony łowiska;
- **dokumenty łowiska przy rejestracji / zakupie** wg flag wymagalności (021, ADR-017);
- obowiązki informacyjne koszyka (adnotacja w wymaganiach etapu 0): brak prawa odstąpienia,
  łowisko stroną umowy, podział obowiązków;
- pojemność stanowiska przy składzie uczestników;
- ustalenia 7, 8, 9 z tabeli w pkt 2.

### 8. Decyzje techniczne do zapisania przed implementacją etapu 1

- **Tailwind dla portalu.** Projekt kompiluje dziś Tailwinda 3; motyw Filamenta 5 wymaga
  Tailwinda 4 (zadanie 019, „Stan po implementacji"; ADR-016). Portal ma mieć „własny Tailwind"
  (decyzja 6 w v1) — trzeba rozstrzygnąć wersję i potok zasobów, bo dotyczy też istniejącej strony
  publicznej. **Założony [ADR-020](../adr/ADR-020-tailwind-portalu-i-potok-zasobow.md)**
  (rekomendacja: cały projekt na Tailwind 4, jeden potok Vite z osobnymi wejściami; migracja jako
  pierwsze zadanie implementacyjne etapu 1).
- **Kalendarz jako komponent wspólny** dla portalu i panelu — **rozstrzygnięte: wspólne dane
  (warstwa oferty, `SaleCalendar`), osobne widoki** (pkt 3).
- Brakujący plik konwencji `docs/conventions/strona-publiczna.md` (wskazany w `CLAUDE.md`, nie
  istnieje) — zakres, który powstanie wraz z etapem 1.

### 9. Przegląd makiet v2 i makiety v3 (30.09.2026)

Przegląd v2 z autorem przed implementacją. Wniosek ogólny: v2 projektuje portal na skalę, której
na starcie nie będzie (dwa łowiska), a strona łowiska otwiera się opisem, a nie tym, po co wędkarz
w nią klika. **Makiety v3** powstają w `docs/project/mockups/portal-v3/` razem ze specyfikacją
(`README.md`), która **zastępuje** specyfikację v2 w zakresie etapów 1–3. v2 zostaje jako zapis
przebiegu.

| # | Propozycja autora | Dyskusja | Rozstrzygnięcie |
|---|---|---|---|
| 1 | Strona główna bez wyszukiwarki i mapy; zamiast nich baner kierujący na landing dla łowisk | Na start będą dwa łowiska — mapa z dwiema pinezkami i wyszukiwarka pokazują głównie małą adopcję. Baner dla łowisk w pierwszym ekranie mówiłby jednak wędkarzowi, że to serwis dla firm, a strona główna jest dla wędkarzy. Obowiązek podania zasad układu listy nie znika przy prostej liście. | **Strona główna dla wędkarza: hasło i lista wszystkich opublikowanych łowisk, alfabetycznie.** Bez wyszukiwarki, mapy i filtrów. **Bez banera dla łowisk** — landing jest dostępny z nawigacji („Dla łowisk") i stopki. Stały link „Jak układamy listę" (alfabetycznie, bez ofert płatnych). **Mapa, wyszukiwarka i filtry wracają po przekroczeniu 12 opublikowanych łowisk** (próg do korekty jedną linijką w roadmapie). |
| 2 | Konsekwencją pkt 1 jest brak ekranu wyników | Zgoda. Z etapu 2 wypadają filtry, mapa wyników i sortowanie; ze specyfikacji danych — współrzędne i geokodowanie (potrzebne wyłącznie mapie). | **Ekranu wyników nie projektujemy.** Filtry (§5 v2), mapa wyników i współrzędne łowiska przechodzą do „na później" z progiem z pkt 1. Rozstrzygnięcia v2 o filtrach zostają jako materiał wyjściowy na tę chwilę. |
| 3 | Canonical `/pl/{województwo}/{slug}` i krótki adres, który łowisko może podawać jako „swoją stronę" | Województwo w adresie jest czytelne i daje przyszłe strony regionów. Routing szuka łowiska **wyłącznie po slugu** (unikalnym w portalu), a województwo jest ozdobą: zły segment województwa → 301 na canonical, więc korekta adresu łowiska niczego nie psuje. Krótki adres wprost pod domeną (`/{slug}`) koliduje ze wszystkimi obecnymi i **przyszłymi** stronami informacyjnymi — listy zastrzeżonych nazw nie da się przewidzieć. Rozważone: subdomena (`klasztorne.fisherya.com`) i separator (`fisherya.com/l/klasztorne`). Subdomena wymaga wildcard DNS i certyfikatu wieloznacznego (na Cloud Run — load balancer zamiast prostego mapowania domeny), dzieli portal na osobne witryny w oczach wyszukiwarek i ma tę samą kolizję w mniejszej skali (`www`, `api`, `panel`, `mail`…). Separator to jedna trasa i jedna zastrzeżona litera. | **Canonical: `/{język}/{województwo}/{slug}`**, np. `/pl/wielkopolskie/klasztorne`, `/en/wielkopolskie/klasztorne`. **Slug województwa po polsku w obu językach** — to nazwa własna. **Krótki adres: separator `/l/{slug}`** (`fisherya.com/l/klasztorne`) — przekierowanie 302 na canonical w języku z cookie, a bez niego z nagłówka przeglądarki; domyślnie PL. Litera separatora do potwierdzenia przy implementacji (jedna linijka). **Historii slugów nie prowadzimy** — ręczna zmiana sluga przez admina świadomie unieważnia stary krótki adres. Województwo staje się **wymagane do publikacji** (bez niego nie ma canonical). |
| 4 | Strona łowiska: najpierw to, po co wędkarz klika — mapa łowiska i kalendarz; opis tekstowy obok; dotychczasowa treść w drugiej zakładce | Scala ekrany 3 i 4 v2: znika podstrona `/kalendarz` i skrót kalendarza. Obie zakładki w jednym dokumencie, żeby treść „Szczegółów" była indeksowana, a przełączanie było bez przeładowania. | **Zdjęcia w nagłówku strony, nad nazwą łowiska.** Pod nazwą dwie zakładki: **„Mapa i terminy"** (domyślna) i **„Szczegóły"**, obie w jednym dokumencie HTML, bez przeładowania (adres `#szczegoly`). Zakładka 1: lewa, szeroka kolumna — mapa łowiska (obrazek) i kalendarz; prawa — box z „ceną od" i telefonem, pod nim opis tekstowy łowiska. Zakładka 2: dotychczasowa treść v2 (akwen, zanim przyjedziesz, dojazd, udogodnienia, stanowiska, cennik, regulamin, linki). **Telefon: nazwa → mapa → kalendarz → cena i telefon → opis → zdjęcia.** |
| 5 | Kalendarz bez grupowania od samego początku; jeśli grupy zostają — przemyśleć kryterium, bo stanowisko bywa w kilku grupach | Nasze łowiska mają 26 (Klasztorne) i 21 (Łopienno) stanowisk — więcej niż „kilka–kilkanaście", ale siatka z przyklejonym nagłówkiem i kolumną stanowisk mieści się na desktopie. Grupowanie z regułą „pierwszej grupy" było sztuczne. Filtr zamiast grupowania rozwiązuje wiele grup naturalnie — to przynależność do zbioru. W etapie 2 (bez zajętości) wszystkie wiersze mają te same ceny; to świadoma redundancja, bo etap 3 dochodzi wtedy bez zmiany układu. | **Kalendarz to płaska siatka: stanowisko = wiersz, w kolejności etykiet**, od etapu 2. Nad siatką **przełączniki filtrujące**: grupy stanowisk (jedna naraz albo „Wszystkie") i cechy filtrowalne (łączone przez I). Stanowisko w kilku grupach pokazuje się w każdej z nich po wybraniu — nic się nie dubluje, bo naraz widać jeden wybór. Nazwy grup stanowiska — drobnym tekstem w nagłówku wiersza. **Znika wiersz podsumowania grupy i „… N kolejnych stanowisk"**; ograniczenie albo usługa obowiązkowa obejmująca wiele stanowisk — jako notka nad siatką i znacznik w wierszach. Ta sama zasada na telefonie (lista stanowisk dla wybranej doby) i w zakładce „Szczegóły" (lista stanowisk). |
| 6 | W szczegółach link do zewnętrznej strony łowiska | Wiele łowisk ma tylko fanpage. | **Nowe dane łowiska: adres strony WWW i adres profilu na Facebooku** (oba opcjonalne, walidowane jako URL). Pokazywane w zakładce „Szczegóły" i w boxie kontaktowym. Link z `rel="noopener"`. |

⚠️ **Krótki adres jest praktycznie nieodwracalny** — łowiska wydrukują go na banerach i ulotkach.
Rozstrzygnięcie spełnia kryterium ADR (zasięg: cały portal; koszt odwrócenia: wydrukowane linki;
uzasadnienie nie wynika z kodu) — **ADR do założenia przy zadaniu szkieletu portalu**, przed
pierwszą trasą łowiska, z treścią pkt 3 jako rekomendacją.

## Kryteria akceptacji

- [ ] Specyfikacja portalu dla etapów 1–2 w `docs/project/mockups/portal-v2/` (plik `README.md`
      albo osobny dokument specyfikacji): przypisanie ekranów i elementów do etapów, ustalenia
      z pkt 2, kalendarz (pkt 3), definicja „ceny od", filtry, dane łowiska do uzupełnienia,
      lista zmian etapu 4, decyzje techniczne.
- [ ] Makiety v2 w `docs/project/mockups/portal-v2/` dla ekranów 1–4 i landingu dla właścicieli,
      w wyglądzie v1, na prawdziwej konfiguracji (nie „Zielona Dolina"): **głównie Klasztorne**,
      a wariant kalendarza z dopłatą „stanowisko tylko dla Ciebie" — **na danych Łopienna**;
      lista wyników pokazuje oba łowiska:
  - strona główna i wyniki w wariancie etapu 2 (bez dostępności i kont);
  - strona łowiska w wariancie etapu 2 (kontakt zamiast panelu rezerwacji);
  - kalendarz w dwóch wariantach: etap 2 (bez zajętości) i etap 3 (z zajętością), z wyciągiem
    zasad, rozbiciem ceny z dopłatą „stanowisko tylko dla Ciebie", pakietem weekendowym
    i świątecznym, blokadą z powodem oraz usługą obowiązkową; także w wersji mobilnej.
- [ ] `docs/project/design/fisherya-design.html` (v1) pozostaje bez zmian.
- [ ] Każdy element makiet v2 ma w konfiguracji źródło danych albo jest na liście braków (pkt 6).
- [ ] Specyfikacja zapisuje rozstrzygnięcia z `/review-task` (cena od, filtry, dane łowiska z pkt 6,
      kalendarz wspólny, parametry z 021, usługi obowiązkowe obok ceny) i odsyła do ADR-020 w sprawie
      Tailwinda.
- [ ] Z pkt 8 i pkt 6 wynika lista zadań implementacyjnych etapów 1–2, wpisana do roadmapy.
- [x] **v3 (pkt 9):** specyfikacja i makiety w `docs/project/mockups/portal-v3/`: strona główna z listą
      alfabetyczną bez wyszukiwarki i mapy; strona łowiska z zakładkami „Mapa i terminy" i „Szczegóły";
      kalendarz jako płaska siatka z przełącznikami grup i cech (etap 2 i 3); wariant Łopienna z dopłatą;
      wersja mobilna w kolejności z pkt 9.4; schemat adresów (canonical z województwem, krótki `/l/{slug}`);
      linki WWW i Facebook łowiska.
- [x] Roadmapa zaktualizowana o skutki pkt 9 (filtry, mapa wyników i współrzędne — „na później" z progiem 12 łowisk).

## Zakres testów

- **Tier:** T1 — pusty
- **Uruchamiamy:** —
- **Uzasadnienie:** zadanie zmienia wyłącznie `docs/**` (specyfikacja i makiety HTML w katalogu
  dokumentacji). Nie dotyka kodu wykonywalnego, widoków Blade, tras, tłumaczeń ani konfiguracji.

## Zakres wyłączeń

- **Implementacja** czegokolwiek — powstaje w zadaniach wynikających z tego.
- **Przerysowanie ekranów etapu 4** (konfigurator, finalizacja, potwierdzenie, konto) — tylko lista
  zmian (pkt 7).
- **Widok zasad sprzedaży jako osobny ekran** — zasady pokazuje kalendarz i krótki wyciąg.
- **Wariacje zasad związane z usługami dodatkowymi** w wyciągu.
- **Pozwolenia sezonowe w portalu** (P9), **klikalna mapa stanowisk** (na później), **status
  „Do potwierdzenia przez łowisko"**, **„Zgłoś problem — zwracamy całość"**, **stan „czeka na
  płatność"**.
- **Zmiana wyglądu** zaakceptowanego w v1 (znak, paleta, typografia, komponenty).
- **Panel operatora i panel administratora** z „Mapy ekranów" v1.

## Zmiany dokumentacji

- [ ] `docs/project/mockups/portal-v2/` — specyfikacja i makiety (wynik zadania).
- [x] `docs/project/mockups/portal-v3/` — specyfikacja i makiety v3 (pkt 9); w `portal-v2/README.md` odsyłacz do v3.
- [ ] `docs/project/ROADMAPA.md` — etap 1 i 2: lista zadań implementacyjnych wynikająca z tego
      zadania; klikalna mapa na liście „na później".
- [ ] `docs/project/DECYZJE-I-TODO-BIZNESOWE.md` — jeśli specyfikacja rozstrzygnie coś, co
      dotyczy obowiązków informacyjnych (parametry plasowania).
- [ ] `docs/adr/` — ADR dla Tailwinda portalu albo aktualizacja ADR-016, jeśli spełnia kryterium.
- [ ] `CHANGELOG.md` — bez wpisu (brak zmian widocznych dla użytkownika).

## Ograniczenia techniczne

- Wygląd z v1 obowiązuje: znak, paleta, typografia, komponenty (`fisherya-design.html`, sekcje
  „Kolory", „Typografia", „Komponenty"; `znak_fisherya` / `KSIEGA-ZNAKU.md`).
- Makiety v2 jako samodzielne pliki HTML, jak dotychczasowe makiety w `docs/project/mockups/`.
- Portal od pierwszego dnia wielojęzyczny (PL/EN) — `CLAUDE.md`, „Język".
- Dane w makietach pochodzą z rzeczywistej konfiguracji Łopienna i Klasztornego
  (`WYMAGANIA-SPRZEDAZ-KROTKOTERMINOWA.md` §2 i §6), żeby makieta była jednocześnie testem
  konfiguracji.
- Źródłem sprzedawalności i ceny jest warstwa oferty (`cennik.md` §5, ADR-015); źródłem dostępności
  doby — `PositionAvailability` (ADR-012).

## Rozstrzygnięcia
<!-- Punktowe decyzje ustalone przy /review-task: wygląd, copy, próg, nazwa, umiejscowienie.
     Wiążą implementację tak samo jak decyzje z ADR-ów, ale nie mają zasięgu poza zadaniem.
     Format: decyzja + jednozdaniowe uzasadnienie. -->
- **Makiety v1 zostają bez zmian, v2 w osobnym katalogu** (`docs/project/mockups/portal-v2/`) —
  wygląd v1 jest zaakceptowany i ma pozostać punktem odniesienia.
- **Ustalenia z przeglądu v1 (24.09.2026)** — tabela w pkt 2 wiąże specyfikację.
- **Zasady sprzedaży pokazuje kalendarz z krótkim wyciągiem**, bez osobnego widoku i bez wariacji
  usług dodatkowych.
- **Polityka zwrotu — dopiero w koszyku przed zakupem; dokumenty — przy rejestracji** (etap 4).
- **Usługi obowiązkowe widoczne w kalendarzu.**
- **Braki w danych łowiska (pkt 6) uzupełniamy** — kształt ustala to zadanie, implementacja
  w etapie 1 albo 2.

Ustalone przy `/review-task`, 24.09.2026:

- **Parametry z 021 — wszystkie cztery na stronie łowiska** (sekcja „Zanim przyjedziesz"), karta
  wędkarska i no-kill także w wyciągu zasad. Pola powstały w 021 właśnie dla opisu oferty (O17).
- **Usługi obowiązkowe w kalendarzu — obok ceny, nie doliczone.** Zgodne z 020, które przesunęło
  usługi w warstwie oferty do etapu 4; wędkarz i tak widzi wszystko przed kontaktem.
- **Makiety: głównie Klasztorne, wariant z dopłatą na danych Łopienna.** Klasztorne pokrywa weekend,
  święta, przedsprzedaż i blokadę; dopłata istnieje tylko w Łopiennie.
- **Landing dla właścicieli — etap 1, jedna prosta strona** z makietą v2.
- **„Cena od" = najniższa stawka za łowiącego za dobę**, bez dopłat, obniżki i usług, liczona jedną
  metodą w warstwie cennika — prosta i porównywalna między łowiskami.
- **Filtry:** cecha filtrowalna spełniona przy ≥ 1 stanowisku w sprzedaży z tą cechą; pusta wartość
  nie spełnia; karta wędkarska i no-kill jako filtry; łączenie przez I.
- **Kalendarz: wspólne dane, osobne widoki** — wspólny widok wiązałby portal z Filamentem.
- **Dane łowiska (pkt 6):** współrzędne z geokodowania z ręczną korektą; kontakt na łowisku; slug
  z nazwy, unikalny i stały; **publikację ustawia właściciel** (z ostrzeżeniem o brakach
  konfiguracji, bez wymuszania); `positions_count` znika na rzecz liczby wyliczanej.
  ⚠️ Publikacja przez właściciela oznacza, że niedokonfigurowane łowisko może trafić do portalu —
  przy D4 (za błąd konfiguracji odpowiada Fisherya) specyfikacja ma opisać, co ostrzeżenie sprawdza.
- **Tailwind portalu — ADR-020** (spełnia trzy warunki: wiąże portal, stronę publiczną i motyw paneli;
  migracja po zbudowaniu portalu oznacza przepisanie wielu szablonów; uzasadnienie nie wynika z kodu).

Ustalone przy przeglądzie makiet v2, 30.09.2026 (pełna dyskusja — pkt 9):

- **Strona główna dla wędkarza, lista łowisk alfabetycznie, bez wyszukiwarki, mapy i banera dla łowisk**
  — przy dwóch łowiskach mapa i wyszukiwarka pokazują małą adopcję; wracają po przekroczeniu 12 łowisk.
- **Bez ekranu wyników; filtry, mapa wyników i współrzędne łowiska — „na później"** z tym samym progiem.
- **Canonical `/{język}/{województwo}/{slug}`, slug województwa po polsku, routing po samym slugu**
  (zły segment województwa → 301); **krótki adres `/l/{slug}`** z przekierowaniem 302 na wersję
  językową; **bez historii slugów**; województwo wymagane do publikacji. ADR przy szkielecie portalu.
- **Strona łowiska: zdjęcia nad nazwą, zakładki „Mapa i terminy" (domyślna) i „Szczegóły"** w jednym
  dokumencie; box z ceną i telefonem, pod nim opis. Telefon: nazwa → mapa → kalendarz → cena → opis → zdjęcia.
- **Kalendarz: płaska siatka stanowisk od etapu 2, grupy i cechy jako przełączniki filtrujące** —
  wiele grup na stanowisko przestaje być problemem; redundancja cen w etapie 2 jest świadoma.
- **Link do strony WWW i profilu na Facebooku łowiska** w „Szczegółach" i w boxie kontaktowym.

## Powiązane ADR-y

- [ADR-020 — Wersja Tailwinda dla portalu wędkarza i jeden potok zasobów front-endu](../adr/ADR-020-tailwind-portalu-i-potok-zasobow.md) — **Decyzja: A** (cały projekt na Tailwind 4, jeden potok Vite).
