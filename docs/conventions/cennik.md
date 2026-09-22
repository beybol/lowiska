# Konwencje: cennik i wycena pobytu

Obowiązuje przy zmianach w `app/Services/StayPricing.php`, `app/Services/PriceRuleResolver.php`,
`app/Services/PriceRuleOverlap.php`, `app/Services/StayOffer.php`,
`app/Services/PricingConfigurationAudit.php`, modelu `PriceRule`, regułach cenowych
w `app/Rules/` oraz w **każdym miejscu, które pyta, ile kosztuje doba albo pobyt**.

⚠️ Ten plik odpowiada na pytanie **„ile to kosztuje"**. Na pytanie **„czy wolno sprzedać"**
odpowiada [`dostepnosc.md`](dostepnosc.md) — inna oś, osobny plik. Obie warstwy dzielą enum
powodów odmowy i pojęcie doby, ale nie znają siebie nawzajem; składa je warstwa oferty (§5).

Zadanie źródłowe: 018. Uzasadnienia w
[ADR-014](../adr/ADR-014-cennik-jako-lista-regul-z-warunkami.md)
i [ADR-015](../adr/ADR-015-warstwa-oferty-pobytu.md).

---

## 1. Cennik jest listą reguł z warunkami

- **Cennik to LISTA REGUŁ, nie tabela stawek po wymiarach.** Dodanie wymiaru cennika jest
  **dodaniem warunku**, czasowa obniżka — **zawieszeniem** reguły, a zmiana ceny wyjściowej —
  zmianą jednej liczby. Tabela kluczowana wymiarami wymagałaby migracji za każdym razem.
- **Dwa rodzaje reguł różni WYŁĄCZNIE flaga `kind`:** `rate` **zastępuje** stawkę, `surcharge`
  **dodaje się**. Warunki, priorytet, zawieszenie i okres obowiązywania działają w obu identycznie.
  Nie rozdzielaj ich na dwa modele.
- ⚠️ **Pusty warunek znaczy „bez warunku na tej osi", NIE „warunek fałszywy".** Reguła `rate` bez
  ani jednego warunku jest **stawką bazową łowiska**; oba łowiska klienta obchodzą się jedną taką
  regułą plus jedną dopłatą.
- **Kwoty liczą się w GROSZACH, w liczbach całkowitych.** Obniżka przedsprzedażowa zaokrągla się
  raz na dobę i musi odróżnić 14,01 zł od 14,02 zł — arytmetyka zmiennoprzecinkowa nie daje na to
  gwarancji.
- ⚠️ **Dopłaty procentowe w `price_rules` są ZAKAZANE** („procent od czego", zależność od
  kolejności naliczania). Obniżka przedsprzedażowa nie jest wyjątkiem od tego zakazu, tylko innym
  mechanizmem — z jedną jawną podstawą i jednym miejscem naliczania (§4).

---

## 2. Dwa wymiary czasu na jednej regule

⚠️ **To jest miejsce, w którym najłatwiej o defekt**, bo oba wyglądają jak „zakres dat":

| Wymiar | Pola | Mierzony wobec | Odpowiada na pytanie |
|---|---|---|---|
| **Obowiązywanie zapisu** | `effective_from`/`effective_to` | **dzisiejszej DACIE** w strefie łowiska | czy ta reguła w ogóle bierze dziś udział w wycenie |
| **Warunek zakresu dat** | `first_day_on`/`last_day_on` | **wycenianej dobie** | których dób ta reguła dotyczy |

- **Bez rozdzielenia tych osi nie da się zaplanować zmiany ceny z wyprzedzeniem** — każda zmiana
  działałaby natychmiast. „Od 1 czerwca podnoszę cenę wakacji" to nowa reguła z `effective_from`
  na czerwiec i warunkiem dat obejmującym lipiec–sierpień.
- **Obie granice są DOMKNIĘTE**, jak horyzont i okno przedsprzedaży w 017: reguła obowiązuje także
  w dniu `effective_to`, a warunek obejmuje także dobę rozpoczynającą się `last_day_on`.
- ⚠️ **`effective_*` mierzy się dzisiejszą DATĄ, nie momentem i nie parametrem wywołania.**
  Rozróżnienie „wycena w koszyku kontra zapłata minutę po północy" wymaga utrwalonej transakcji,
  której nie ma w schemacie, i należy do snapshotu G1.
- ⚠️ **`first_day_on`/`last_day_on` to DNI ROZPOCZĘCIA DÓB**, tak samo jak w `whole_term_periods`.
  Nazw `starts_on`/`ends_on` **nie wolno tu użyć** — w `sale_periods` znaczą co innego
  ([`dostepnosc.md`](dostepnosc.md) §4).

---

## 3. Rozstrzyganie reguł

**Nachodzenie się stawek jest ZAMIERZONE, nie błędem.** „70 zł zawsze" koliduje z „90 zł
w piątki" w każdy piątek — i tak właśnie operator chce to zapisać. Stąd reguła wyboru, a nie zakaz
nachodzenia.

Kolejność dla jednej doby i jednej roli:

1. odrzuć reguły **zawieszone** i spoza `effective_*`;
2. odrzuć reguły, których którykolwiek warunek nie jest spełniony **dla tej doby**;
3. spośród `rate` wygrywa **najwyższy `priority`**; przy remisie **wyższa szczegółowość**;
   przy remisie nierozstrzygalnym — **błąd konfiguracji**;
4. **wszystkie** pasujące `surcharge` **sumują się** — przy kwotach wynik nie zależy od kolejności.

- ⚠️ **Warunek sprawdza się osobno dla KAŻDEJ doby**, nie „całe albo wcale": pobyt śr–pt przy
  dopłacie „czw–nd" dostaje ją za czwartek i piątek, a nie za środę.
- **Szczegółowość liczy się OSIAMI, nie polami.** Osie są cztery: dni tygodnia, zakres dat, liczba
  łowiących, rola. Zakres z jednym otwartym końcem to **jedna** oś, nie pół ani dwie — licząc pola,
  szczegółowość zależałaby od tego, czy operator domknął przedział.
- ⚠️ **Priorytet idzie PRZED szczegółowością.** Priorytet jest jedynym narzędziem, którym operator
  wyraża intencję wprost, więc musi wygrywać z regułą wyprowadzoną z kształtu warunków.
- **`anglers_count` porównuje się przez RÓWNOŚĆ z faktyczną obsadą z zapytania** — nigdy
  z `positions.max_anglers`, która jest pojemnością stanowiska i kusi wyłącznie nazwą.

### Remis nierozstrzygalny jest błędem zapisu

Remis to ten sam `priority` **i** ta sama szczegółowość przy warunkach spełnialnych jednocześnie.
Sprawdzenie ma jeden dom: [`PriceRuleOverlap`](../../app/Services/PriceRuleOverlap.php).

1. **Osie niezależne od doby** — liczba łowiących, rola, okno `effective_*` — muszą się przecinać
   każda z osobna. ⚠️ **Oś pusta przecina się ze wszystkim.**
2. ⚠️ **Dni tygodnia i zakres dat sprawdza się RAZEM, nie oś po osi.** Kolizja zachodzi tylko wtedy,
   gdy **istnieje doba** w przecięciu zakresów, której dzień rozpoczęcia należy do przecięcia
   zbiorów dni. Skrót „wystarczy niepuste przecięcie dni" wolno zastosować **wyłącznie** przy
   przecięciu nieograniczonym albo obejmującym co najmniej 7 dni.

⚠️ **To nie jest optymalizacja — bez tego walidacja odrzuca poprawne cenniki.** Stawka
„30.04–02.05" i stawka „piątki" sprawdzane oś po osi kolidują **zawsze**: oś dni przecina się, bo
pierwsza reguła jej nie ma, a oś dat — bo druga jej nie ma. Naprawdę kolidują tylko wtedy, gdy
w tym zakresie wypada piątek.

- **Porównywane są PARY, bez analizy przesłaniania.** Remis dwóch reguł jest błędem także wtedy,
  gdy trzecia, szczegółowsza i tak wygrywa w całym przecięciu; pełna analiza pokrycia jest
  nieproporcjonalnie droga wobec obejścia, którym jest podniesienie priorytetu o jeden.
- ⚠️ **Remis nie leci wyjątkiem.** Wraca wynikiem, żeby jedna zła para reguł nie wywróciła całego
  widoku kalendarza (019) — czyli jedynego miejsca, w którym operator ma ten błąd zobaczyć.

---

## 4. Osoba towarzysząca i obniżka przedsprzedażowa

- **Osoba towarzysząca NIE jest gałęzią w kodzie** — to reguła `rate` z warunkiem
  `participant_role = companion` i kwotą `0.00`. Nie dorabiaj dla niej wyjątku.
  ⚠️ Pole kwoty w cenniku dopuszcza **0,00**, inaczej niż przy usługach dodatkowych — i to jest
  powód, dla którego minimum jest parametrem `SharedFormComponents::getPriceInput()`.
- ⚠️ **Stawka osoby towarzyszącej musi mieć NAJWYŻSZY priorytet w cenniku.** Każda stawka
  warunkowa **bez** warunku roli pasuje także do niej; przy równym priorytecie i równej
  szczegółowości daje to remis, czyli błąd zapisu. Podniesienie priorytetu rozwiązuje to raz dla
  całego cennika — alternatywą byłoby dopisywanie warunku „rola: łowiący" do **każdej** pozostałej
  stawki.
- ⚠️ **Ta zasada chroni tylko w jedną stronę.** Walidacja remisu łapie priorytet **równy**;
  priorytet **wyższy** przepuszcza bez słowa, więc „Sylwester 150 zł" bez warunku roli i z wysokim
  priorytetem sprawi, że towarzysząca zapłaci 150 zł — bez błędu i bez śladu. Przed tym broni
  **ostrzeżenie** przy zapisie, nie model. Instrukcja nie wystarcza, bo zasada obowiązuje tylko
  dopóty, dopóki ktoś o niej pamięta.
- **Dopłata bez warunku roli obciąża także osobę towarzyszącą** — to zachowanie **poprawne**, nie
  defekt, i wynika z konfiguracji, a nie z kodu.

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
- **Odmowa wskazuje dobę, rolę i obsadę**, dla których zabrakło stawki — sam powód jest
  bezużyteczny przy pobycie wielodobowym i wieloosobowym, bo operator nie wie, którą regułę dopisać.

### Ostrzeżenie przy zapisie to POMOC, nie gwarancja

Zakres sprawdzenia: doby okresów sprzedaży **od dziś** do końca ostatniego okresu, obsady od 1 do
największego `max_anglers`, rola `companion` tylko na łowisku dopuszczającym osoby towarzyszące,
reguły obowiązujące **dziś**. Stanowisko bez podanej pojemności liczy się jako **1 łowiący i zero
towarzyszących**, a nie jest pomijane.

⚠️ **Dziura powstaje także POZA ekranem cennika** — wydłużeniem okresu sprzedaży, podniesieniem
`max_anglers` albo wygaśnięciem reguły w połowie sezonu — a sprawdzenie widzi wyłącznie dzisiejszy
stan reguł. **Gwarancją jest odmowa przy sprzedaży**, nie ostrzeżenie przy zapisie.

### Co jest błędem, a co ostrzeżeniem

Twarde reguły zapisu mieszkają w `app/Rules/`; ostrzeżenia — w
[`PricingConfigurationAudit`](../../app/Services/PricingConfigurationAudit.php), bo laravelowa
reguła walidacji potrafi tylko odrzucić zapis.

- **Błędy:** remis nierozstrzygalny dwóch stawek; `effective_to` przed `effective_from`;
  `last_day_on` przed `first_day_on`; obniżka spoza zakresu 0–100.
- **Ostrzeżenia:** dziura w cenniku; stawka bez warunku roli z priorytetem **wyższym** niż stawka
  osoby towarzyszącej. Oba są ostrzeżeniami, bo łowisko może świadomie chcieć takiej konfiguracji
  albo dopiero ją porządkuje.
