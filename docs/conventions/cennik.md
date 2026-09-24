# Konwencje: cennik i wycena pobytu

Obowiązuje przy zmianach w `app/Services/StayPricing.php`, `app/Services/PriceRuleResolver.php`,
`app/Services/PriceRulePeriods.php`, `app/Services/StayOffer.php`,
`app/Services/PricingConfigurationAudit.php`, modelu `PriceRule`, regułach cenowych
w `app/Rules/`, jednostce rozliczenia usług dodatkowych (`ServiceBillingUnit`) oraz w **każdym
miejscu, które pyta, ile kosztuje doba albo pobyt**.

⚠️ Ten plik odpowiada na pytanie **„ile to kosztuje"**. Na pytanie **„czy wolno sprzedać"**
odpowiada [`dostepnosc.md`](dostepnosc.md) — inna oś, osobny plik. Obie warstwy dzielą enum
powodów odmowy i pojęcie doby, ale nie znają siebie nawzajem; składa je warstwa oferty (§5).

Zadania źródłowe: 018, 020. Uzasadnienia w
[ADR-014](../adr/ADR-014-cennik-jako-lista-regul-z-warunkami.md)
i [ADR-015](../adr/ADR-015-warstwa-oferty-pobytu.md).

---

## 1. Cennik jest listą reguł, a osie warunku rozkładają się ASYMETRYCZNIE

- **Cennik to LISTA REGUŁ, nie tabela stawek po wymiarach.** Dodanie wymiaru cennika jest dodaniem
  warunku, czasowa obniżka — zawieszeniem reguły, zmiana ceny wyjściowej — zmianą jednej liczby.
  Tabela kluczowana wymiarami wymagałaby migracji za każdym razem.
- **Dwa rodzaje reguł na jednej tabeli, odróżnia je flaga `kind`:** `rate` **zastępuje** cenę doby,
  `surcharge` **dodaje się**. Wszystkie pasujące dopłaty sumują się.
- ⚠️ **Stawka zna WYŁĄCZNIE daty.** Nie ma dni tygodnia, obsady ani roli — cały ciężar warunkowy
  siedzi na dopłacie (daty, dni tygodnia, obsada, `applies_to`). Kto chce różnicować cenę dniami
  tygodnia, **robi to dopłatą**: wtedy widać, że to dopłata, a nie druga cena bazowa.
- ⚠️ **Nie dokładaj stawce warunków.** Ich zdjęcie było sednem przedefiniowania z 22.09.2026:
  warunek na stawce potrafi po cichu podmienić cenę bazową, warunek na dopłacie może tylko dodać
  albo nie dodać znaną kwotę. Powrót warunków na stawkę przywraca pytanie „która stawka wygrywa",
  a z nim priorytety — patrz [ADR-014](../adr/ADR-014-cennik-jako-lista-regul-z-warunkami.md),
  sekcja „Aktualizacja".
- **Stawka ma opcjonalną NAZWĘ (`label`), która nie jest warunkiem** — formularz stawki ma sześć
  pól: dwie kwoty, nazwę, dwie daty i zawieszenie. Nazwa trafia do nagłówka wiersza, powiadomienia
  o domknięciu, rozbicia wyceny i listy martwych stawek. **Pusta nazwa zapisuje się jako `null`**:
  rozbicie niesie wtedy `null`, a powiadomienie o domknięciu i nagłówek pokazują kwotę.
- **Pusty warunek dopłaty znaczy „bez warunku na tej osi", NIE „warunek fałszywy".**
- ⚠️ **Pusty `Select` z formularza przysyła PUSTY ŁAŃCUCH, nie `null`** — i to jest stan normalny.
  Normalizacja mieszka w `ManagePricing::withoutBlankValues()`; bez niej rzut enuma na pusty łańcuch
  wywraca **cały zapis** formularza.

## 2. Jeden wymiar czasu, nie dwa

- **`first_day_on`/`last_day_on` mówią, KTÓRYCH DÓB reguła dotyczy** — i to jedyny wymiar czasu
  w cenniku. Obie granice są **domknięte**.
- ⚠️ **Nazwy są celowe i identyczne jak w `whole_term_periods`**: oba pola wskazują **dni
  rozpoczęcia dób**. `starts_on`/`ends_on` znaczą w tym projekcie co innego
  ([`dostepnosc.md`](dostepnosc.md) §4) i użycie ich tutaj byłoby zaproszeniem do skopiowania złej
  logiki granic.
- ⚠️ **Nie wprowadzaj drugiego wymiaru czasu** („kiedy ten zapis staje się aktywny"). Dawne
  `effective_*` zostało wycofane: różnica między nim a zakresem dób jest obserwowalna dopiero przy
  **utrwalonej transakcji** pamiętającej cenę z chwili zakupu, czyli razem ze snapshotem G1.
  Do tego czasu nowy cennik od 1 lipca to po prostu stawka z `first_day_on = 1.07`.
- **Brak `last_day_on` na stawce NIE jest tylko „bez granicy"** — to deklaracja „to jest aktualny
  cennik", i na niej stoi całe domykanie okresów (§3).

## 3. Rozstrzyganie: wygrywa tańsza, bez priorytetów

Dla jednej doby:

1. pomiń reguły miękko usunięte (globalny zakres `SoftDeletes`) i **zawieszone**;
2. pomiń te, których warunek nie jest spełniony dla TEJ doby — stawka: daty; dopłata: daty, dni
   tygodnia, obsada;
3. spośród stawek wygrywa **najniższa kwota za osobę łowiącą**;
4. wszystkie pasujące dopłaty **sumują się**.

- ⚠️ **Nachodzenie stawek NIE jest błędem zapisu i nie ma priorytetów.** Uwidacznia je **kalendarz
  podglądowy (019)**, a nie walidacja — to świadome przeniesienie odpowiedzialności z zapisu na
  widok skutku.
- ⚠️ **Pomyłka z koszem jest niewidoczna.** Przy wyborze „najniższa kwota" cena z reguły miękko
  usuniętej byłaby **korzystniejsza**, więc wygrałaby i nie rzuciłaby się w oczy.
- **Determinizm przy równych kwotach:** niższa kwota za towarzyszącą, potem mniejsze `id`. Kwota
  jest wtedy i tak identyczna — chodzi o to, żeby rozbicie wskazywało zawsze tę samą regułę.
  ⚠️ **Stawka bez kwoty za towarzyszącą przegrywa remis zawsze**, żeby wybór nie padł na regułę,
  która odmówi wyceny.
- ⚠️ **Warunek dopłaty sprawdza się osobno dla KAŻDEJ doby**, nie „całe albo wcale".
- **Wynik rozstrzygnięcia niesie KOMPLET kandydatów, nie samego zwycięzcę** — to diagnostyka dla
  kalendarza (019). Nie wchodzi do `StayPriceBreakdown`: rozbicie opisuje, za co wędkarz płaci,
  a komplet kandydatów opisuje stan konfiguracji.

### Domykanie okresów stawki

- **Utworzenie stawki z `first_day_on = D`, która sama jest bezterminowa, domyka poprzednie
  bezterminowe na `D − 1`.** Bez tego podwyżka cicho przestałaby działać: stara 70 zł i nowa 80 zł
  nachodzą, więc wygrałaby tańsza.
- ⚠️ **Stawka z datą KOŃCA nie domyka niczego.** To okno nakładkowe, nie nowy cennik. Wersja bez
  tego warunku otwierała dziurę w cenniku po końcu okna — i po to ten warunek istnieje.
- ⚠️ **Konsekwencja: stawką z datą końca da się cenę tylko OBNIŻYĆ.** Podwyżkę robi się nową
  stawką bezterminową albo dopłatą. Panel tego nie sygnalizuje — pokazuje to kalendarz (019).
- **Tylko przy UTWORZENIU, nigdy przy edycji** — domknięcie jest nieodwracalne.
- ⚠️ **Migawkę identyfikatorów sprzed zapisu rób w `beforeValidate`, nie w `beforeSave`.** Filament
  zapisuje repeatery relacyjne wewnątrz `getState()`, a `beforeSave` odpala się już po wstawieniu
  wierszy — migawka zrobiona tam zawiera je wszystkie i domykanie nigdy się nie uruchamia, cicho.
- **Kilka nowych stawek w jednym zapisie przetwarza się rosnąco po `first_day_on`**, w jednej
  transakcji, z powiadomieniem dla operatora. Cicha zmiana cudzego wpisu jest gorsza niż brak
  automatu.

## 4. Osoba towarzysząca i obniżka przedsprzedażowa

- **Cena osoby towarzyszącej to KOLUMNA `amount_companion` na stawce**, nie osobna reguła. Nie
  dorabiaj dla niej gałęzi ani konkurencyjnego wpisu.
  ⚠️ Pole kwoty dopuszcza **0,00** — minimum jest parametrem `SharedFormComponents::getPriceInput()`,
  bo należy do kontekstu, nie do komponentu. Tak samo cena usługi dodatkowej (§7).
- ⚠️ **`amount_companion = null` znaczy BRAK CENY, nie cenę zerową.** Zapytanie z osobą
  towarzyszącą dostaje wtedy odmowę `NoCompanionPrice` — **osobną** od `NoPriceDefined`, bo obie
  każą operatorowi zrobić co innego: tam dopisać stawkę, tu poprawić jedno pole w istniejącej.
  Darmowa towarzysząca to `0,00` wpisane świadomie; formularz czyni to pole wymaganym.
- **Komu nalicza się dopłatę, mówi `applies_to`** (`SurchargeAudience`: `angler` / `everyone` /
  `companion`). Warunek obsady decyduje, **czy** dopłata wchodzi; `applies_to` — **przez ilu** się
  ją mnoży. Oba są niezależne.
- ⚠️ **Domyślne jest `angler`, nie `everyone`** — i to jest decyzja o kosztach pomyłki. Osoba
  towarzysząca bywa darmowa, więc domyślne „dla każdego" obciążałoby kogoś, kto nie płaci nic.
  Pusta kolumna zachowuje się tak samo jak `angler`.
- ⚠️ **Obsadę liczą SAMI ŁOWIĄCY** — osoba towarzysząca nie podnosi `anglers_count`, inaczej
  dopłata za wyłączność znikałaby przez to, że wędkarz przyjechał z kimś.
- ⚠️ **Wycena wymaga co najmniej jednego łowiącego** — pilnuje tego obiekt zapytania wyjątkiem,
  a nie warstwa odmów: pobyt bez wędkarza nie jest pojęciem. Bez tej bramki „0 łowiących,
  1 towarzysząca" dałoby ofertę za 0,00 zł.
- **`ParticipantRole` przestało być osią warunku** — zostało wyłącznie etykietą pozycji w rozbiciu.

### Obniżka przedsprzedażowa

- **Liczy się PER DOBA i jest widoczna przy każdej dobie.** Podstawą jest **suma pozycji tej doby**
  (wszystkie role razem): wygrana stawka **wraz ze wszystkimi pasującymi dopłatami**. Usługi
  dodatkowe (020) zostają poza podstawą.
- **Zaokrąglenie następuje RAZ na dobę**, do pełnego grosza, **połówki w górę**.
- ⚠️ **Nie licz obniżki od samej stawki** — jej wysokość zależałaby wtedy od tego, czy operator
  wpisał kwotę jako stawkę, czy rozłożył ją na stawkę i dopłatę; ta sama cena końcowa dawałaby
  dwie różne obniżki.
- ⚠️ **Znane i świadomie przyjęte:** suma obniżek dób może różnić się o grosze od procentu
  policzonego od całości pobytu. **Nie „naprawiaj" tego przeliczaniem na całość.**
- Obniżka obowiązuje wyłącznie przy zakupie **w otwartym oknie przedsprzedaży** tego okresu, do
  którego należy doba. Pole mieszka w bloku przedsprzedaży na ekranie „Sprzedaż i sezony", nie
  w cenniku — wszystkie pięć pól opisuje tę samą ofertę tego samego sezonu.

---

## 5. Granice warstw i warstwa oferty

⚠️ **Wycena nie pyta o sprzedawalność, sprzedawalność nie pyta o cenę.**
[`StayPricing`](../../app/Services/StayPricing.php) nie woła `StaySellability` i nie powtarza
żadnego z jej warunków; zmiana reguł pobytu z 017 nie zmienia wyniku wyceny.

```
FishingDayCalendar        czas: doby, sezony                      (ADR-010)
        ↑
PositionAvailability      doba na stanowisku                      (ADR-012)
        ↑
StaySellability           ciąg dób: spoiwo, długość, horyzont     (ADR-013)
        ↑                          StayPricing   ile kosztuje     (ADR-014)
        └──────────────┬──────────────────┘
                 StayOffer                 czy w ofercie          (ADR-015)
                        ↑
        kalendarz (019) · przyszły koszyk · portal
```

- **`StayOffer` jest JEDYNYM wejściem dla kalendarza (019), koszyka i portalu.** Panel
  konfiguracyjny nadal woła to, co odpowiada na jego pytanie — sam cennik albo samą sprzedawalność.
- **Kolejność: najpierw sprzedawalność, potem cena.** Pobyt niesprzedawalny **nie jest wyceniany**,
  więc odmowa niesie przyczynę trwalszą („stanowisko wycofane" przed „brak ceny").
- **Cennik może przyjść GOTOWY od wołającego** — `StayOffer`, `StayPricing`
  i `PricingConfigurationAudit` przyjmują opcjonalną tablicę reguł. Kalendarz (019) wczytuje cennik
  raz i podaje go wszystkim stanowiskom; pozostali wołający nie podają nic i wycena wczytuje go
  sama. To jedno wczytanie na żądanie, nie bufor werdyktu.
- **Warstwa oferty konstruuje się PER STANOWISKO**, jak `PositionAvailability` i `StaySellability`.
  Gdyby budowała zależności przy każdym wywołaniu, memoizacja z [`dostepnosc.md`](dostepnosc.md) §2
  przestałaby działać — a pomiar kosztu w 019 wyszedłby zły **z powodu kształtu konstruktora, nie
  realnej ceny algorytmu**.
- ⚠️ **GRANICA NA PRZYSZŁOŚĆ: warstwa oferty nie liczy niczego własnego.** Pierwsza reguła
  sprzedażowa zapisana w niej, a nie w `StaySellability` albo w cenniku, czyni z niej czwarte
  źródło prawdy — i wtedy ADR-015 trzeba **odwrócić**, a nie rozszerzyć.

### Najkrótszy kupowalny pobyt

`StayOffer::shortestOffer()` odpowiada na drugie pytanie kalendarza: **„ceny od"**. Bez niego 019
miałby dwa wyjścia, oba złe — próbować kolejnych długości (dziesiątki tysięcy wywołań przy siatce
stanowiska × doby) albo odtworzyć u siebie reguły z 017.

⚠️ **Metoda SZUKA, ale nie ROZSTRZYGA.** Nie czyta `min_nights`, `max_nights` ani
`presale_min_nights` i nie wie nic o spoiwie — pyta o kolejne długości i **czyta z odmowy**, co
robić dalej. Dzięki temu zwolnienie świąteczne z minimum, minimum przedsprzedaży i przycinanie
pakietu działają **same z siebie**. Odmowa ze spoiwa niesie zakres pakietu, więc:

- pakiet zaczynający się **przed** pytaną dobą → pobytu nie da się tu zacząć; wynik niesie
  **pierwszą dobę pakietu**;
- pakiet zaczynający się **w** pytanej dobie → skok od razu na długość pokrywającą cały pakiet.

---

## 6. Dziura w cenniku jest odmową

- **Doba w otwartym sezonie, do której nie pasuje żadna reguła `rate`, jest NIESPRZEDAWALNA** —
  nie kosztuje zera. Powód dokłada się do `SaleUnavailabilityReason`, bo enum jest **jeden dla
  całej sprzedaży** ([`dostepnosc.md`](dostepnosc.md) §2).
- ⚠️ **Powód „brak ceny" nazywa WARSTWA OFERTY, nie wycena.** Wycena zwraca typowaną informację,
  że nie umie wycenić doby (`PricingFailure`), i dzięki temu zostaje wolna od słownika odmów
  sprzedaży.
- **Odmowa wskazuje DOBĘ**, dla której zabrakło ceny — sam powód jest bezużyteczny przy pobycie
  wielodobowym, bo operator nie wie, którą regułę poprawić.
- ⚠️ **Dwa powody, nie jeden:** `NoPriceDefined` (żadna stawka nie pasuje) i `NoCompanionPrice`
  (stawka pasuje, ale nie ma kwoty za towarzyszącą). Zlanie ich posłałoby operatora szukać
  nieistniejącej dziury w cenniku.

### Ostrzeżenie przy zapisie to POMOC, nie gwarancja

Zakres sprawdzenia: **doby** okresów sprzedaży **od dziś** do końca ostatniego okresu, wobec reguł
obowiązujących **dziś**. I tyle.

⚠️ **Nie dokładaj tu pętli po obsadach ani po rolach.** Dopasowanie stawki zależy wyłącznie od daty,
a dopłaty dziur nie tworzą, bo tylko dodają — taka pętla nie może znaleźć nic, czego nie znajdzie
iteracja po dobach, a kosztuje iloczyn.

⚠️ **Dziura powstaje także POZA ekranem cennika** — wydłużeniem okresu sprzedaży albo skróceniem
stawki — a sprawdzenie widzi wyłącznie dzisiejszy stan reguł. **Gwarancją jest odmowa przy
sprzedaży**, nie ostrzeżenie przy zapisie.

### Co jest błędem, a co ostrzeżeniem

Twarde reguły zapisu mieszkają w `app/Rules/`; ostrzeżenia — w
[`PricingConfigurationAudit`](../../app/Services/PricingConfigurationAudit.php), bo laravelowa
reguła walidacji potrafi tylko odrzucić zapis.

- **Błędy:** `last_day_on` przed `first_day_on`; brak kwoty za osobę towarzyszącą na stawce;
  obniżka spoza zakresu 0–100.
- **Ostrzeżenia:** dziura w cenniku; domknięcie okresu poprzedniej stawki (powiadomienie, nie
  ostrzeżenie o błędzie).
- ⚠️ **Czego tu NIE MA i nie ma wracać:** ostrzeżenia o stawce, która przegrywa z tańszą.
  Nachodzenie uwidacznia kalendarz (019) — formularz mógłby najwyżej zgadywać intencję, a jedna
  wiedza ma mieć jeden dom.
- ⚠️ **Remis nierozstrzygalny przestał istnieć** razem z priorytetami. Jego powrót do `app/Rules/`
  znaczyłby, że wróciła konkurencja między stawkami.
- **Martwe stawki** (niewygrywające w żadnej dobie swojego okresu) wskazuje
  `PricingConfigurationAudit::deadRates()` — dla kalendarza, nie dla formularza. Kalendarz opisuje
  każdą nazwą (gdy jest), kwotą z walutą łowiska i zakresem dat, żeby operator trafił do właściwego
  wiersza cennika.

---

## 7. Usługi dodatkowe: jednostka rozliczenia i cena

Zadanie źródłowe: 020. Dostępność usługi na stanowisku opisuje [`dostepnosc.md`](dostepnosc.md) §5.

- **Usługa ma WYMAGANĄ jednostkę rozliczenia z dwóch wartości** (`ServiceBillingUnit`), a obie
  mnożą się przez **liczbę egzemplarzy** wybraną przez wędkarza (policzalne — „liczba", nie „ilość"):

  | Jednostka | Rachunek | Przykłady |
  |---|---|---|
  | **za dobę** (`per_night`) | cena × liczba dób pobytu × liczba | łódka, hamak, postawienie przyczepy, wywózka pontonem |
  | **za pobyt** (`per_stay`) | cena × liczba | pellet, lód, drewno |

- ⚠️ **„Za sztukę" i „za wejście" nie istnieją i nie mają wracać.** „Za sztukę" to jedna z dwóch
  jednostek razy liczba. Opłaty za każde użycie (prysznic płacony za wejście) nie da się sprzedać
  z góry, bo wędkarz nie zna liczby wejść — zostaje **na miejscu** i opisuje ją treść oferty łowiska.
- ⚠️ **Egzemplarz usługi „za dobę" zajmuje WSZYSTKIE doby pobytu** — nie ma wyboru części dób.
- **Rachunek z tabeli jest regułą jednostki**; kod liczący kwotę usługi dla pobytu powstaje razem
  z modułem zakładania rezerwacji, jego pierwszym odbiorcą. Warstwa oferty i „ceny od" w kalendarzu
  usług **nie doliczają**, a obniżka przedsprzedażowa ich nie obejmuje (§4).
- **Cena usługi może wynosić 0,00** — usługa darmowa („postawienie przyczepy") nadal niesie
  deklarację wędkarza i limit egzemplarzy. Pokazuje się jako **„bezpłatna"**, nie „0,00 zł / doba".
- **Cenę z jednostką jako tekst składa `AdditionalService::priceLabel()`** („20,00 zł / doba") —
  jeden dom dla tabeli usług i kalendarza; kwotę formatuje `AmountFormatter`.
