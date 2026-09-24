# 023 — Porządki po przeglądzie 018 i 019

## Opis problemu

Przegląd wg frameworka po implementacji zadań 018 i 019 (2026-09-23, zakres: pięć niewypchniętych
commitów) zwrócił **osiem defektów i dziewięć propozycji zmian**. Defekty zostały poprawione od razu,
w tym samym przebiegu — ten plik zbiera **wyłącznie propozycje**, czyli rzeczy, które dziś działają
poprawnie, ale dałoby się je zrobić inaczej.

⚠️ **To nie jest lista błędów.** Żadna z pozycji nie wywraca zachowania, żadna nie jest naruszeniem
zapisanej konwencji i żadna nie blokuje wdrożenia. Dlatego powstało osobne zadanie, a nie poprawka
w locie: część z nich to decyzje projektowe, które powinien podjąć autor, a nie przegląd.

✅ **Zadanie przeszło `/review-task` (2026-09-23)** — każda pozycja ma decyzję autora zapisaną
pod nią jako „**Decyzja:**" i zebraną w sekcji `## Rozstrzygnięcia`. Doszły pozycje 10
(uspójnienie wyboru dób tygodnia w dopłatach i weekendzie) i 11 (testy mutacyjne pakietu).

**Co zostało poprawione od razu (dla kontekstu, nie do zrobienia):** N+1 na `positions()` przy
podpowiedzi blokady, brak eager-loadu łowiska na stanowiskach, niewalidowane właściwości publiczne
komponentu (`nights`, `windowStart`), drugi dom reguły przecięcia blokady z dobą, brak kwot
w podpowiedzi nachodzenia oraz trzy nieaktualne komentarze.

---

## Wymagania

### 1. Memoizacja kalendarza i podwójne `missingSetup()` — ⚠️ CZĘŚCIOWO ZROBIONE

`ManageCalendar::calendar()` tworzyło nową instancję przy każdym wywołaniu (pięć razy na render),
a każda od nowa odpytywała o sezony. **Memoizację naniesiono razem z poprawkami defektów.**

Zostaje drobiazg: `getGrid()` woła `missingSetup()`, mimo że widok policzył je chwilę wcześniej.
Jedno zapytanie na render — do rozważenia, czy warto przekazywać wynik.

**Decyzja:** memoizacja wyniku `missingSetup()` w polu instancji `SaleCalendar`. Bez zmiany
sygnatur `getGrid()` ani widoku.

### 2. `SaleCalendar::anchor()` czyta się jak pętla, a jest „weź pierwszy"

Metoda to `foreach` z **bezwarunkowym `return` w pierwszej iteracji**. Wynik jest poprawny, bo lista
sezonów jest posortowana i przefiltrowana po `ends_on >= dziś`, ale zapis myli czytelnika:

```php
$first = $this->seasons()[0] ?? null;
```

plus jedno `if` niesie tę samą logikę bez sugerowania przebiegu po wszystkich sezonach.

**Decyzja:** przepisać wg powyższego. Zachowanie bez zmian.

### 3. Martwy kod po przebudowie 018

Do usunięcia **albo** świadomego zostawienia z adnotacją, dlaczego zostaje:

| Element | Stan |
|---|---|
| `ParticipantRole::options()` | nieużywane nigdzie, także w testach — zostało po formularzu, w którym rola była `Select`-em |
| `PriceRuleKind::options()` | jw. |
| `NightPriceResolution::hasOverlappingRates()` | tylko testy; siatka liczy to `SaleCalendarGrid::overlappingRates()` |
| `PriceRule::coversNight()` | tylko testy; ścieżka produkcyjna idzie `candidatesForDay()` → `coversDay()` |
| `StayOffer::isAvailable()` | nieużywane |

⚠️ **Nie kasuj odruchowo.** `coversNight()` i `hasOverlappingRates()` są sensownym API domenowym,
tylko jeszcze bez konsumenta; `options()` na enumach to konwencja projektu powtórzona w kilku
miejscach. Decyzja należy do autora.

**Decyzja:** **usunąć wszystkie pięć.** `options()` jest potrzebne tylko enumom zasilającym
`Select`, a tych dwóch nie ma w żadnym formularzu. `coversNight()` to jednolinijkowe
przekierowanie do `coversDay()`. `hasOverlappingRates()` powtarza odpowiedź, której domem jest
`SaleCalendarGrid::overlappingRates()`. Testy przepiąć odpowiednio na `coversDay()` i na liczbę
kandydatów. Poprawić też komentarz w `PriceRuleResolver` (linia ~21), który wymienia
`coversNight()`.

### 4. Lista martwych stawek pokazuje surową kwotę bez kontekstu

`manage-calendar.blade.php` wypisuje `$rule->amount` — czyli `90.00`, z kropką i bez waluty,
w polskim interfejsie — i **nie mówi, którego wpisu cennika dotyczy**. Stawki nie mają pola nazwy
(pięć pól, świadomie), więc operator musi się domyślić po kwocie.

**Do rozważenia:** sformatowana kwota wraz z zakresem dat (`first_day_on–last_day_on`), żeby dało
się trafić do właściwego wiersza cennika. Wiąże się z pozycją 6.

**Decyzja:** wpis listy martwych stawek ma postać **[etykieta — ] kwota z walutą · zakres dat**.
Etykieta pojawia się tylko wtedy, gdy stawka ją ma (pozycja 6). Zmienia się tekst tłumaczenia:
klucz angielski w kodzie i jego odpowiednik w `lang/pl.json`.

**Formater kwoty (doprecyzowane przy drugim `/review-task`):** wspólnego formatera dziś nie ma.
Komórka siatki formatuje inline w Blade (`number_format($cell->totalInCents / 100, 2, ',', ' ')`),
bez waluty. Formatowanie kwoty wydzielamy więc do **jednej klasy w `app/Services/`**
(`AmountFormatter`) z opcjonalnym symbolem waluty łowiska.
- **Lista martwych stawek** pokazuje kwotę **z walutą**.
- **Komórki siatki** wołają ten sam formater **bez waluty** i wyglądają jak dziś.
- Literał formatu w widoku znika.

### 5. Wybór blokady do podpowiedzi — ⚠️ ZROBIONE

Gdy dobę przykrywały dwie blokady, podpowiedź pokazywała zasięg przypadkowej. **Poprawione razem
z defektem 4**: wybór idzie po `starts_on`, potem po `id`.

### 6. Stawka nie ma pola nazwy, choć kolumna `label` istnieje i jest używana

`StayPricing` wstawia `label` stawki do rozbicia wyceny (zawsze `null`), a `PriceRulePeriods` robi
w powiadomieniu o domknięciu fallback na kwotę. Formularz stawki ma **pięć pól** i nazwy wśród nich
nie ma — to zapis z zadania 018, przyjęty świadomie.

**Dwie drogi, do wyboru przez autora:**

- dołożyć stawce **opcjonalną etykietę**, jak przy dopłacie — wtedy powiadomienie o domknięciu,
  rozbicie wyceny i lista martwych stawek zaczynają mówić „Cennik 2026" zamiast „70.00";
- albo odnotować w [`cennik.md`](../../conventions/cennik.md), że **rozbicie stawki jest bezetykietowe
  z założenia**, i zostawić fallback na kwotę.

⚠️ Makieta v2 rysuje stawki z nazwami („Cennik 2026", „Majówka taniej"), a zadanie mówi „pięć pól" —
**te dwa dokumenty są dziś ze sobą sprzeczne** i ta pozycja to rozstrzyga.

**Decyzja:** **opcjonalna etykieta stawki** — szóste pole formularza stawki, wzorem pola `label`
dopłaty (`TextInput`, `maxLength(255)`, nullable, z helper textem). Rację ma makieta v2.
- **Bez migracji:** kolumna `price_rules.label` istnieje (nullable) i jest w `$fillable`.
- Pusta etykieta zachowuje dzisiejsze zachowanie: fallback na kwotę w `PriceRulePeriods`
  i `null` w rozbiciu `StayPricing`.
- Formularz stawki **zapisuje** i **odczytuje** `label`. Normalizacja pustych wartości
  (`ManagePricing::withoutBlankValues()`) obejmuje już `label` dla obu rodzajów reguł, a
  `rateData()` go nie zeruje. Po stronie zapisu wystarczy więc dodać pole do schematu stawki.
- **Nagłówek wiersza stawki w repeaterze** (dziś „70.00 · od 2026-01-01”) pokazuje etykietę
  na początku, gdy jest wypełniona: **[etykieta · ] kwota · daty**. Tak samo robi już nagłówek
  dopłaty i tak samo zapis z poz. 4.
- ⚠️ **Zadanie 018 jest zamrożone — nie edytujemy go.** Sprzeczność znika, bo to zadanie
  (023) nadpisuje zapis „pięć pól", a obowiązujący stan opisuje `cennik.md`.

### 7. `PricingConfigurationAudit::representativeDays()` parsuje daty w strefie aplikacji

Jedyne miejsce w pakiecie 017–019, które nie bierze strefy łowiska. Dziś **nieszkodliwe**, bo
`coversDay()` porównuje łańcuchy `Y-m-d`, ale różni się od reszty kodu i zacznie kłamać przy
pierwszym porównaniu momentów zamiast dat.

**Decyzja:** daty w `representativeDays()` parsowane w strefie czasowej łowiska, tak jak
w reszcie pakietu. Zachowanie bez zmian.

### 8. Cennik wczytywany wielokrotnie na render

`SaleCalendar::pricingDiagnostics()` pobiera reguły, `PricingConfigurationAudit` pobiera je
jeszcze raz, a do tego **każde stanowisko** pobiera je osobno. `SaleCalendar` tworzy
`new StayOffer($position)` na każde stanowisko, a ta budzi własny `StayPricing`, który wczytuje
cennik w `resolver()`. Przy 26 stanowiskach to **28 wczytań tego samego cennika**, a nie trzy.
Wstrzyknięcie gotowej tablicy reguł usuwa wszystkie oprócz jednego. Nie narusza to zakazu
bufora, bo to jedno wczytanie na żądanie, a nie zapamiętany werdykt.

**Decyzja:** reguły wczytuje raz `SaleCalendar` i przekazuje je opcjonalnym parametrem
`?array $rules = null`:
- do `PricingConfigurationAudit`;
- do `StayOffer`, który przekazuje je dalej do tworzonego przez siebie `StayPricing`.

Kalendarz nadal woła wyłącznie warstwę oferty (ADR-015). Pozostali wołający nie podają
parametru i działają bez zmian. Kryterium: liczba zapytań renderu z pomiaru 019 (191) spada,
co ma potwierdzić test albo ponowny pomiar zapisany w podsumowaniu.

### 9. `ShortestStayVerdict::found()` zależy od niezmiennika spoza sygnatury

`found()` i `startsEarlier()` mają parametry nie-nullowalne, a `StayOffer` woła je
z `?StayPriceBreakdown` / `?SaleUnavailabilityReason`. Runtime jest bezpieczny (przy
`available === true` rozbicie zawsze istnieje), ale to założenie nie jest widoczne w typach.
Jawna asercja albo `?->` z fallbackiem byłyby czytelniejsze.

**Decyzja:** strażnik przy wywołaniu w `StayOffer`:
`$verdict->breakdown ?? throw new LogicException('…')`, analogicznie dla `reason` przy
`startsEarlier()`. Niezmiennik jest widoczny w kodzie i sprawdzany w runtime. Nie `assert()`,
bo przy `zend.assertions=-1` na produkcji niczego nie pilnuje. Sygnatury `ShortestStayVerdict`
zostają nie-nullowalne.

### 10. Wybór dób tygodnia — jeden komponent dla dopłat i weekendu

Dwa ekrany wybierają ten sam rodzaj wartości: **zbiór dób tygodnia identyfikowanych dniem
rozpoczęcia** (ISO 1–7). Robią to dziś na dwa sposoby:

| | Cennik → dopłata (`ManagePricing`, pole `weekdays`) | Reguły sprzedaży → weekend (`ManageSaleRules`, pole `weekend_days`) |
|---|---|---|
| Wizualizacja | `ToggleButtons` inline — **dobra** | `CheckboxList` w dwóch kolumnach — **do wymiany** |
| Informacja | same nazwy dni („pt") — **uboga**, sugeruje dni zamiast dób | przedział doby „pt → sob · 15:00 → 15:00" + podsumowanie — **dobra** |
| Nazwy dni | `weekdayOptions()` | `dayName()` — **drugi literał tej samej logiki** |

**Cel:** bierzemy to, co najlepsze z obu. Wizualizacja pochodzi z dopłat: przyciski-chipy
w jednym rzędzie, **bez checkboxów**. Informacja pochodzi z reguł sprzedaży: doba jako
przedział plus podsumowanie. Układ informacji jest jak w
[`makieta-017-reguly-sprzedazy-v2-spoiwo.html`](../../project/mockups/makieta-017-reguly-sprzedazy-v2-spoiwo.html),
**sekcja 2 („Reguły sprzedaży"), blok „Weekend"**:

1. **etykieta pola** nad chipami (np. „Zaznacz doby, które składają się na weekend");
2. **siedem chipów w jednym rzędzie**, każdy **dwuwierszowy**: pogrubione „**pt → sob**",
   a pod nim mniejsze „15:00 → 15:00" (godziny doby łowiska); chip zaznaczony jest wyróżniony;
3. **podsumowanie pod chipami**: „Od piątku 15:00 do niedzieli 15:00 · 2 doby".

**Wymagania:**
- **Jeden dom.** Wiedza o dobach tygodnia (nazwa dnia, przedział „dzień → następny dzień",
  godziny doby, podsumowanie zbioru, skrót do nagłówka) trafia do **jednej klasy
  w `app/Services/`**: `WeekdayNights`, test `tests/Unit/WeekdayNightsTest.php` (albo `Feature`,
  jeśli potrzebuje łowiska z bazy). Budowę pola daje jedna metoda w `SharedFormComponents`, którą wołają
  oba ekrany. Znikają `ManagePricing::weekdayOptions()`, a z `ManageSaleRules`:
  `weekendNightOptions()`, `weekendSummary()` i `dayName()`. `timeLabel()` przenosi się, jeśli
  nie ma innych konsumentów na stronie.
- **Komponent:** `ToggleButtons` z `->multiple()->inline()` i dwuwierszową etykietą opcji.
  Sposób wstrzyknięcia dwóch wierszy (np. `HtmlString` / `allowHtml()`) sprawdź w API Filamenta 5,
  wzorem natywnych komponentów (`panel-wlasciciela.md` §1). Wszelki HTML budowany z wartości
  musi być escapowany: godziny i nazwy dni pochodzą z bazy i z locale.
- **Weekend zachowuje całą dzisiejszą semantykę.** Przełącznik „The weekend is sold whole",
  widoczność pola zależna od przełącznika, wyłączenie bez godzin doby, reguła
  `WeekendDaysAreContiguous`, rzutowanie na `int` w `mutateFormDataBeforeSave` oraz
  ostrzeżenie „shortest stay longer than weekend" — wszystko bez zmian. Zmienia się **wyłącznie
  wizualizacja**.
- **Dopłata dostaje pełną informację.** Chip dwuwierszowy, podsumowanie pod chipami i
  zachowany helper text („Nights are identified by the day they start on. Selecting none means
  every night."). Brak zaznaczenia znaczy „każda doba", a podsumowanie ma to powiedzieć wprost.
- **Podsumowanie jako lista ciągów.** Zbiór rozbija się na **maksymalne ciągi cykliczne**,
  z których każdy ma postać „od … do …", a na końcu stoi łączna liczba dób. Weekend (zawsze
  jeden ciąg, pilnuje tego reguła) wygląda jak dziś. Dopłata „pn + śr" daje dwa odcinki.
  Ciąg `{7, 1}` to **jeden** odcinek nd → wt.
- **Bez godzin doby** (łowisko nie ma `day_start_time`/`day_end_time`): w dopłacie chip
  pokazuje samo „pt → sob", **bez linii godzin**, a podsumowanie mówi o dobach bez godzin.
  Pole dopłaty **nie** jest wtedy wyłączane. Weekend jest wyłączony jak dziś.
- **Nagłówek wiersza dopłaty w repeaterze** (dziś „pn–pt" z `reset()`/`end()`, co przekłamuje
  zbiór nieciągły) bierze **krótką formę z tego samego domu**, np. „pt→nd" albo „pn, śr".
- **Bez zmiany danych.** Obie kolumny trzymają dziś ISO 1–7 jako JSON, więc bez migracji.
- Nowe i zmienione klucze tłumaczeń: angielski tekst jest kluczem w kodzie (UI po angielsku),
  a polski trafia do `lang/pl.json`. Plik `lang/en.json` nie istnieje i nie powstaje.

### 11. Testy mutacyjne pakietu — zaraz po implementacji

**Bezpośrednio po zakończeniu implementacji (pozycje 1–10) i zielonym zakresie T2** uruchom testy mutacyjne
Pesta na **całym pakiecie niewypchniętych commitów oraz na wszystkim, co powstało później**
(zmiany samego 023, także jeszcze niezacommitowane). Nie odkładamy ich do
`/review-implementation`.

**Pakiet — baza i commity (stan na 2026-09-23):**

| | Commit | Opis |
|---|---|---|
| baza (`origin/dev`) | `2537fc6071fcf76f32a4c6a94ddc1310bae3cd86` | Szybkie poprawki — **poza** zakresem |
| 1 | `78a2f98751428d1102e1d568712ed83e10231949` | T017 — reguły sprzedaży |
| 2 | `08c8d38a4fcbd26229a11542e54e807664264d70` | T018 — cennik łowiska |
| 3 | `a768ce3e963c80b761abe1bc7b1f06231103102f` | T018 — poprawki przed przedefiniowaniem |
| 4 | `1e866da6db6f3be0f850d1a149197bcc4576c37c` | T018 — cennik po uproszczeniu modelu |
| 5 | `a04786629504b90dce656346b4f50f6382aedcc1` | T019 — kalendarz podglądowy konfiguracji |
| 6 | `50be27b5007612d0acbb2c3d2d9420e90555187f` | Poprawki po zadaniach 018 i 019 |
| 7+ | wszystko po `50be27b` | commity i zmiany robocze powstałe później, w tym implementacja 023 |

**Zbiór klas** — pliki z `app/Services/`, `app/Rules/` i `app/Models/` zmienione od bazy do
**bieżącego drzewa roboczego**. Zawężenie wybrał autor: klasy liczące, walidujące i modele
z logiką. Enumy i strony/zasoby Filamenta są pominięte, bo mutacja szkieletu deklaratywnego ma
niską wartość, a pokrywają go wolne testy Feature:

```bash
git diff --name-only 2537fc6071fcf76f32a4c6a94ddc1310bae3cd86 -- app/Services app/Rules app/Models
```

To jedyna komenda git, której zadanie wymaga (odczyt, za zgodą autora). Porównanie z drzewem
roboczym obejmuje zarówno commity 1–6 i późniejsze, jak i niezacommitowaną implementację 023.
Pliki usunięte w międzyczasie pomiń. Na 2026-09-23 lista liczy 29 plików: 4 modele, 6 reguł
i 19 usług.

**Uruchomienie** — wg Kroku 3C `/review-implementation` (silnik Pesta, sterownik PCOV, bez `--min`):

```bash
docker compose exec -T app vendor/bin/pest --mutate --covered-only --class="App\\Services\\NazwaKlasy"
```

jedno `--class` na klasę ze zbioru, albo kilka klas po przecinku.
- ⚠️ Przebieg potrwa **rząd godzin**, nie minut, i poleci w tło. Próbka z 2026-09-22 to 21
  mutacji w 260 s dla 50-liniowej reguły, a zbiór liczy 29 klas, większość znacznie większych.
  **W tym czasie nie uruchamiaj niczego, co dotyka bazy testowej.**
- **To jedyny przebieg mutacyjny tego pakietu.** Autor nie puszcza osobnego, bo dwa przebiegi
  naraz nadpisywałyby sobie bazę `lowiska_test`. Zakres to wszystkie klasy z listy commitów
  powyżej, bez dalszego zawężania.
- Nocny przebieg z 2026-09-23 zakończył się kodem 4 bez wyniku, prawdopodobnie przez restart
  hosta. Kod wyjścia różny od 0 **bez** wiersza `Mutations:` dla danej klasy zgłoś
  w podsumowaniu jako przebieg nieudany, a nie jako wynik.
- Bramka 10 minut z `/review-implementation` **nie obowiązuje**, bo autor wyraził zgodę na zakres
  w tym zadaniu.
- Ocalałe mutanty obsłuż wg Kroku 3C.3 `/review-implementation`: zapytaj autora o wariant
  **Popraw teraz / Nowe zadanie / Raport do pliku / Zignoruj**. Wynik (MSI + ocalałe) podaj
  w podsumowaniu implementacji.

---

## Kryteria akceptacji

- [ ] Pozycje **1, 2, 7** wykonane wg decyzji pod każdą z nich.
- [ ] Pozycja **3**: wszystkie pięć elementów usunięte, testy przepięte, a w `app/` i `tests/`
      nie zostało żadne odwołanie do nich (także w komentarzach).
- [ ] Pozycja **6**: formularz stawki ma opcjonalne pole etykiety (zapis i odczyt). Etykieta
      stawki trafia do powiadomienia o domknięciu, do rozbicia wyceny i do nagłówka wiersza
      stawki w repeaterze. Test w `PricingPageTest` pokrywa zapis etykiety stawki.
- [ ] Pozycja **4**: lista martwych stawek pokazuje [etykietę], kwotę z walutą z
      `AmountFormatter` i zakres dat. Komórki siatki korzystają z tego samego formatera
      i wyglądają jak dziś. W widoku kalendarza nie zostało żadne `number_format`.
      Klucz w `lang/pl.json` zaktualizowany.
- [ ] Pozycja **8**: cennik wczytywany raz na render kalendarza. Spadek liczby zapytań
      względem 191 z pomiaru 019 potwierdza test albo pomiar zapisany w podsumowaniu.
- [ ] Pozycja **9**: strażnik `?? throw new LogicException` przy obu wywołaniach w `StayOffer`.
- [ ] Pozycja **10**: oba ekrany budują wybór dób jedną metodą z `SharedFormComponents`,
      a wiedza o dobach tygodnia ma jeden dom w `app/Services/`. W `ManagePricing`
      i `ManageSaleRules` nie zostało żadne `weekdayOptions` / `weekendNightOptions` /
      `weekendSummary` / `dayName`, a w UI obu ekranów nie ma już `CheckboxList` dób.
- [ ] Pozycja **10**: układ zgodny z makietą 017 v2, sekcja 2 (etykieta → rząd siedmiu chipów
      dwuwierszowych → podsumowanie). Weekend zachowuje przełącznik, walidację ciągłości
      i zapis `int`-ów. Dopłata bez godzin doby działa i pokazuje chipy bez linii godzin.
- [ ] Pozycja **10**: test jednostkowy klasy-domu pokrywa podsumowanie dla zbioru pustego,
      ciągłego, nieciągłego, cyklicznego `{7, 1}` oraz wariant bez godzin doby.
- [ ] Żadna zmiana nie rusza zachowania widocznego dla operatora poza pozycjami 4, 6 i 10.
- [ ] Zielony zakres testów zadeklarowany niżej.
- [ ] Pozycja **11**: testy mutacyjne przeszły na zbiorze klas z pakietu `2537fc6..` (drzewo
      robocze). Wynik (MSI, ocalałe mutanty) jest w podsumowaniu, a decyzja autora co do
      ocalałych — wykonana.

## Zakres testów

- **Tier:** T2
- **Uruchamiamy:** `docker compose exec app php artisan test --filter="SaleCalendarTest|SaleCalendarPageTest|PriceRuleResolutionTest|StayPricingTest|StayOfferTest|PricingPageTest|PriceRuleTest|SaleRulesPageTest|WeekendBundleTest|WeekdayNightsTest|AmountFormatter"`
- **Uzasadnienie:** zmiany są wewnętrzne, ale **ruszają kontrakty używane gdzie indziej** —
  `ShortestStayVerdict` czyta warstwa oferty, `PricingConfigurationAudit` woła i kalendarz,
  i formularz cennika. Żaden wyzwalacz T3 nie jest dotknięty: bez migracji, bez `User`, bez polityk,
  bez providerów paneli.
  Pozycja 10 rusza dwie strony ustawień i dokłada klasę współdzieloną — to nadal T2, bez
  wyzwalaczy T3.
  Pozycja 6 poszła wariantem z etykietą, ale **tier zostaje T2**: kolumna `label` już istnieje,
  więc migracji nie ma (poprzednia wersja tej sekcji błędnie zakładała T3).
- **Testy mutacyjne:** osobno, wg pozycji 11. Pełny pakiet (T3) jest **odroczony** na koniec
  sesji (`/review-implementation`, Krok 1).

## Zakres wyłączeń

- **Rejestracja motywu panelu i migracja Tailwind 3 → 4** — osobny, większy temat; patrz
  [ADR-016](../../adr/ADR-016-wlasny-motyw-panelu.md) i „Stan po implementacji" w zadaniu 019.
- **Wiersz „Pakiety" w kalendarzu** — wymaga, żeby warstwa oferty wystawiła pakiety łowiska przed
  przycięciem; to należy do 018 i czeka na decyzję autora.
- **Wszystko, co przegląd zgłosił jako defekt** — poprawione w przebiegu z 2026-09-23.

## Zmiany dokumentacji

- [x] `docs/conventions/cennik.md` — stawka ma **opcjonalną etykietę**. Gdy jest pusta, rozbicie
      wyceny niesie `null`, a powiadomienie o domknięciu robi fallback na kwotę. Formularz stawki
      ma sześć pól. Stan obowiązujący, bez historii.
- [x] `docs/conventions/panel-wlasciciela.md` §6 — nowy niezmiennik: **wybór dób tygodnia to
      jeden komponent** (`SharedFormComponents` + klasa-dom w `app/Services/`), w postaci
      chipów dwuwierszowych z przedziałem doby i podsumowaniem. Nie wolno wracać do
      `CheckboxList` ani do samych nazw dni.
- [x] `MANUAL.md` — sekcja cennika mówi dziś „Stawka ma pięć pól”. Zmienić na sześć,
      z opcjonalną nazwą stawki widoczną w powiadomieniu, nagłówku wiersza i kalendarzu.
      Opis wyboru dób weekendu i dopłaty uzgodnić z nowymi chipami i podsumowaniem.
- [x] `CHANGELOG.md` — wpis przez skill `changelog`: etykieta stawki (poz. 6), czytelna lista
      martwych stawek (poz. 4) oraz ujednolicony wybór dób w dopłatach i weekendzie (poz. 10).
- ⛔ `docs/tasks/018-cennik-regulowy.md` — **nie edytować**, zadanie jest zrealizowane
  i zamrożone. Nadpisanie „pięciu pól" żyje w tym zadaniu i w `cennik.md`.
- — `docs/project/mockups/makieta-018-cennik-regulowy-v2.html` — bez zmian, rysuje nazwy
  stawek zgodnie z decyzją.

## Ograniczenia techniczne

- Laravel 13, Filament 5, PHP 8.4.
- **Bez migracji** — także w pozycji 6, bo kolumna `price_rules.label` istnieje, i w pozycji 10,
  bo `weekdays` i `weekend_days` mają już ten sam format (ISO 1–7).
- Pozycja 10 dotyka stron `FisheryResource/Pages/` — przed edycją przeczytaj
  `docs/conventions/panel-wlasciciela.md` (§1, §6) i `docs/conventions/panel-admina.md`.
- Wszystkie porównania dat w **strefie czasowej łowiska** (to zresztą treść pozycji 7).

## Powiązane ADR-y

Żadna z pozycji nie spełnia trzyskładnikowego kryterium ADR. Pozycja 6 jest najbliżej, ale bez migracji
odwraca się jednym polem formularza; pozycja 10 dokłada klasę współdzieloną, ale odwraca się
bez migracji danych — to **rozstrzygnięcie w treści zadania**, nie ADR.

## Rozstrzygnięcia

Ustalone z autorem przy `/review-task`, 2026-09-23:

- **Poz. 1** — memoizacja `missingSetup()` w instancji `SaleCalendar`. Najmniej ruchu, bez zmiany
  sygnatur, a to wynik na żądanie, nie bufor werdyktu.
- **Poz. 2** — `anchor()` przepisane na „weź pierwszy sezon" + jedno `if`. Zapis ma mówić to,
  co kod robi.
- **Poz. 3** — usunąć wszystkie pięć elementów. Żaden nie ma konsumenta produkcyjnego, dwa
  dublują istniejące API, a przywrócenie to jedna linijka.
- **Poz. 4** — [etykieta — ] kwota z walutą · zakres dat. Operator ma trafić do konkretnego
  wiersza cennika.
- **Poz. 5** — zrobione przy poprawkach defektów, nic do zrobienia.
- **Poz. 6** — opcjonalna etykieta stawki, bez migracji. Rację ma makieta v2. Zadanie 018 zostaje
  nietknięte (zamrożone), a obowiązujący stan zapisuje `cennik.md`.
- **Poz. 7** — daty w `PricingConfigurationAudit` w strefie łowiska. Spójność z resztą pakietu
  i z ograniczeniami technicznymi tego zadania.
- **Poz. 8** — reguły wczytywane raz w `SaleCalendar` i przekazywane **opcjonalnym** parametrem
  do `StayPricing` i `PricingConfigurationAudit`. Pozostali wołający działają bez zmian.
- **Poz. 9** — `?? throw new LogicException` w `StayOffer`. Strażnik działa w runtime, `assert()`
  na produkcji nie działa.
- **Poz. 10 (nowa)** — wybór dób tygodnia uspójniony. Wizualizacja pochodzi z dopłat
  (`ToggleButtons`, bez checkboxów), informacja z reguł sprzedaży (przedział doby + godziny
  + podsumowanie), a układ z makiety 017 v2, sekcja 2. Jeden dom wiedzy zamiast dwóch literałów.
  - Podsumowanie **także w dopłacie**, jako lista ciągów cyklicznych — jedna funkcja obsługuje
    oba ekrany.
  - Bez godzin doby dopłata pokazuje **chip bez linii godzin** i nie jest wyłączana, bo działa
    po dniu rozpoczęcia doby.
  - Nagłówek wiersza dopłaty bierze **krótką formę z tego samego domu** — dzisiejsze „pn–pt"
    przekłamuje zbiór nieciągły.
- **Poz. 11 (nowa)** — testy mutacyjne zaraz po implementacji, na klasach `Services`/`Rules`/
  `Models` zmienionych od `2537fc6` do bieżącego drzewa. Bramka 10 minut uchylona zgodą autora.
- **Tier** — zostaje T2. Wcześniejsza adnotacja o podniesieniu do T3 była błędna (brak migracji).

Uzupełnione przy drugim `/review-task`, 2026-09-23:

- **Poz. 4, formater** — nowa klasa `AmountFormatter` w `app/Services/`. Waluta pojawia się
  w liście martwych stawek, a komórki siatki zostają bez waluty. Wspólnego formatera nie było,
  a drugi literał formatu byłby defektem.
- **Poz. 6, nagłówek** — nagłówek wiersza stawki ma postać „[etykieta · ] kwota · daty”,
  spójnie z dopłatą i z poz. 4.
- **Poz. 8, droga parametru** — reguły idą do `StayPricing` przez opcjonalny parametr
  `StayOffer`, bo to oferta tworzy wycenę, a kalendarz nie woła wyceny wprost (ADR-015).
  Dziś to 28 wczytań na render (26 stanowisk), nie trzy.
- **Poz. 10, nazwy** — dom wiedzy o dobach tygodnia to `WeekdayNights`, a jego test to
  `WeekdayNightsTest`. Komenda T2 musi być wykonywalna.
- **Tłumaczenia** — EN to klucze w kodzie, PL to `lang/pl.json`. Pliku `en.json` nie ma.
- **Poz. 11** — mutacje biegną wyłącznie w ramach 023, na pełnym zbiorze klas z listy commitów.
  Autor nie puszcza równoległego przebiegu. Szacunek czasu poprawiony na godziny.
- **Dokumentacja** — doszedł `MANUAL.md`, bo dziś opisuje „pięć pól” stawki.
