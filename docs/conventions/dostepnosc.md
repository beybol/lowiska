# Konwencje: sprzedaż i dostępność

Obowiązuje przy zmianach w `app/Services/FishingDay*.php`, `app/Services/PositionAvailability.php`,
`app/Services/AvailabilityBlockSelectionResolver.php`, modelach `SalePeriod` i `AvailabilityBlock`
oraz w **każdym miejscu, które pyta, czy dobę można sprzedać** — w panelu, w portalu wędkarza,
w cenniku i w kalendarzu.

⚠️ Ten plik nie należy do żadnego panelu. Reguły niżej wiążą tak samo zasób Filamenta, przyszły
portal, sprzedaż wpisywaną ręcznie i zadania 017–019. Dlatego mieszkają osobno, a nie w pliku
o panelu właściciela.

Zadania źródłowe: 015, 016. Uzasadnienia w [ADR-010](../adr/ADR-010-doba-wedkarska-jako-przedzial-czasu.md)
i [ADR-012](../adr/ADR-012-jedno-zrodlo-prawdy-o-dostepnosci.md).

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
  WYŁĄCZNIE [`PositionAvailability`](../../app/Services/PositionAvailability.php).** Panel, portal,
  cennik (018) i kalendarz podglądowy (019) wołają tę klasę.
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
