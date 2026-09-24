# 020 — Usługi dodatkowe: jednostka rozliczenia, zasięg i dostępność na stanowisku

> **Pochodzenie:** numer z planu realizacji ([wymagania, §14.1](../../project/WYMAGANIA-SPRZEDAZ-KROTKOTERMINOWA.md)),
> zarezerwowany 20.09.2026 i uzupełniony przez `/create-task` 23.09.2026, po zadaniu 019.
> Przerobiony dwukrotnie tego samego dnia po uwagach do definicji (patrz `## Rozstrzygnięcia`).
> **Moduł:** M6 (zależy od doby M1 i stanowisk M5, oba zrealizowane). Rozstrzyga K18 po stronie
> usług (G13). Obejmuje **usługi w kalendarzu podglądowym** jako obraz konfiguracji (G4 dla M6).
> **Przegląd:** wspólny przegląd po pakiecie 017–021 (§14.2; zastąpił punkty kontrolne B i C).

⚠️ **Implementacja rusza dopiero po zakończeniu zadania 023**, łącznie z jego testami mutacyjnymi.
023 zmienia `SaleCalendar`, `SaleCalendarGrid` i `ManageCalendar`, a 020 rozszerza kalendarz na
tych samych plikach i buduje na ich stanie po 023.

Makieta: [`makieta-020-uslugi-dodatkowe.html`](../../project/mockups/makieta-020-uslugi-dodatkowe.html)
— **propozycja do przeglądu**, zgodna z tą wersją zadania (23.09.2026). Tam, gdzie odpowiada na
pytania otwarte, niesie wariant rekomendowany, a nie decyzję. Staje się wiążąca po `/review-task`.
⚠️ **Po `/review-task` (24.09.2026) makieta rozjeżdża się z zadaniem w trzech miejscach, a wiążą
rozstrzygnięcia:** usługi w kalendarzu to **plakietka + podpowiedź**, nie rozwijany wiersz;
„Odepnij usługę" jest **w zakresie**; zmiana zasięgu na „całe łowisko" **usuwa przypięcia**.

## Opis problemu

Usługa dodatkowa (`AdditionalService`) zna dziś cenę jako **liczbę bez jednostki** oraz
`available_count` jako **pulę bez znaczenia w czasie** („mamy 3 łódki"). Do tego:

- **Brak jednostki rozliczenia.** Łódka w Łopiennie kosztuje 20 zł **za dobę**; pięciodobowy pobyt
  z łódką nie ma dziś z czego się policzyć (O13).
- **Limit nie ma znaczenia w czasie.** Pytanie brzmi „czy w **tę** dobę jest jeszcze wolna łódka",
  a nie „ile łódek ma łowisko" (O13).
- **Usługa nie widzi ograniczeń stanowiska.** Przyczepa przypięta do st. 22–40 byłaby do kupienia
  mimo dzisiejszego zakazu wjazdu (O21 zakłada, że usługa i ograniczenie „się składają").
- **Usługa nie może być darmowa**, choć O21 („postawienie przyczepy") tego wymaga.
- **Zasięg usługi zależy od pustej listy przypięć.** Nie wiadomo, czy usługa bez przypięć jest
  dostępna na całym łowisku, czy nigdzie.
- **Przypięcie usługi do wielu stanowisk** wymaga edycji każdego z osobna.
- **Operator nie widzi usług w kalendarzu podglądowym** (019 zbudowało go bez nich).

**Stan w kodzie (rozpoznanie z 23.09.2026):**
- `additional_services`: `name`, `description`, `price` (decimal, akcesor formatujący przecinek),
  `available_count` (nullable int), `is_active`, `fishery_id`, soft delete, `LogsActivity`.
- `additional_service_position` z `is_required`. Zapis idzie wyłącznie przez
  `AdditionalServiceSync`, z bramką przynależności do łowiska.
- `additional_service_long_term_permit` istnieje, ale **nie ma relacji Eloquenta ani UI** po
  stronie `LongTermPermit`.
- `AdditionalServiceResource` jest wspólny dla obu paneli (`panel-admina.md`). Ekran łowiska to
  `FisheryResource\Pages\ManageAdditionalServices` i deleguje do zasobu.
- Cechy stanowisk mają trzy typy (`PositionAttributeType`: flaga, liczba, wybór). Ograniczenie
  zawiesza cechę typu flaga w dobie; odpowiada za to `PositionAvailability::suspendedAttributes()`.
- Akcja zbiorcza `PositionResource::setAttributeBulkAction()` („Ustaw cechę") jest wzorem dla
  akcji na zaznaczeniu stanowisk.
- **Modelu rezerwacji nie ma**, więc nie ma sprzedanych egzemplarzy do odliczenia ani nikogo, kto
  wycenia pobyt z usługami.

## Wymagania

### 1. Jednostka rozliczenia — dwie wartości i liczba egzemplarzy

- Usługa dostaje **wymaganą jednostkę rozliczenia** z **dwóch** wartości:

  | Jednostka | Rachunek | Przykłady |
  |---|---|---|
  | **za dobę** | cena × liczba dób pobytu × liczba | łódka, hamak, postawienie przyczepy, wywózka pontonem |
  | **za pobyt** | cena × liczba | pellet, lód, drewno; prysznic, jeśli jest bez limitu na cały pobyt |

- **Liczba** to liczba egzemplarzy wybrana przez wędkarza — elementy są policzalne, więc „liczba",
  nie „ilość". Najczęściej wynosi 1.
- „Za sztukę" i „za wejście" z pierwotnego F8 **nie powstają**. „Za sztukę" to „za pobyt"
  (towar) albo „za dobę" (wypożyczenie) razy liczba. „Za wejście" (prysznic płacony za każde
  użycie) nie da się sprzedać z góry, bo wędkarz nie zna liczby wejść — taka opłata zostaje **na
  miejscu** i opisuje ją treść oferty łowiska, a nie usługa w portalu (P16).
- Rachunek z tabeli jest **regułą jednostki**, zapisaną w `cennik.md`. Kod liczący kwotę usługi
  dla pobytu powstaje razem z modułem zakładania rezerwacji, który jest jego pierwszym odbiorcą
  (patrz „Zakres wyłączeń").
- Kontrolka jednostki w formularzu: `ToggleButtons` z dwiema opcjami. Tabela usług pokazuje cenę
  z jednostką („20,00 zł / doba", „15,00 zł / pobyt").
- Istniejące usługi dostają przy migracji jednostkę **„za dobę"** — aplikacja nie działa
  produkcyjnie, więc wartość domyślna nie niesie ryzyka.

### 2. Cena usługi może wynosić 0,00

- Minimum pola ceny usługi schodzi do **0,00** (`SharedFormComponents::getPriceInput()` bierze
  minimum jako parametr — tak jak stawka). Usługa darmowa („postawienie przyczepy", O21) nadal
  niesie deklarację wędkarza i limit egzemplarzy.
- Usługa z ceną 0,00 pokazuje się jako **„bezpłatna"**, nie „0,00 zł / doba".

### 3. Limit egzemplarzy — zawsze w każdej dobie

- `available_count` znaczy **liczbę egzemplarzy dostępnych w każdej dobie**. Egzemplarz wzięty do
  pobytu jest zajęty **w każdej dobie tego pobytu** — przy obu jednostkach (przyczepa stoi cały
  pobyt, łódka jest wypożyczona na każdą dobę).
- `available_count = null` — usługa bez limitu. Towar („za pobyt": pellet, lód) zwykle nie ma
  limitu.
- **Nie ma przełącznika „limit globalny / w każdej dobie".** Pula globalna odtwarza błąd z O13,
  a limit zapasu towaru („mamy 20 worków") nie ma dziś łowiska, które go potrzebuje (R5).
- Odliczanie sprzedanych egzemplarzy powstaje z modelem rezerwacji. To zadanie ustala wyłącznie
  **znaczenie** liczby; nie buduje kontraktu zajętości.

### 4. Zasięg usługi — jawne pole

- Usługa dostaje pole **„Dostępna na"**: **całym łowisku** albo **wybranych stanowiskach**.
- „Wybrane stanowiska" czyta istniejące przypięcia (`additional_service_position`). Przy braku
  przypięć usługa jest dostępna **nigdzie**, a formularz i lista pokazują ostrzeżenie. Odpięcie
  ostatniego stanowiska **nie zmienia** usługi po cichu w ogólnołowiskową.
- „Całe łowisko" obejmuje każde stanowisko łowiska, także dodane później. To właściwość
  **usługi**, nie grupy, więc nie łamie F5.
- **Usługa na całym łowisku nie może być obowiązkowa.** `is_required` żyje na przypięciu do
  stanowiska; stała opłata za pobyt niezależna od stanowiska nie ma łowiska, które jej potrzebuje
  (R5), a stała opłata od osoby i doby to dopłata `everyone` w cenniku.
- Istniejące usługi dostają przy migracji zasięg **„wybrane stanowiska"**; te bez przypięć
  pokazują ostrzeżenie.
- **Zmiana zasięgu z „wybrane stanowiska" na „całe łowisko" usuwa wszystkie przypięcia usługi**
  (razem z `is_required`). Przed zapisem formularz pyta o potwierdzenie i podaje liczbę
  przypięć, które znikną. Ślad zostaje w dzienniku zmian. Powrót na „wybrane" zaczyna od pustej
  listy przypięć.
- Repeater usług w formularzu stanowiska (`PositionResource`) oraz akcje „Przypnij" i „Odepnij"
  oferują **wyłącznie usługi o zasięgu „wybrane stanowiska"**. Zapis przypięcia usługi
  ogólnołowiskowej jest odrzucany także po stronie serwera (`AdditionalServiceSync` i akcje
  zbiorcze) — lista opcji w formularzu tego nie gwarantuje.

### 5. Wymagane cechy stanowiska

- Usługę można powiązać z **dowolną liczbą cech typu flaga** (`PositionAttribute`), np.
  przyczepa ↔ „wjazd pojazdem". Formularz pokazuje do wyboru **wyłącznie flagi** — tylko flagę
  da się zawiesić ograniczeniem (016), a „ma cechę" dla liczby albo wyboru nie ma jednego znaczenia.
- Usługa jest **niedostępna na stanowisku w danej dobie**, gdy **brakuje którejkolwiek**
  z wymaganych cech:
  - stanowisko ma ją ustawioną na „nie", **albo nie ma jej wartości wcale** (trzeci stan
    „nikt się nie wypowiedział" liczy się jako brak — bezpieczniej nie sprzedać przyczepy tam,
    gdzie nikt nie potwierdził wjazdu), albo
  - ograniczenie **zawiesza** ją w tej dobie (`PositionAvailability::suspendedAttributes()`).
- **Wymóg cechy miękko usuniętej ze słownika jest pomijany** — usunięcie cechy nie może wyłączyć
  usługi na całym łowisku. `restore()` cechy przywraca też wymóg.
- Zapis powiązań przechodzi przez **bramkę**: cecha istnieje w słowniku i jest flagą, a usługa
  należy do łowiska operatora.

### 6. Jeden dom: „jakie usługi są dostępne na stanowisku"

- Powstaje **jedna klasa w `app/Services/`** odpowiadająca na pytanie: **jakie usługi ma to
  stanowisko w danej dobie i czy każda z nich jest dostępna — a jeśli nie, to dlaczego.** Składa:
  `is_active`, zasięg (pkt 4), przypięcia z `is_required` i wymagane cechy (pkt 5).
- Zawieszenia cech bierze z `PositionAvailability`, doby z `FishingDayCalendar` — nie liczy ich
  sama (`dostepnosc.md`).
- **`PositionAvailability` dostaje nową publiczną metodę** zwracającą **wpisy** zawieszające cechy
  w dobie albo w zakresie dób (z powodem i datami), na tym samym jednym zapytaniu na instancję co
  dziś. `suspendedAttributes()` zostaje bez zmian. Klasa usług stanowiska **nie pyta o blokady
  sama** — reguła przecięcia wpisu z dobą ma jeden dom.
- Przyczyna niedostępności jest **wyjaśnialna** (G11): brak wartości cechy, cecha ustawiona na
  „nie", cecha zawieszona ograniczeniem — z powodem i zakresem dat ograniczenia.
- Klasa ma wariant dla **zakresu dób** (okno kalendarza) bez N+1: usługi, przypięcia, wymagane
  cechy i wartości cech wczytane raz.
- To miejsce jest przyszłym wejściem modułu rezerwacji do pytania o usługi stanowiska. **Warstwa
  oferty (`StayOffer`) nie zmienia się w tym zadaniu.**

### 7. Usługi w kalendarzu podglądowym — obraz konfiguracji, nie kalkulator

Kalendarz pokazuje **skutek konfiguracji**, nie liczy rachunku. Dokładne wyliczenia pobytu
z usługami należą do modułu zakładania rezerwacji (front i panele).

- Przy **nazwie każdego stanowiska** kalendarz pokazuje **plakietkę** z liczbą usług („3 usługi").
  Gdy którakolwiek jest w pokazywanym oknie niedostępna, plakietka ma **kolor ostrzegawczy**
  i dopisek („3 usługi · 1 niedostępna") — najważniejsza informacja jest widoczna bez klikania.
- **Pełna lista w podpowiedzi** plakietki: nazwa, cena podstawowa z jednostką albo „bezpłatna",
  oznaczenie „obowiązkowa", limit egzemplarzy informacyjnie („3 szt.").
- **Kolejność na liście:** najpierw obowiązkowe, potem alfabetycznie. Siatka nie dostaje nowych
  wierszy.
- Usługa, która **w pokazywanym oknie** jest na tym stanowisku niedostępna przez brak albo
  zawieszenie wymaganej cechy, jest widoczna jako niedostępna **z przyczyną i zakresem dób**, np.
  „Przyczepa — niedostępna od 01.06 (ograniczenie: wjazd pojazdem)". To najważniejsza informacja
  tej sekcji — dokładnie przypadek Klasztornego.
- **Komórki dób bez zmian.** „Ceny od" nie doliczają usług, a kalendarz nie pokazuje wolnych
  egzemplarzy („X z N") ani kwot zależnych od liczby dób czy osób.
- Listę dostarcza klasa z pkt 6. ⚠️ Reguła „kalendarz woła wyłącznie warstwę oferty" dotyczy
  **komórek** (sprzedawalność i cena pobytu); lista usług jest odczytem konfiguracji, nie ofertą.
  Wyjątek zapisuje się w `panel-wlasciciela.md` §6.
- Liczba zapytań na render nie rośnie z liczbą usług ani dób.

### 8. Akcje zbiorcze „Przypnij usługę" i „Odepnij usługę" — zamiast przypisania do grupy

Grupa jest etykietą i zapisanym zaznaczeniem. Niczego nie przekazuje stanowiskom, a jej usunięcie
nie zmienia żadnego stanowiska (F5, O11). Dlatego **nie ma przypisania usługi do grupy**.

- Akcja **„Przypnij usługę"** działa na zaznaczeniu w tabeli stanowisk i z poziomu grupy, wzorem
  „Ustaw cechę" (`setAttributeBulkAction()`). Zapisuje przypięcie **wprost na każdym objętym
  stanowisku**, z wyborem `is_required`. Zaznaczenie liczy się w chwili wykonania.
- Przypięcie już istniejące dostaje nową wartość `is_required`. Inne przypięcia stanowiska
  zostają nietknięte (dopisanie, nie `sync()` całej listy).
- Akcja jest dostępna wyłącznie dla usług o zasięgu „wybrane stanowiska".
- Po wykonaniu akcja **ostrzega**, na ilu stanowiskach usługa jest martwa, bo brakuje im wymaganej
  cechy (bez ograniczeń czasowych — to pokazuje kalendarz).
- Zapis przez **bramkę przynależności do łowiska**, wzorem `AdditionalServiceSync`:
  identyfikatory stanowisk i usług pochodzą od klienta.
- Akcja **„Odepnij usługę"** — ten sam schemat wejść (zaznaczenie w tabeli stanowisk i poziom
  grupy) i ta sama bramka. Usuwa przypięcie wskazanej usługi z objętych stanowisk; inne
  przypięcia zostają. Stanowisko bez tej usługi jest pomijane bez błędu.
- Obie akcje mają **jawną autoryzację** (`update` na stanowisku, a z poziomu grupy — na grupie,
  jak „Ustaw cechę"). Zakres widoczności tabeli nie zastępuje autoryzacji (`CLAUDE.md`).

### 9. Powiązania z pozwoleniami długookresowymi (K18, G13)

- Migracje zadania **nie ruszają** `additional_service_long_term_permit`. Test potwierdza, że
  istniejące wiersze pośrednie przeżywają migrację, a usunięcie usługi (soft delete) ich nie
  kasuje — to domyka K18 po stronie usług.
- Budowa relacji i UI pozwolenie ↔ usługa jest **poza zakresem**.

## Kryteria akceptacji

- [ ] Usługa ma wymaganą jednostkę rozliczenia (za dobę / za pobyt), pole zasięgu i powiązanie
      z wymaganymi cechami typu flaga. Wszystko jest edytowalne w formularzu wspólnym dla obu
      paneli; tabela pokazuje cenę z jednostką.
- [ ] Cena usługi przyjmuje 0,00, a usługa darmowa pokazuje się jako „bezpłatna".
- [ ] Usługa „wybrane stanowiska" bez przypięć jest niedostępna i ostrzega o tym. Usługa „całe
      łowisko" obejmuje stanowiska dodane po jej zapisie i nie da się jej oznaczyć jako
      obowiązkowej.
- [ ] Wymagane cechy: usługa jest niedostępna na stanowisku, gdy cecha jest ustawiona na „nie",
      gdy nie ma wartości, oraz w dobach, w których ograniczenie ją zawiesza; jest dostępna
      w dobach bez zawieszenia. Wymóg cechy miękko usuniętej jest pomijany. Formularz nie pozwala
      wybrać cechy innej niż flaga.
- [ ] Klasa „usługi stanowiska" zwraca dla doby i dla zakresu dób listę usług z dostępnością
      i przyczyną niedostępności (z powodem i zakresem ograniczenia), bez N+1.
- [ ] Kalendarz pokazuje przy nazwie każdego stanowiska plakietkę z liczbą usług (ostrzegawczą
      z dopiskiem, gdy któraś jest w oknie niedostępna) i pełną listę w podpowiedzi: cena
      podstawowa z jednostką, „obowiązkowa", limit, a dla niedostępnej — przyczyna i zakres dób.
      Kolejność: obowiązkowe, potem alfabetycznie. Komórki dób i „ceny od" są bez zmian. Liczba zapytań na render nie
      rośnie z liczbą usług ani dób.
- [ ] Akcje „Przypnij usługę" i „Odepnij usługę" działają na zaznaczeniu stanowisk i z poziomu
      grupy, bez naruszania innych przypięć; „Przypnij" ustawia `is_required` i ostrzega
      o stanowiskach bez wymaganej cechy. Obie mają jawną autoryzację, a bramka przynależności
      jest pokryta testem wzorem `CrossTenantRelationTest`.
- [ ] Zmiana zasięgu na „całe łowisko" usuwa przypięcia po potwierdzeniu z ich liczbą; usługi
      ogólnołowiskowej nie da się przypiąć ani w repeaterze, ani akcją, ani żądaniem z pominięciem
      formularza.
- [ ] `PositionAvailability` zwraca wpisy zawieszające cechy (z powodem i datami) bez dodatkowych
      zapytań; `suspendedAttributes()` bez zmian.
- [ ] `StayOffer`, `StayPricing` i `StaySellability` bez zmian — ich testy zielone bez edycji.
- [ ] Wiersze `additional_service_long_term_permit` przeżywają migracje (test G13).
- [ ] Nowe pola usługi i powiązania z cechami są objęte dziennikiem zmian (`LogsActivity`,
      `dziennik-zmian.md`).
- [ ] Nowe klucze tłumaczeń w `lang/pl.json` (UI po angielsku jako klucze).
- [ ] Zielony zakres T2 zadeklarowany niżej. **Pełny pakiet (T3) odroczony** na wspólny przegląd
      po pakiecie 017–021 (`/review-implementation`, Krok 1).

## Zakres testów

- **Tier:** T2 (decyzja pakietowa z §14.2 wymagań)
- **Uruchamiamy:** `docker compose exec app php artisan test --filter="AdditionalServiceResourceTest|AdditionalServicePriceTest|PositionAdditionalServicesTest|CrossTenantRelationTest|PositionGroupTest|PositionAttributeTest|AvailabilityBlockTest|PositionAvailabilityTest|StayOfferTest|LongTermPermitResourceTest|SaleCalendarTest|SaleCalendarPageTest|ActivityLoggingTest|ShieldPermissionNamesTest|AdminPanelTest|OwnerPanelTest|FisheryAccessTest"` + nowe klasy testów usług stanowiska i akcji „Przypnij usługę" (nazwy ustala implementacja; dopisać je do filtra)
- **Uzasadnienie:** zadanie trafia w **jeden** wyzwalacz T3 z `CLAUDE.md` — migracje (jednostka,
  zasięg, powiązanie usługi z cechami). Zgodnie z §14.2 zadania 014–021 tworzą jeden pakiet: każde
  deklaruje T2, a pełny pakiet biegnie na **wspólnym przeglądzie po pakiecie 017–021**. To
  **odroczenie, nie zwolnienie**. Warunek: 020 nie trafia do `docs/tasks/implemented/`, dopóki ten
  przegląd nie jest zielony. `StayOfferTest` pilnuje, że warstwa oferty się nie zmieniła.
  Polityki, `User`, providery paneli i Shield nie są dotykane.

## Zakres wyłączeń

- **Wycena usług dla pobytu i usługi w warstwie oferty** (`StayOffer`, rozbicie z usługami, odmowa
  przy niedostępnej usłudze obowiązkowej, aktualizacja ADR-015) — powstają z **modułem zakładania
  rezerwacji** (front i panele), który jest ich pierwszym odbiorcą. Reguły rachunku (pkt 1)
  i dom „usług stanowiska" (pkt 6) są na to gotowe.
- **Kontrakt zajętości i odliczanie sprzedanych egzemplarzy** — z modelem rezerwacji, wspólnie dla
  stanowisk i usług (F8, G10).
- **Usługi płatne za każde użycie** („za wejście") — zostają na miejscu, poza portalem.
- **Limit zapasu towaru** („mamy 20 worków") — brak łowiska, które go potrzebuje (R5).
- **Przypisanie usługi do grupy stanowisk** — łamałoby F5/O11. Zastępuje je akcja zbiorcza (pkt 8).
- **Flaga „liczona od liczby osób"** — stała opłata od osoby to dopłata `everyone`.
- **Wymagane cechy typu liczba albo wybór.**
- **Wybór części dób dla usługi „za dobę"** — egzemplarz zajmuje wszystkie doby pobytu.
- **Blokady egzemplarzy usług na termin** (łódka w naprawie).
- **Relacja i UI pozwolenie długookresowe ↔ usługa** — tylko test, że pivot przeżywa (G13).
- **Usługi z własnym harmonogramem** (przewodnik na godziny) — M6 „Nie teraz".
- **Obniżka przedsprzedażowa na usługach** — usługi zostają poza jej podstawą (`cennik.md`).

## Zmiany dokumentacji

- [ ] `docs/conventions/dostepnosc.md` — dom „usługi stanowiska" jako jedyne miejsce pytania
      „jakie usługi są dostępne na stanowisku w dobie i dlaczego nie": zasięg, przypięcia, wymagane
      cechy (flagi, brak wartości = brak, cecha usunięta pomijana), zawieszenia przez
      `PositionAvailability`; znaczenie `available_count` (egzemplarze w każdej dobie).
- [ ] `docs/conventions/cennik.md` — dwie jednostki usług i ich rachunek; cena usługi może wynosić
      0,00 (zdanie „inaczej niż przy usługach dodatkowych" do przepisania).
- [ ] `docs/conventions/panel-wlasciciela.md` — plakietka usług przy stanowisku w kalendarzu
      i wyjątek od reguły „kalendarz woła wyłącznie warstwę oferty"; akcje „Przypnij"/„Odepnij
      usługę"; pole zasięgu (zmiana na „całe łowisko" kasuje przypięcia) i wymagane cechy
      w formularzu usługi.
- [ ] `docs/conventions/panel-admina.md` — nowe pola we wspólnym `AdditionalServiceResource`.
- [x] `docs/project/WYMAGANIA-SPRZEDAZ-KROTKOTERMINOWA.md` — O13, O21, M6, F8, §6, P16 i §14.1
      zaktualizowane 23.09.2026 przy przeróbce tego zadania. Po implementacji: §0.1 — M6
      zrealizowane; K18 zamknięty po stronie usług.
- [x] `docs/project/KONCEPCJA-SPRZEDAZ-DOBOWA.md` — M6 zaktualizowane 23.09.2026.
- [x] `docs/project/mockups/makieta-020-uslugi-dodatkowe.html` — przerobiona 23.09.2026: dwie
      jednostki, brak wyceny i „X z N", lista usług przy stanowisku.
- [ ] `MANUAL.md` — jednostki, usługa bezpłatna, zasięg, wymagane cechy, znaczenie limitu i akcja
      „Przypnij usługę" z perspektywy operatora.
- [ ] `CHANGELOG.md` — wpis przez skill `changelog`.

## Ograniczenia techniczne

- Laravel 13, Filament 5, PHP 8.4.
- Doby liczy wyłącznie `FishingDayCalendar`, zawieszenia cech wyłącznie `PositionAvailability`.
  Wszystkie porównania dat w **strefie łowiska**.
- Pole ceny usługi przez `SharedFormComponents::getPriceInput()` z minimum 0,00. Uwaga na akcesor
  formatujący `price` w testach (`panel-wlasciciela.md`).
- Enumy (jednostka, zasięg) z `options()` i relacje z generykami.
- `AdditionalServiceResource` jest wspólny dla obu paneli — bez klas per panel.
- Autoryzacja przez istniejącą `AdditionalServicePolicy`. Nowe tabele pośrednie bez własnego
  zasobu autoryzują się przez rodzica (`autoryzacja.md` §5).
- Tłumaczenia wyłącznie w `lang/pl.json`.
- Powierzchnie do przeczytania przed edycją: `panel-admina.md`, `panel-wlasciciela.md`,
  `dostepnosc.md`, `cennik.md`, `autoryzacja.md`, `dziennik-zmian.md`.
- Implementacja po zakończeniu zadania 023 (patrz nagłówek).

## Rozstrzygnięcia

Ustalone z autorem 23.09.2026, po dwóch rundach uwag do definicji (przed `/review-task`):

- **Dwie jednostki: „za dobę" i „za pobyt", każda razy liczba egzemplarzy.** „Za sztukę" to jedna
  z nich razy liczba; „za wejście" nie da się sprzedać z góry, więc zostaje na miejscu (P16).
  Postawienie przyczepy i wywózka pontonem liczą się **za dobę**.
- **Limit egzemplarzy zawsze w każdej dobie**, bez przełącznika. Pula globalna odtwarza błąd O13,
  a limit zapasu towaru nie ma właściciela (R5).
- **Kalendarz pokazuje obraz konfiguracji, nie liczy.** Przy stanowisku lista usług z ceną
  podstawową i dostępnością wynikającą z cech i ograniczeń; bez „X z N" i bez usług w „cenach od".
  Dokładne wyliczenia należą do modułu zakładania rezerwacji.
- **Wycena usług i usługi w warstwie oferty przesunięte do modułu rezerwacji** — dziś nie mają
  odbiorcy. Nic z tego nie wymaga później migracji danych.
- **Grupy → akcja zbiorcza „Przypnij usługę".** Przypisanie przez grupę działałoby jak
  dziedziczenie, a grupa ma niczego nie przekazywać stanowiskom (F5, O11).
- **Flaga „liczona od liczby osób" usunięta.** Obowiązkowa opłata od osoby i doby to dopłata
  `everyone`; usługa ma być tym, czego da się nie wziąć.
- **Cena usługi może wynosić 0,00** — wymaga tego O21 („postawienie przyczepy").
- **Wymagane cechy: tylko flagi, brak wartości liczy się jako brak, cecha usunięta ze słownika
  jest pomijana.** Łączy to usługę z ograniczeniem, jak zakłada O21.
- **Jawne pole zasięgu** „całe łowisko / wybrane stanowiska"; usługa na całym łowisku nie jest
  obowiązkowa. Odpięcie ostatniego stanowiska nie otwiera usługi po cichu na całym łowisku.
- **Migracja:** jednostka „za dobę", zasięg „wybrane stanowiska" — aplikacja nie działa
  produkcyjnie.
- **Jednostka rozliczenia to `ToggleButtons`**, spójnie z wyborem dób (023).
- **Aktywność usługi (`is_active`) bez zmian.** Usługa nieaktywna nie trafia do listy usług
  stanowiska ani do kalendarza, a jej ustawienia i przypięcia zostają.
- **Drobne:** punkt kontrolny C zastąpiony wspólnym przeglądem po 017–021; tłumaczenia tylko
  w `lang/pl.json`.

Ustalone przy `/review-task`, 24.09.2026:

- **Usługi w kalendarzu: plakietka przy nazwie stanowiska + lista w podpowiedzi**, kolor
  ostrzegawczy przy niedostępnej usłudze, kolejność: obowiązkowe, potem alfabetycznie. Siatka nie
  rośnie, a niedostępność widać bez klikania.
- **„Odepnij usługę" w zakresie** — symetria z „Przypnij"; bez niej odpięcie z 19 stanowisk to
  19 edycji.
- **Zmiana zasięgu na „całe łowisko" usuwa przypięcia** (po potwierdzeniu z ich liczbą). Brak
  ukrytych, nieaktywnych danych; ślad zostaje w dzienniku zmian. Wynika z tego, że repeater
  i akcje oferują tylko usługi „wybrane stanowiska".
- **Przyczyna niedostępności z `PositionAvailability`** — nowa metoda zwraca wpisy zawieszające
  cechy; klasa usług stanowiska nie pyta o blokady sama. Reguła przecięcia wpisu z dobą ma jeden
  dom (023 usuwało jej drugi dom jako defekt).
- **Brak ADR-a.** Żadna decyzja tego zadania nie spełnia trzech warunków naraz: wszystkie są lokalne
  dla usług, odwracalne bez migracji danych albo już zapisane w wymaganiach (F5, O13, O21).

## Powiązane ADR-y
<!-- Numery ADR-ów podjętych dla tego zadania (uzupełnia /review-task).
     Tylko decyzje spełniające trzyskładnikowe kryterium z CLAUDE.md —
     reszta idzie do „Rozstrzygnięcia" powyżej. -->
- 
