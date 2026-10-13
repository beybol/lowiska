# 051 — Koszt obliczeń i renderu kalendarza rezerwacji

## Opis problemu

Po usunięciu powtarzanych zapytań (050) klik w dzień kalendarza rezerwacji na stagingu kosztuje głównie
obliczenia PHP budowy siatki i render widoku. Koszt rośnie ze stanowiskami i dobami w oknie.

**Stan wyjściowy — staging po wdrożeniu 050** (Flare, projekt 112055, 13.10.2026, ślady `55438136`, `cc711fbc`):

| | Przed 050 (12.10) | Po 050 (13.10) |
|---|---|---|
| klik w dzień — czas | 892–1164 ms | 629–807 ms |
| zapytania (czas w bazie) | 123–126 (387–462 ms) | 49 (182–240 ms) |
| wywołanie `selectCell()` | 532–659 ms, 84 zapytania | ~300 ms, 17 zapytań (57–84 ms baza → **~220–240 ms PHP**) |
| render | 241–409 ms, 27–30 zapytań | **204–331 ms**, 20 zapytań (~75 ms baza) |

Lokalnie (kontener, łowisko 2: 4 stanowiska, okno 14 dni, 13.10.2026): siatka oferty (`SaleCalendar`) ~66 ms,
siatka rezerwacji ~35 ms; render i reszta kliknięcia ~50–65 ms.

Rozpoznane przyczyny (profilowanie przy `/review-task`):

1. **Siatka oferty liczy wszystkie komórki okna**, a warstwa „Rezerwacje" pokazuje cenę wyłącznie w dobach
   wolnych i nieminionych (`manage-calendar-reservations.blade.php`: `$offer = ($cell->past || $cell->occupied) ? null : …`).
   Każda komórka to `StayOffer::shortestOffer()` — kilka prób długości pobytu z wyceną.
2. **Wycena w formularzu rezerwacji liczy się kilka razy na żądanie.** W `ReservationFormSchema` domknięcie
   `$quote($get)` woła `ReservationFormQuote::for()` co najmniej 5 razy (podgląd ceny, widoczność i wymagalność pola
   powodu, widoczność i wymagalność wyjątku usług), za każdym razem z pełną wyceną (cennik, usługi, sloty, cechy).
   Stąd zapis z kalendarza (`callMountedAction`, ślady `526277ac`, `fe907ef8`, 939–1325 ms, 114–115 zapytań
   z powtórzeniami po 6–7×) i wolniejsze formularze rezerwacji w obu panelach.
3. **Widok telefonu renderuje się przy każdym kliknięciu także na komputerze** (ukryty klasą `md:hidden`)
   i liczy wolne doby dla każdego stanowiska (`freeNightsFrom()`), listy przyjazdów, wyjazdów i obecnych.
4. **Klik w pasek rezerwacji albo blokady przerysowuje całą stronę**, choć zmienia tylko popover
   i podświetlenie klikniętego paska.

## Wymagania

- **Oferta tylko tam, gdzie widoczna.** Warstwa „Rezerwacje" prosi warstwę oferty wyłącznie o doby wolne
  i nieminione (te, w których komórka pokazuje cenę). Warstwa „Oferta" i kalendarz portalu liczą jak dziś
  (pokazują powody odmowy także w dobach zajętych). Wybór dób należy do wołającego (`ReservationCalendar` /
  `ManageCalendar`), nie do warstwy oferty — warstwa oferty nie liczy niczego własnego (ADR-015).
- **Wycena formularza raz na stan.** `ReservationFormQuote` dla tego samego stanu formularza (stanowisko,
  start, doby, skład, usługi, edytowana rezerwacja) liczy się raz na żądanie; kolejne wywołania domknięcia
  `$quote($get)` biorą wynik z pamięci. Zmiana stanu (inna wartość pola) liczy wycenę od nowa.
- **Widok telefonu jako wyspa leniwa** (Livewire 4 `@island`, tryb leniwy): nie renderuje się ani nie liczy
  przy kliknięciach w siatce komputera; ładuje się, gdy jest potrzebny. Zachowanie widoku telefonu bez zmian
  (pasek dni, listy, arkusz od dołu, `quote()` renderless).
- **Klik w pasek rezerwacji albo blokady przerysowuje tylko popover** (wyspa). Podświetlenie wybranego paska
  idzie z klasy zależnej od stanu po stronie przeglądarki (`$wire.selReservation` / `$wire.selBlock`), bez
  przerysowania siatki. Zamknięcie popovera (✕, Esc, klik poza kalendarzem) — tak samo.
- **Klik w dzień zostaje pełnym renderem** (nakładka zaznaczenia i podgląd kształtu zależą od reguł
  `selectCell()` i `CalendarBarShape` — bez ich drugiej kopii w JS).
- Pomiar przed i po: lokalnie (czas siatki oferty, liczba wycen formularza, czas kliknięcia w dzień i w pasek,
  liczba zapytań zapisu z kalendarza) i po wdrożeniu we Flare (staging) — zapisany w zadaniu.

## Kryteria akceptacji

- [ ] Test: w warstwie „Rezerwacje" warstwa oferty jest pytana wyłącznie o doby wolne i nieminione; ceny
      w komórkach bez zmian (istniejące asercje `ReservationCalendarTest` przechodzą bez zmian).
- [ ] Test: warstwa „Oferta" i kalendarz portalu bez zmian (istniejące testy przechodzą bez zmian asercji).
- [ ] Test liczby zapytań: render formularza rezerwacji z wypełnionym stanem wycenia raz (zapytania o cennik
      i usługi po jednym razie); zapis z kalendarza (`quickBook`) bez powtórzeń tych zapytań po 5–7×.
- [ ] Test: klik w pasek rezerwacji i blokady nie przerysowuje siatki (odpowiedź zawiera wyłącznie wyspę popovera),
      popover pokazuje właściwą rezerwację/blokadę, akcje popovera działają.
- [ ] Test: widok telefonu nie renderuje się w odpowiedzi na klik w siatce komputera; po załadowaniu wyspy działa
      jak dziś (istniejące testy widoku telefonu przechodzą).
- [ ] Pomiar lokalny przed i po zapisany w zadaniu; pomiar stagingu (Flare) po wdrożeniu dopisany do zadania
      (bez twardego progu — zależy od danych łowiska).
- [ ] Testy z zakresu T2 zielone; **pełny pakiet odroczony** na koniec sesji (`/review-implementation`, Krok 1).

## Zakres testów

- **Tier:** T2
- **Uruchamiamy:** `docker compose exec app php artisan test --filter="SaleCalendarTest|SaleCalendarPageTest|PortalCalendarTest|ReservationCalendarTest|ReservationPanelTest|ReservationReviewFixesTest|ReservationServicesTest|StayOfferTest|FisheryReadCacheTest"`
- **Uzasadnienie:** zmiana dotyka `SaleCalendar`/`ReservationCalendar` (wołający warstwy oferty, także portal),
  formularza rezerwacji (`ReservationFormSchema`, `ReservationFormQuote`, oba panele) i widoku kalendarza; nie
  rusza rdzenia dostępności ani niczego z listy wyzwalaczy T3. Pełny pakiet — odroczony na koniec sesji.

## Zakres wyłączeń

- Powtarzane zapytania w rdzeniu dostępności — zadanie 050.
- Trwały bufor werdyktu między żądaniami (`dostepnosc.md` §2, ADR-029 reguła 7).
- Klik w dzień jako wyspa (nakładka i podgląd kształtu rysowane w przeglądarce) — wymagałby drugiej kopii reguł
  `selectCell()`/`CalendarBarShape` w JS.
- Zmiany w algorytmie najkrótszego pobytu (`StayOffer::shortestOffer()`) i w kolejności warunków dostępności.

## Zmiany dokumentacji

- [ ] `docs/conventions/rezerwacje.md` — §4: oferta liczona tylko dla dób wolnych i nieminionych; wyspy (popover
      paska, widok telefonu) i zasada „klik w dzień = pełny render, bez reguł w JS"
- [ ] `docs/conventions/cennik.md` — jeśli zmieni się sposób wołania warstwy oferty przez kalendarz (wybór dób
      po stronie wołającego)
- [ ] `CHANGELOG.md` — wpis w changelogu

## Ograniczenia techniczne

- Livewire 4.4 (`@island`, wyspy leniwe, wywołania z metadanymi wyspy), Filament 5 (akcje w popoverze muszą
  działać z wnętrza wyspy — modale `quickEdit`, `confirm`, `cancel` itd.).
- Granica ADR-015: warstwa oferty nie liczy niczego własnego — wybór dób to decyzja wołającego.
- Kolejność warunków dostępności bez zmian (`dostepnosc.md` §2).
- Zapamiętana siatka na żądanie (`ManageCalendar::getReservationGrid()`) i `forgetReservationLayer()` po zapisie
  zostają (`rezerwacje.md` §4).

## Pomiar po implementacji (lokalnie, kontener, łowisko 2: 4 stanowiska, okno 14 dni, 13.10.2026)

Pomiar A/B na tym samym hoście (kod sprzed 051 odłożony przez `git stash`), harness testowy Livewire, mediana z 9:

| | Przed 051 | Po 051 |
|---|---|---|
| budowa siatki „Rezerwacje" (oferta + rezerwacje) | 82 ms | **64 ms** (oferta tylko dla dób wolnych i nieminionych) |
| klik w dzień (`selectCell`, pełny render) — zapytania | 39 | **34** |
| klik w pasek rezerwacji — zapytania | **392** | **13** (wyspa popovera) / 37 (pełny render w teście) |
| klik w pasek — odpowiedź | 256 KB HTML, ~575 ms | **17 KB fragmentu wyspy, ~44 ms** |
| wycena w formularzu rezerwacji (`price_rules` na render) | 3 | **1** |
| widok telefonu na komputerze | renderowany i liczony przy każdym kliku | **nie renderuje się** (szkielet, `wire:intersect`) |

Klik w dzień czasowo bez wyraźnej zmiany lokalnie (150–250 ms, duży rozrzut hosta): pełny render został z założenia,
a wyspa popovera kosztuje tu ~11 ms, z czego ~9 ms to jeden `file_exists` na dysku Windows podpiętym do kontenera
(`docker/php/dev.ini` — `stat` ~9 ms); na stagingu ten koszt jest pomijalny. Wiarygodne lokalnie są liczby zapytań
i rozmiary odpowiedzi; czasy — Flare po wdrożeniu.

Odkrycie przy pomiarze: popover czytał rezerwację **31 razy po 7 zapytań** (`selectedReservation()` i `record()`
sześciu akcji, które Filament rozwiązuje wielokrotnie) — stąd 392 zapytania na klik w pasek przed 051. Teraz rekord
popovera czyta się raz na żądanie (`scopedReservation()`/`scopedBlock()` z pamięcią po id), a akcje zapisujące czyszczą
pamięć w `after()`.

**Do uzupełnienia po wdrożeniu:** ślady Flare (projekt 112055) — klik w dzień, klik w pasek (`livewire-…/update`
z wyspą), zapis z kalendarza (`callMountedAction`).

## Rozstrzygnięcia

- **Zakres: oferta tylko dla widocznych dób + wycena formularza raz na stan + odchudzenie renderu** (autor,
  13.10.2026) — wszystkie trzy przyczyny z profilowania w jednym zadaniu.
- **Render: widok telefonu jako wyspa leniwa i wyspa popovera dla kliknięć w paski; klik w dzień zostaje pełnym
  renderem** — pełne wyspy dla kliknięcia w dzień wymagałyby drugiego literału reguł `selectCell()` i kształtu
  w JS, co w tym projekcie jest defektem.
- **Kryteria: progi i testy lokalne + pomiar stagingu bez twardego progu** — czasy na stagingu zależą od danych
  łowiska, więc zadanie ma się dać domknąć lokalnie.
- **Zakres testów: T2** — nie rusza rdzenia dostępności ani listy wyzwalaczy T3; pełny pakiet na koniec sesji.
- Bez ADR: wszystkie decyzje dotyczą jednej strony i formularza rezerwacji, odwracają się lokalnie.

## Powiązane ADR-y

- [ADR-015](../adr/ADR-015-warstwa-oferty-pobytu.md) — warstwa oferty (wybór dób po stronie wołającego)
- [ADR-029](../adr/ADR-029-pamiec-odczytow-dostepnosci-na-zadanie.md) — magazyn odczytów na żądanie (bez zmian)
