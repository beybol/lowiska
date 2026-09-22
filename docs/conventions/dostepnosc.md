# Konwencje: sprzedaż i dostępność

Obowiązuje przy zmianach w `app/Services/FishingDay*.php`, `app/Services/PositionAvailability.php`,
`app/Services/AvailabilityBlockSelectionResolver.php`, `app/Services/StaySellability*.php`,
modelach `SalePeriod`, `AvailabilityBlock` i `WholeTermPeriod` oraz w **każdym miejscu, które pyta,
czy dobę albo pobyt można sprzedać** — w panelu, w portalu wędkarza, w cenniku i w kalendarzu.

⚠️ Ten plik nie należy do żadnego panelu. Reguły niżej wiążą tak samo zasób Filamenta, przyszły
portal, sprzedaż wpisywaną ręcznie i zadania 017–019. Dlatego mieszkają osobno, a nie w pliku
o panelu właściciela.

Zadania źródłowe: 015, 016, 017. Uzasadnienia w
[ADR-010](../adr/ADR-010-doba-wedkarska-jako-przedzial-czasu.md),
[ADR-012](../adr/ADR-012-jedno-zrodlo-prawdy-o-dostepnosci.md)
i [ADR-013](../adr/ADR-013-warstwa-regul-pobytu-i-spoiwo-dob.md).

---

## 1. Doba wędkarska i okresy sprzedaży

- **Doba jest PRZEDZIAŁEM DWÓCH MOMENTÓW, nie datą kalendarzową.** Trwa od `day_start_time`
  dnia D do `day_end_time` dnia D+1, więc zawsze przechodzi przez północ, a przy zmianie czasu
  trwa 23 albo 25 godzin i mimo to jest jedną dobą. Identyfikuje ją dzień rozpoczęcia.
- **Wyliczenia biegną w strefie czasowej ŁOWISKA** (`fisheries.timezone`). Strefa aplikacji
  (`config/app.php`) nie bierze w nich udziału. Momenty graniczne porównuj jako punkty w czasie,
  nie jako daty lokalne — inaczej wynik zależy od kolejności rzutowania i rozjeżdża się dopiero
  przy zmianie czasu.
- ⚠️ **Dwie reguły dopasowania zakresu dat są CELOWO ASYMETRYCZNE** i nie wolno zastąpić jednej
  drugą:
  - **okres sprzedaży DOPUSZCZA** → wymaga **zawierania**: doba musi mieścić się w oknie
    w całości (`FishingDay::isContainedIn()`);
  - **blokada WYŁĄCZA** → wystarczy **przecięcie**: doba jest objęta, gdy jakakolwiek jej część
    wypada w oknie (`FishingDay::overlaps()`).

  Pomyłka w którąkolwiek stronę kończy się sprzedażą doby, której nie wolno sprzedać.
  Skutek praktyczny reguły zawierania, wart zapamiętania: **ostatnie pozwolenie jednodobowe
  kupuje się na PRZEDOSTATNI dzień okresu.**
- **Brak okresu sprzedaży oznacza brak sprzedaży**, nie sprzedaż bez ograniczeń. Reguła jest
  odwrotna do intuicji „nic nie ustawiłem, więc sprzedaję normalnie" i myli się wyłącznie
  w stronę odmowy.
- ⚠️ **Doby liczy wyłącznie [`FishingDayCalendar`](../../app/Services/FishingDayCalendar.php).**
  Ani zasoby Filamenta, ani zapytania o dostępność, cennik czy blokady nie liczą ich po swojemu.
  Drugi kod liczący doby jest defektem: asymetria reguł przestaje wtedy obowiązywać w jednym
  z dwóch miejsc i nic tego nie sygnalizuje.
- **Pola doby żyją POZA `FisheryResource::fisheryDetailComponents()`**, bo ta metoda jest
  współdzielona z krokiem „Fishery" kreatora. Łowisko powstaje niesprzedające i to jest stan
  zamierzony. Pilnuje tego
  [`tests/Feature/SaleSettingsPageTest.php`](../../tests/Feature/SaleSettingsPageTest.php).

---

## 2. Jedno źródło prawdy o dostępności

- **Na pytanie „czy tę dobę można sprzedać na tym stanowisku — i dlaczego nie" odpowiada
  WYŁĄCZNIE [`PositionAvailability`](../../app/Services/PositionAvailability.php).**
  ⚠️ **Lista wołających zmieniła się w zadaniu 018.** Panel konfiguracyjny woła tę klasę wprost,
  ale kalendarz podglądowy (019), koszyk i portal pytają **warstwę oferty**
  ([`cennik.md`](cennik.md) §5, ADR-015) — bo od chwili, w której dziura w cenniku jest odmową,
  odpowiedź „czy wolno sprzedać" ma dwóch dostawców i ktoś musi ich złożyć w JEDNYM miejscu.
  ⚠️ Zapytanie z `where('status', 'available')` napisane w zasobie Filamenta obok usługi jest
  defektem, nawet gdy dziś zwraca to samo — przestanie, gdy dojdzie piąty warunek.
- **`PositionAvailability` KOMPONUJE `FishingDayCalendar`, nie zastępuje go.** Kalendarz wie
  o **czasie** (doby, reguły granic), usługa dostępności o **stanowisku** (stan własny, blokady).
  Pytasz wyłącznie o doby sezonu, bez stanowiska → kalendarz. Pytasz o sprzedaż → **zawsze**
  usługa dostępności.
- ⚠️ **Stanowisko bez łowiska to nie odmowa, tylko błąd wywołania** — `PositionAvailability`
  rzuca wtedy `InvalidArgumentException` z konstruktora. `positions.fishery_id` jest świadomie
  `nullable` z `set null` ([`panel-wlasciciela.md`](panel-wlasciciela.md) §8), więc taki rekord
  może istnieć; bez łowiska nie ma jednak ani doby, ani okresów, więc nie ma o co pytać.
  **Nie zastępuj tego `assert()`** — asercje są wyłączone w obrazie produkcyjnym
  (`zend.assertions=-1`), czyli dokładnie tam, gdzie ochrona ma działać.
- **Cztery warunki składane w STAŁEJ kolejności, wyrażającej trwałość przyczyny:**
  1. stan własny stanowiska (`withdrawn` → odmowa niezależnie od dat),
  2. konfiguracja doby i okres sprzedaży (przez kalendarz),
  3. blokada `sale_blocked` **przecinająca** dobę,
  4. zawieszenia cech — **nie wpływają na sprzedawalność**, wracają osobno.

  ⚠️ Kolejność jest umową produktową, nie skutkiem kosztu zapytania. **Nie przestawiaj jej dla
  wydajności** — komunikat widziany przez wędkarza zmieniłby się przy okazji optymalizacji.
- **Odmowa niesie PIERWSZY napotkany powód** (`SaleUnavailabilityReason`), nigdy samo „nie".
  Stanowisko wycofane ORAZ poza sezonem raportuje stan stanowiska, bo ten nie zmieni się jutro.
- **Enum powodów jest JEDEN dla całej sprzedaży.** Nowy warunek dokłada wartość do
  `SaleUnavailabilityReason`, nie zakłada własnego słownika komunikatów.
- **Zawieszenie cechy nie jest odmową sprzedaży.** Stanowisko z zawieszonym pomostem nadal się
  sprzedaje — tylko bez pomostu. `suspendedAttributes()` jest osobnym pytaniem; nie zlewaj go
  z wynikiem odmowy.
- **Dostępność wylicza się z rekordów przy każdym pytaniu — nie ma kolumny, która by ją
  buforowała.** Bufor, gdyby był kiedyś potrzebny, jest nową decyzją z własnym uzasadnieniem
  **pomiarowym**, nie rozwinięciem tej.
  ⚠️ **To nie zakazuje pamiętania ODCZYTU w obrębie jednej instancji usługi.** `PositionAvailability`
  wczytuje wpisy o dostępności raz na skutek, a `FishingDayCalendar` — okresy sprzedaży raz na
  instancję; bez tego `sellableDaysBetween()` zadawało jedno zapytanie **na dobę** (zakres
  trzydziestodniowy = ~61 zapytań zamiast 3). Zakaz dotyczy trwałego bufora werdyktu między
  żądaniami, nie powtórzeń w jednym. Skutkiem ubocznym jest to, że **`FishingDayCalendar` i
  `PositionAvailability` nie są klasami `readonly`** — instancja żyje tyle, co jedno pytanie,
  więc nie trzymaj jej dłużej w polu innego obiektu.

---

## 3. Blokady i ograniczenia — jeden wpis ze skutkiem

- **Blokada sprzedaży i zawieszenie cechy to JEDEN byt** (`AvailabilityBlock`) o identycznym
  kształcie: zbiór stanowisk, zakres dat, powód, widoczność powodu. Różni je wyłącznie `effect`.
  Nie rozdzielaj ich na dwa modele — dublowałoby to politykę, zasób i formularz.
- ⚠️ **Zbiór stanowisk jest ZMATERIALIZOWANY** w `availability_block_position`, także przy wyborze
  „całe łowisko". `selection_kind` i `selection_label` mówią wyłącznie, JAK zbiór wybrano, i **nie
  są rozwiązywane przy odczycie** — zmiana cechy na stanowisku nie wciąga go do blokady ani z niej
  nie wypycha. Kryterium podpowiada, lista przesądza.
  - Skutek, o którym panel MUSI mówić: **stanowisko założone później nie wchodzi do istniejącej
    blokady całościowej**. `CreatePosition` ostrzega przy zapisie, wskazując wpisy, które go nie
    obejmują.
  - Przeliczenie kryterium na listę ma jeden dom:
    [`AvailabilityBlockSelectionResolver`](../../app/Services/AvailabilityBlockSelectionResolver.php).
- ⚠️ **Opcje zależne od cech znikają, dopóki słownik nie ma ani jednej cechy typu `flag`** —
  skutek „zawieszenie cechy" i kryterium „stanowiska z cechą". Przy pustym słowniku obie są
  ślepą uliczką: pierwszej nie przepuści `AvailabilityBlockEffectMatchesAttribute`, druga zawsze
  daje pusty zbiór, którego nie przepuści `PositionsBelongToFishery`. Cecha typu liczba albo
  wybór z listy ich **nie odblokowuje** — zawiesić da się tylko flagę.
  - Pusty słownik jest stanem DOMYŚLNYM: `DatabaseSeeder` sieje `State`, `FishingMethod`
    i `Currency`, ale nie cechy.
  - **Wpis, który już zawiesza cechę, zachowuje swoją opcję** także po opróżnieniu słownika —
    inaczej edycja dat albo powodu po cichu gubiłaby skutek wpisu. Bramka pyta więc o `$record`,
    nie o stan pola.
- **Zawiesić można wyłącznie cechę typu `flag`** — zawiesza się to, co stanowisko MA albo czego
  NIE MA. Zawieszenie liczby albo wyboru z listy byłoby **nadpisaniem**, czyli innym pojęciem.
  Pilnuje tego `app/Rules/AvailabilityBlockEffectMatchesAttribute`.
- **Zawieszenie nie zmienia wartości cechy na stanowisku.** Po upływie okresu wszystko wraca samo,
  bez żadnej operacji — to warunek, bez którego ograniczenie byłoby nieodwracalne.
- **Puste `ends_on` znaczy „do odwołania".** Okno wpisu to `starts_on` od północy do `ends_on`
  do końca dnia w strefie łowiska; przecięcie z dobą liczy `FishingDay::overlaps()`.
- ⚠️ **Zbiór jest niepusty i w całości z łowiska wpisu** — pilnuje tego
  `app/Rules/PositionsBelongToFishery`, a nie samo zawężenie opcji w formularzu. Identyfikatory
  z wyboru wielokrotnego przychodzą od klienta (`autoryzacja.md` §4).
- **Klucz obcy do łowiska jest WYMAGANY z kaskadą** — jak `sale_periods` i `position_groups`.
  Wpis nie ma wartości historycznej ani ścieżki dostępu poza łowiskiem.
- **Blokada nie unieważnia sprzedanej rezerwacji sama z siebie.** Model rezerwacji jeszcze nie
  istnieje; gdy powstanie, kolizja ma dawać ostrzeżenie z liczbą objętych rezerwacji, a odwołanie
  ma być świadomą decyzją właściciela oznaczającą pełny zwrot.

---

## 4. Pobyt, spoiwo dób i reguły sprzedaży

Zadanie źródłowe: 017. Uzasadnienie i odrzucone warianty:
[ADR-013](../adr/ADR-013-warstwa-regul-pobytu-i-spoiwo-dob.md).

- **Pobyt** to ciągły zakres dób na JEDNYM stanowisku, opisany dobą rozpoczęcia i liczbą dób.
  Reguły z tego rozdziału dotyczą **ciągu dób** i nie dają się wyrazić jako własność żadnej
  z nich osobno.
- ⚠️ **Na pytanie „czy ten pobyt wolno kupić — i dlaczego nie" odpowiada WYŁĄCZNIE
  [`StaySellability`](../../app/Services/StaySellability.php).** Klasa **komponuje**
  `PositionAvailability`, nie zastępuje jej ani nie powtarza jej warunków. Granica jest ta sama
  co między `FishingDayCalendar` a `PositionAvailability`:

  | Pytasz o | Wołasz |
  |---|---|
  | doby sezonu, bez stanowiska | `FishingDayCalendar` |
  | jedną dobę na stanowisku | `PositionAvailability` |
  | ciąg dób kupowany razem | `StaySellability` |
  | **ile ten pobyt kosztuje** | `StayPricing` ([`cennik.md`](cennik.md)) |
  | **czy pobyt jest w ofercie** | `StayOffer` ([`cennik.md`](cennik.md) §5) |

  ⚠️ **`StaySellability` NIE jest już bezpośrednim wejściem dla cennika, kalendarza ani portalu** —
  zostaje jedynym źródłem prawdy o **sprzedawalności pobytu**, a wołającym jest warstwa oferty
  (ADR-015). Decyzja ADR-013 zostaje w mocy; zmieniła się wyłącznie lista wołających.

- ⚠️ **Brak reguły pobytu znaczy BRAK OGRANICZENIA** — odwrotnie niż przy okresach sprzedaży,
  gdzie brak wpisu jest odmową (§1). Asymetria jest zamierzona: okres sprzedaży mówi, co
  **wolno**, a reguła pobytu, czego **nie wolno**. Intuicja podpowiada tu spójność, której nie ma,
  więc nie „poprawiaj" jednej strony pod drugą.

### Spoiwo dób

- **Weekend i święto to JEDNA mechanika o dwóch regułach powtarzania**, nie dwa mechanizmy:
  doby, które idą wyłącznie razem — **cyklicznie** (`fisheries.weekend_days`) albo
  **jednorazowo** (`whole_term_periods`). Nie rozdzielaj ich: objęcie w całości, przycinanie,
  zlewanie i kształt odmowy są wspólne, a druga kopia tej mechaniki rozjechałaby się bez sygnału.
- *Instancja pakietu* to **maksymalny ciąg kolejnych dób** spiętych spoiwem. **Nic nie jest
  zmaterializowane** — instancje liczą się w chwili pytania.
- **Pobyt przecinający instancję musi ją objąć W CAŁOŚCI.** Kupno samej soboty z weekendu pt–sob
  jest odmową; kupno pakietu wraz z dobami przed nim i po nim jest dozwolone.
- ⚠️ **`weekend_days` to zbiór DÓB, nie dni.** Wartości to dni ISO-8601 (1 = poniedziałek)
  **rozpoczęcia dób**, więc weekend „od piątku 15:00 do niedzieli 15:00" to `{5, 6}`, a **nie**
  `{5, 6, 7}`. Doba `nd 15:00 → pon 15:00` leży **poza** weekendem i sprzedaje się jak zwykły
  dzień — potwierdzone przez łowisko (pytanie 7a). Walidacja zbioru
  (`app/Rules/WeekendDaysAreContiguous`): ciąg **cykliczny** (`{7, 1}` jest poprawne), co najmniej
  dwie doby, nie wszystkie siedem.
- **Instancję PRZYCINA się do dób sprzedawalnych.** Blokada w sobotę wycina dobę sob→nd, więc
  piątek sprzedaje się sam — tej soboty i tak nie ma. Wariant „pakiet z niesprzedawalną dobą jest
  cały niesprzedawalny" jest odrzucony: blokada soboty wyłączałaby cicho piątek.
- **Nachodzące pakiety ZLEWAJĄ się** — spoiwo jest przechodnie. Święto śr–pt nachodzące na weekend
  pt–sob daje jeden pakiet śr–sob (4 doby). **Nie ma walidacji nienachodzenia**: nachodzenie jest
  stanem poprawnym, nie błędem zapisu. Skutek jest niewidoczny w formularzu — pokazuje go kalendarz
  podglądowy (019).

### Zwolnienie z minimum długości

- **Pobyt obejmujący pakiet, w którym jest doba święta, NIE PODLEGA `min_nights`.** Zwolnienie jest
  **zero-jedynkowe** i należy do **pakietu**, nie do samego święta. `max_nights` obowiązuje dalej —
  „spoiwo tylko zaostrza, święto może łagodzić".
- **Pakiet czysto weekendowy nie zwalnia z niczego.**
- ⚠️ **Nie zapisuj tego jako „obniżenie minimum do długości pakietu".** Oba zapisy dają ten sam
  wynik, bo pobyt dochodzący do sprawdzenia długości **już** obejmuje każdy dotknięty pakiet
  w całości, więc nigdy nie jest od niego krótszy. Wersja z arytmetyką sugeruje obliczenie, którego
  nie ma i które nigdy nie zmienia werdyktu.
- **Wynik jest monotoniczny** i o to chodziło: przy `min_nights = 5` i trzydobowym święcie
  przechodzą pobyty 3-, 4-, 5- i 6-dobowe. Zwolnienie „tylko dla pobytu **równego** świętu" dałoby
  ciąg „3 przechodzi, 4 odmowa, 5 przechodzi".
- ⚠️ **Weekend zlany ze świętem jest zwolniony razem z pakietem**, a **pakiet przycięty do jednej
  doby zwolnienie zachowuje.** Oba skutki są zamierzone. Odwrócenie pierwszego znaczyłoby, że samo
  dołożenie reguły weekendu czyni święto niekupowalnym.

### Kolejność sprawdzania

⚠️ Stała, wyrażająca **trwałość przyczyny** — to samo kryterium, którym ADR-012 uporządkował
warunki wewnątrz dostępności. **Nie przestawiaj jej dla wydajności**, bo zmienia komunikat widziany
przez wędkarza:

1. **dostępność każdej doby** pobytu (przez `PositionAvailability`),
2. **spoiwo** — objęcie instancji w całości,
3. **długość** — `min_nights` / `max_nights`,
4. **horyzont**, a w jego obrębie **minimum przedsprzedaży**.

Skutek praktyczny wart zapamiętania: przy zlanym pakiecie krótszy pobyt odpada jako **przerwany
pakiet**, a nie „za krótki" — tylko pierwszy komunikat mówi, co zrobić.

### Horyzont i przedsprzedaż

- **Horyzont liczy się od dzisiejszego dnia w strefie łowiska do dnia rozpoczęcia doby** i obejmuje
  **wszystkie** doby pobytu, nie tylko pierwszą. **Granica jest domknięta**
  (`dzień rozpoczęcia ≤ dzisiaj + sale_horizon_days`). Porównanie idzie na **datach** w strefie
  łowiska, nie na momentach — inaczej zmiana czasu przesuwałaby granicę o godzinę.
- **Przedsprzedaż jest WŁAŚCIWOŚCIĄ OKRESU SPRZEDAŻY i wyjątkiem od horyzontu — nie od okresu ani
  od dostępności.** Zakres objętych dób to z definicji zakres okresu, więc nie ma osobnej tabeli
  ani kolumn `covers_from`/`covers_to` i nie da się skonfigurować okna obejmującego doby poza
  sezonem. Okno **nie otwiera** sprzedaży w blokadzie, na stanowisku wycofanym ani na dobach
  **innego** okresu.
- **Przedsprzedaż jest WŁĄCZONA, gdy obie daty okna są wypełnione.** Nie ma kolumny-przełącznika
  i mieć jej nie ma — dwie prawdy o tym samym rozjechałyby się przy pierwszym zapisie z pominięciem
  formularza.
- ⚠️ **Minimum przedsprzedaży obowiązuje KAŻDY zakup dób okresu dokonany w oknie**, także dób
  leżących w horyzoncie: przedsprzedaż jest ofertą hurtową, nie furtką na pojedyncze doby.
  Flaga `presale_whole_terms_bypass_min_nights` (**domyślnie włączona**) zwalnia z tego minimum
  pobyt obejmujący pakiet ze świętem; weekend sam z siebie nigdy nie zwalnia.
- **Okno obejmuje CAŁE dni brzegowe** — od `presale_opens_on` od północy do `presale_closes_on`
  do końca dnia w strefie łowiska, tak samo jak okno blokady (§3). Porównywanym momentem jest
  **chwila zakupu**.

### Granice święta znaczą co innego niż granice okresu sprzedaży

⚠️ **To jest pułapka, którą najłatwiej skopiować w złą stronę**, i jedyny powód, dla którego
kolumny święta nazywają się inaczej:

| Zapis | 30.04 – 02.05 | Dlaczego |
|---|---|---|
| `sale_periods.starts_on`/`ends_on` | **2 doby** (30.04, 01.05) | `ends_on` to ostatni dzień OKNA, a doba musi zawrzeć się w nim w całości |
| `whole_term_periods.first_day_on`/`last_day_on` | **3 doby** (30.04, 01.05, 02.05) | `last_day_on` to dzień rozpoczęcia ostatniej OBJĘTEJ doby |

**Nie kopiuj logiki granic z `SalePeriod` do `WholeTermPeriod`.** Formularz pokazuje wyliczony pobyt
(„czw 30.04 15:00 → nd 3.05 15:00 · 3 doby"), żeby operator nie mylił ostatniej doby z dniem wyjazdu.

### Zapis reguł — co jest błędem, a co ostrzeżeniem

Walidacja ma jeden dom w `app/Rules/`, nie w formularzu strony — formularz jest jedną ze ścieżek
zapisu. Reguła mieszczenia się święta w sezonie **woła `FishingDayCalendar`** zamiast porównywać
daty po swojemu (§1).

- **Błędy zapisu:** `min_nights > max_nights`; okno przedsprzedaży zamykające się przed otwarciem;
  święto krótsze niż **dwie doby**; **święto, którego choć jedna doba nie mieści się w okresie
  sprzedaży** — także wtedy, gdy doba przecina granicę dwóch sąsiadujących okresów.
- ⚠️ **Na świeżym łowisku komunikat musi mówić prawdę o przyczynie.** Kalendarz odmawia każdej doby
  i przy braku godzin doby, i przy braku okresów — bez rozróżnienia operator zobaczyłby „święto poza
  sezonem" tam, gdzie problemem jest brak konfiguracji. Dlatego **bez godzin doby sekcja świąt
  i przełącznik weekendu są wyłączone**, a brak okresów sprzedaży daje **własny** komunikat.
- **Ostrzeżenia (zapis przechodzi):** okno przedsprzedaży otwierające się po starcie okresu;
  przedsprzedaż na łowisku **bez horyzontu** (okno wtedy niczego nie otwiera, a jedynie ogranicza
  sprzedaż przez `presale_min_nights`); zmiana okresu zostawiająca istniejące święto poza sezonem;
  `min_nights` większe niż długość weekendu; `max_nights` mniejsze niż długość pakietu.
  Powód, dla którego to ostrzeżenia, a nie błędy: operator porządkuje sezon w dowolnej kolejności,
  a w czasie działania ratuje go **przycinanie**.

### Odmowa pobytu

- **Sześć powodów dokłada się do `SaleUnavailabilityReason`** — enum jest JEDEN dla całej sprzedaży
  (§2). Pobyt nie dostaje własnego słownika komunikatów.
- **Odmowa wskazuje, CZEGO dotyczy** — to pole
  [`StaySellabilityVerdict`](../../app/Services/StaySellabilityVerdict.php);
  `FishingDayAvailability` zostaje nietknięte:
  - powód z poziomu doby, przepuszczony z `PositionAvailability`, niesie **dobę**, która zawiodła
    („stanowisko wycofane" bez daty nic nie mówi przy pobycie ośmiodobowym);
  - powód ze spoiwa niesie **pełny zakres pakietu** do objęcia, a nie sam zakres święta czy weekendu
    — bez tego wędkarz nie wie, ile dobrać;
  - powody o całym pobycie (za krótki, za długi, minimum przedsprzedaży) nie niosą ani jednego,
    ani drugiego.
- ⚠️ **Zlany pakiet odmawia jako „przerwane święto"**, gdy jest w nim choć jedna doba święta — nie
  jako „przerwany weekend". Święto nadaje pakietowi zwolnienie z minimum, więc jest jego cechą
  dominującą, a jego nazwa własna jest dla wędkarza rozpoznawalna. Siódmego powodu dla pakietów
  zlanych świadomie nie ma.
- **Zmiana reguł obowiązuje od daty zapisu i nie rusza tego, co sprzedane.** Ponieważ nic nie jest
  zmaterializowane, nie ma „otwartych tygodni" do migrowania.
