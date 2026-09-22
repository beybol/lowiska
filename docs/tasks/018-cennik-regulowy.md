# 018 — Cennik regułowy

## Opis problemu

Po zadaniach 015, 014, 016 i 017 system wie, **czym** handluje (doba), **czym** dysponuje (stanowiska),
**kiedy jest wyłączony** (sezony, blokady) i **co wolno kupić** (długość pobytu, spoiwo dób, horyzont,
przedsprzedaż). Nie wie natomiast **ile to kosztuje**.

Ceny są dziś w projekcie wyłącznie tam, gdzie nie rozstrzygają o sprzedaży doby: `additional_services.price`
i `long_term_permits`. **Nie istnieje cena doby na stanowisku** — a bez niej kalendarz podglądowy (019)
nie pokaże „po jakiej cenie", portal nie sprzeda niczego, a operator nie zobaczy skutku własnej
konfiguracji.

Zadanie realizuje moduł **M3** wraz z mechanizmem **G2** (deterministyczne rozstrzyganie reguł)
i częścią **G3** (walidacja konfiguracji przy zapisie) z
[Wymagań konfiguracji sprzedaży krótkoterminowej](../project/WYMAGANIA-SPRZEDAZ-KROTKOTERMINOWA.md).
Jest drugim zadaniem **punktu kontrolnego B**.

Makiety:

- **wiążąca** — [`makieta-018-cennik-regulowy.html`](../project/mockups/makieta-018-cennik-regulowy.html):
  ekran „Cennik" (stawki, dopłaty, cztery osie warunku), dwa wymiary czasu, tabele rozstrzygania
  i walidacji, **rozbicie wyceny** na przykładzie Łopienna wraz z pułapką roli, obniżka
  przedsprzedażowa na przykładzie Klasztornego, dziura w cenniku oraz diagram warstw z warstwą
  oferty;
- pomocnicza — [`makiety-panelu-lowiska-v2.html`](../project/mockups/makiety-panelu-lowiska-v2.html),
  sekcja **M3**. ⚠️ Powstała **przed** zadaniami 014–017 i nie zna ani pojęcia pobytu, ani spoiwa
  dób, ani przedsprzedaży na okresie sprzedaży — nie traktuj jej jako wiążącej.

**Podział pracy z 017 jest jednokierunkowy: 017 mówi, CO wolno kupić, 018 ILE to kosztuje.** Wycena
nie powtarza warunków sprzedawalności i nie woła `StaySellability`; `StaySellability` nie pyta
o cenę. ⚠️ **Nie znaczy to jednak, że składaniem obu odpowiedzi zajmuje się każdy pytający z osobna**
— od tego jest warstwa oferty opisana niżej.

## Wymagania

### Nowa tabela `price_rules` — jedna lista, dwa rodzaje reguł

Cennik jest **listą reguł z warunkami**, nie tabelą stawek po wymiarach (kluczowe wymaganie
architektoniczne M3). Dodanie wymiaru jest dodaniem warunku, czasowa obniżka — zawieszeniem reguły,
zmiana ceny wyjściowej — zmianą jednej liczby.

| Kolumna | Typ | Uwagi |
|---|---|---|
| `id` | `id()` | |
| `fishery_id` | `foreignId`, **wymagany**, `cascadeOnDelete()` | jak `sale_periods`, `whole_term_periods` |
| `kind` | `string` (enum `PriceRuleKind`: `rate`, `surcharge`) | `rate` **zastępuje** stawkę, `surcharge` **dodaje się** — jedna mechanika z flagą (O3) |
| `label` | `string` nullable | tekst widziany przez wędkarza w rozbiciu („Stanowisko tylko dla Ciebie"); konfigurowalny (D5 — [wymagania](../project/WYMAGANIA-SPRZEDAZ-KROTKOTERMINOWA.md) §M3) i **jednojęzyczny**, jak wszystkie treści operatora (D8 — [decyzje biznesowe](../project/DECYZJE-I-TODO-BIZNESOWE.md) §6) |
| `amount` | `decimal(8,2)` | kwota **za osobę za dobę**; `decimal(8,2)` jak `additional_services.price` |
| `priority` | `integer`, domyślnie `0` | jawny priorytet; wyższa liczba wygrywa |
| `is_suspended` | `boolean`, domyślnie `false` | zawieszenie bez usuwania — Klasztorne robi to dziś z dopłatą za wyłączność |
| `effective_from` | `date` nullable | od kiedy **ten zapis** bierze udział w wycenie |
| `effective_to` | `date` nullable | do kiedy |
| `weekdays` | `json` nullable | warunek: dni ISO 1–7 **rozpoczęcia doby**; `null`/pusty = każdy dzień |
| `first_day_on` | `date` nullable | warunek: pierwsza doba zakresu (dzień rozpoczęcia) |
| `last_day_on` | `date` nullable | warunek: ostatnia doba zakresu (dzień rozpoczęcia) |
| `anglers_count` | `unsignedTinyInteger` nullable | warunek: **dokładnie tyle** łowiących — porównanie przez RÓWNOŚĆ z faktyczną obsadą z zapytania, nigdy z `positions.max_anglers` (tamta kolumna to pojemność i kusi wyłącznie nazwą). Patrz „Rozstrzygnięcia”, pkt 24 |
| `participant_role` | `string` nullable (enum `ParticipantRole`: `angler`, `companion`) | warunek: rola uczestnika |
| | `softDeletes()`, `timestamps()` | |
| — | `index(['fishery_id','kind'])` | |

⚠️ **Warunek pusty znaczy „bez warunku na tej osi", nie „warunek fałszywy".** Reguła `rate` bez ani
jednego warunku jest **stawką bazową łowiska**. Oba łowiska klienta obchodzą się jedną taką regułą
plus jedną dopłatą.

⚠️ **Nazwy `first_day_on`/`last_day_on` są celowe i identyczne jak w `whole_term_periods` (017)** —
oba pola wskazują **dni rozpoczęcia dób**. Kolumn `starts_on`/`ends_on` **nie wolno tu użyć**, bo
w tym projekcie znaczą co innego (`sale_periods.ends_on` to ostatni dzień okna, a ostatnia
sprzedawalna doba zaczyna się dzień wcześniej). To dokładnie ta pułapka, którą 017 usunął zmianą
nazw — opis w [`dostepnosc.md`](../conventions/dostepnosc.md).

### Dwa wymiary czasu na jednej regule — nie wolno ich zlać

To jest miejsce, w którym najłatwiej o defekt, bo oba wyglądają jak „zakres dat":

| Wymiar | Pola | Mierzony wobec | Odpowiada na pytanie |
|---|---|---|---|
| **Obowiązywanie zapisu** | `effective_from`/`effective_to` | **dzisiejszej DACIE w strefie łowiska**, liczonej wewnątrz wyceny — nie momentowi i nie parametrowi wywołania (patrz „Rozstrzygnięcia”, pkt 21) | czy ta reguła w ogóle bierze dziś udział w wycenie |
| **Warunek zakresu dat** | `first_day_on`/`last_day_on` | **wycenianej dobie** | których dób ta reguła dotyczy |

Przykład rozdzielający oba: „od 1 czerwca podnoszę cenę wakacji" to nowa reguła z
`effective_from = 1.06` i warunkiem `first_day_on`/`last_day_on` obejmującym lipiec–sierpień.
Do 31 maja wycena lipca idzie po starej regule, od 1 czerwca po nowej. Bez rozdzielenia tych osi nie
da się zaplanować zmiany ceny z wyprzedzeniem, a każda zmiana działałaby natychmiast.

⚠️ **Obie granice są DOMKNIĘTE**, tak samo jak horyzont i okno przedsprzedaży w 017: reguła
obowiązuje także w dniu `effective_to`, a warunek obejmuje także dobę rozpoczynającą się
`last_day_on`. Porównania idą na **datach** w strefie łowiska, nie na momentach.

⚠️ Realizuje to **G6** („zmiany obowiązują od daty, nigdy wstecz"). Modelu rezerwacji nie ma, więc
nie ma jeszcze czego chronić przed zmianą wsteczną — reguła powstaje **razem z pojęciem**, tak jak
przy godzinie doby (015) i przy regułach pobytu (017).

### Rozstrzyganie reguł — obowiązkowe, bo nachodzenie jest normalne

Po dopuszczeniu stawek warunkowych nachodzenie się reguł `rate` jest **przypadkiem zamierzonym**:
„70 zł zawsze" koliduje z „90 zł w piątki" w każdy piątek (K1). Dla jednej doby i jednej roli
uczestnika:

1. odrzuć reguły zawieszone i spoza `effective_*`;
2. odrzuć reguły, których którykolwiek warunek nie jest spełniony **dla tej doby**;
3. spośród pozostałych reguł `rate` wybierz zwycięzcę: **najwyższy `priority`**, przy remisie
   **bardziej szczegółowa**, a przy remisie nierozstrzygalnym **zwróć informację o błędzie
   konfiguracji** — nigdy losową cenę (G2) i nigdy wyjątek (rozstrzygnięcie 10);
4. **wszystkie** pasujące reguły `surcharge` **sumują się** (K1a); przy kwotach wynik nie zależy od
   kolejności, więc żadnego rozstrzygania tu nie ma.

**Szczegółowość liczy się OSIAMI, nie polami.** Osie są cztery: dni tygodnia, zakres dat, liczba
łowiących, rola uczestnika. Oś jest niepusta, gdy niesie jakikolwiek warunek — więc zakres
z **jednym otwartym końcem** (sam `first_day_on`, bez `last_day_on`) liczy się jako **jedna** oś,
a nie pół ani dwie. Szczegółowość reguły to liczba niepustych osi, od 0 (stawka bazowa) do 4.

**Remis nierozstrzygalny** to dwie reguły `rate` o **tym samym `priority`** i **tej samej
szczegółowości**, których warunki dają się spełnić **jednocześnie**. Sprawdza się to dwustopniowo:

1. **Osie niezależne od doby** — liczba łowiących, rola, okno `effective_*` — muszą się przecinać
   każda z osobna. ⚠️ **Oś pusta przecina się ze wszystkim.**
2. ⚠️ **Dni tygodnia i zakres dat sprawdza się RAZEM, nie oś po osi** — bo nie są niezależne.
   Kolizja zachodzi tylko wtedy, gdy **istnieje doba** leżąca w przecięciu zakresów dat, której
   dzień rozpoczęcia należy do przecięcia zbiorów dni tygodnia.
   ⚠️ Skrót „wystarczy niepuste przecięcie dni" wolno zastosować **wyłącznie wtedy, gdy przecięcie
   zakresów jest nieograniczone albo obejmuje co najmniej 7 dni** — dopiero taki przedział na pewno
   zawiera każdy dzień tygodnia. Sam fakt, że któraś z reguł ma otwarty koniec, **nie wystarcza**:
   „od 01.01.2026" w parze z „30.04–02.05" daje przecięcie zamknięte i trzydniowe, więc trzeba je
   przejrzeć dobami.

⚠️ **Dlaczego to nie jest szczegół implementacyjny.** Stawka „30.04–02.05" i stawka „piątki" (obie
priorytet 0, szczegółowość 1) sprawdzane oś po osi kolidują **zawsze**: oś dni przecina się, bo
pierwsza reguła jej nie ma, a oś dat — bo druga jej nie ma. Naprawdę kolidują wyłącznie wtedy, gdy
w tym zakresie wypada piątek. Ponieważ remis jest **błędem zapisu** (G2), sprawdzanie oś po osi
**nie pozwoliłoby zapisać poprawnego cennika**.

⚠️ **Warunek sprawdza się osobno dla KAŻDEJ doby** (K3, potwierdzone jako P2). Pobyt śr–pt przy
dopłacie „czw–nd" dostaje dopłatę za czwartek i piątek, a nie za środę — i nie „całą albo wcale".

⚠️ **Osoba towarzysząca nie jest wyjątkiem w kodzie** — to reguła `rate` z warunkiem
`participant_role = companion` i kwotą `0.00` (O15). Nie dorabiaj dla niej gałęzi.

⚠️ **Stawka osoby towarzyszącej musi mieć NAJWYŻSZY priorytet w cenniku** — i to jest reguła
konfiguracji, którą trzeba wpisać do instrukcji, bo operator trafi na nią od razu. Powód: każda
stawka warunkowa **bez** warunku roli pasuje także do osoby towarzyszącej. Przy równym priorytecie
i równej szczegółowości („Osoba towarzysząca" — oś roli; „90 zł w piątki" — oś dni) powstaje
**remis nierozstrzygalny**, czyli błąd zapisu; gdyby dało się go rozstrzygnąć na korzyść drugiej
reguły, towarzysząca płaciłaby 90 zł. Podniesienie priorytetu stawki towarzyszącej rozwiązuje to
raz dla całego cennika — alternatywa, czyli dopisywanie warunku „rola: łowiący" do **każdej**
pozostałej stawki, jest pracochłonna i łatwo o niej zapomnieć przy kolejnej regule.

⚠️ **Sama ta zasada chroni tylko w jedną stronę i dlatego potrzebuje ostrzeżenia w kodzie.**
Walidacja remisu łapie priorytet **równy**, bo dopiero wtedy powstaje remis — priorytet **wyższy**
przepuszcza bez słowa. Operator, który za rok doda „Sylwester 150 zł" bez warunku roli i z priorytetem
200, sprawi, że osoba towarzysząca zapłaci w sylwestra 150 zł: bez błędu, bez ostrzeżenia i bez
śladu w konfiguracji, że coś poszło nie tak. Instrukcja tego nie zagwarantuje, bo zasada obowiązuje
tylko dopóty, dopóki ktoś o niej pamięta.

**Dlatego przy zapisie powstaje ostrzeżenie** (obok sprawdzania dziury): *stawka bez warunku roli ma
wyższy priorytet niż stawka z rolą „osoba towarzysząca" — w dniach, w których obowiązuje, towarzysząca
zapłaci tę stawkę*. Zgłaszane tylko wtedy, gdy warunki obu reguł da się spełnić **jednocześnie** —
tym samym sprawdzeniem co przy remisie, bez warunku równości priorytetu i szczegółowości.
⚠️ **Ostrzeżenie, nie błąd**: łowisko może świadomie chcieć, żeby w sylwestra płacili wszyscy.

⚠️ **Remis jest błędem także wtedy, gdy trzecia, bardziej szczegółowa reguła i tak wygrywa
w całym przecięciu.** Sprawdzenie porównuje pary, nie analizuje przesłaniania — pełna analiza
„czy przecięcie jest w całości pokryte regułą szczegółowszą" jest nieproporcjonalnie droga wobec
obejścia, którym jest podniesienie priorytetu jednej z dwóch reguł o jeden.

### Wycena pobytu — wynik z rozbiciem, nie jedna liczba

Wycena ma jeden dom w `app/Services/`. Odpowiada na pytanie: **ile kosztuje ten pobyt i z czego się
to składa.** Pyta się o pobyt (stanowisko, doba rozpoczęcia, liczba dób) i **skład uczestników**
(ilu łowiących, ile osób towarzyszących) — uczestnik jest **parametrem zapytania**, nie rekordem.

Wynikiem jest **struktura rozbicia**: pozycje per doba i per rola z kwotą, etykietą i wskazaniem
reguły, z której pozycja wynika, plus suma. ⚠️ **To nie jest snapshot G1** — patrz „Zakres wyłączeń".
Rozbicie trafia do kalendarza podglądowego (019) i przyszłego koszyka **przez warstwę oferty**
(niżej); gdyby wycena zwracała jedną liczbę, 019 nie miałby czego pokazać, a operator nie
zrozumiałby, skąd wzięło się 110 zł.

### Dziura w cenniku jest ODMOWĄ, nie ceną zerową

Doba w otwartym sezonie, do której **nie pasuje żadna reguła `rate`**, jest niesprzedawalna
z jawnym powodem (K2, G3). ⚠️ Nowa wartość w
[`SaleUnavailabilityReason`](../../app/Enums/SaleUnavailabilityReason.php) — enum powodów jest
**jeden dla całej sprzedaży** ([`dostepnosc.md`](../conventions/dostepnosc.md) §2). Wymaga to
gałęzi w wyczerpującym `match` w `label()` (brak = `UnhandledMatchError` dopiero w chwili odmowy)
oraz wpisu tłumaczenia. ⚠️ **Zadanie 017 jest już wdrożone i dołożyło do tego enuma sześć
wartości** (`StayTooShort`, `StayTooLong`, `WeekendBroken`, `WholeTermBroken`, `BeyondSaleHorizon`,
`BelowPresaleMinimum`) — to zadanie dokłada **siódmą**, do istniejącego bloku powodów pobytu,
a nie do pustego pliku.

⚠️ **Powód nazywa warstwa oferty, nie wycena.** Wycena zwraca rozbicie albo **typowaną informację,
że danej doby nie umie wycenić**, z rozróżnieniem dwóch przyczyn: *brak pasującej stawki* oraz
*remis nierozstrzygalny* (gdyby powstał ścieżką zapisu omijającą formularz). Na wartości
`SaleUnavailabilityReason` tłumaczy je dopiero warstwa oferty; wycena pozostaje wolna od słownika
odmów sprzedaży.

⚠️ **Żadna z tych dwóch sytuacji nie leci w górę jako wyjątek.** Remis rzucony wyjątkiem przerwałby
**cały** widok kalendarza (019) przez jedną złą parę reguł — a kalendarz jest właśnie tym miejscem,
w którym operator ma taki błąd zobaczyć. Obie wracają więc jako wynik, a kalendarz rysuje dobę jako
niesprzedawalną z czytelnym powodem.

**Zakres sprawdzenia przy zapisie** (inaczej implementacja wybierze go na ślepo):

| Wymiar | Zakres |
|---|---|
| doby | wszystkie doby okresów sprzedaży **od dziś do końca ostatniego okresu** — przeszłości nie sprzedajemy |
| liczba łowiących | od `1` do największego `max_anglers` wśród stanowisk łowiska; łowisko bez stanowisk pomijamy |
| rola | `angler` zawsze; `companion` **tylko wtedy**, gdy którekolwiek stanowisko ma `max_people > max_anglers` (obie wartości podane), czyli łowisko jawnie dopuszcza osoby towarzyszące |
| stan cennika | reguły obowiązujące **dziś** (`effective_*`) |

⚠️ **Pojemności są `nullable`** (migracja 014), więc zakres trzeba domknąć jawnie: stanowisko
**bez podanej obsady liczy się jako 1 łowiący i zero osób towarzyszących**, a nie jest pomijane.
Pominięcie znaczyłoby, że łowisko z nieuzupełnionymi pojemnościami nie dostaje sprawdzenia w ogóle;
przyjęcie „1" daje sprawdzenie najwęższe z możliwych, czyli takie, które nie zgłasza dziur dla
obsad, o których nic nie wiadomo.

⚠️ **Sprawdzenie nie przewiduje przyszłych zmian `effective_*`** — reguła wygasająca w połowie
sezonu otworzy dziurę, której dzisiejsze ostrzeżenie nie pokaże. Komunikat ma to mówić wprost
(„sprawdzono dla dzisiejszego stanu cennika"), zamiast udawać pełną analizę osi czasu.

⚠️ **Dziura powstaje też POZA tym ekranem** — wydłużenie okresu sprzedaży albo podniesienie
`max_anglers` na stanowisku otwiera ją bez dotykania cennika, a ostrzeżenie przy zapisie cennika
tego nie zobaczy. Mieści się to w zastrzeżeniu „pomocnicze, nie gwarancja", ale warto to wiedzieć,
zanim ktoś zacznie szukać błędu w walidacji. Gwarancją jest odmowa przy sprzedaży.

### Warstwa oferty pobytu — jedyne wejście dla 019 i koszyka

Od chwili, w której dziura w cenniku jest odmową, **odpowiedź na pytanie „czy wolno to sprzedać"
ma dwóch dostawców**: reguły pobytu (017) i cennik (018). Gdyby składał ich każdy pytający z osobna,
powstałyby dwa miejsca składania — czyli dokładnie to, czego zabraniają
[ADR-012](../adr/ADR-012-jedno-zrodlo-prawdy-o-dostepnosci.md)
i [ADR-013](../adr/ADR-013-warstwa-regul-pobytu-i-spoiwo-dob.md).

Dlatego 018 dokłada **cienką warstwę składającą** w `app/Services/` — „oferta pobytu": czy ten pobyt
jest **sprzedawalny i wyceniony**. Zwraca albo ofertę z rozbiciem, albo odmowę z jednym powodem,
niezależnie od tego, która warstwa niżej ją zgłosiła.

```
FishingDayCalendar        czas: doby, sezony                      (ADR-010)
        ↑
PositionAvailability      doba na stanowisku                      (ADR-012)
        ↑
StaySellability           ciąg dób: spoiwo, długość, horyzont     (ADR-013)
        ↑                          StayPricing   ile kosztuje     (018)
        └──────────────┬──────────────────┘
                 warstwa oferty            czy w ofercie          (018)
                        ↑
        kalendarz (019) · przyszły koszyk · portal
```

- **Warstwy niżej pozostają niezależne**: wycena nie pyta o sprzedawalność, sprzedawalność nie pyta
  o cenę. Tylko warstwa oferty zna obie.
- **Jest jedynym wejściem dla 019, koszyka i portalu.** Panel konfiguracyjny nadal woła to, co
  odpowiada na jego pytanie — sam cennik albo samą sprzedawalność.
- **Odmowa „brak ceny" wskazuje, CZEGO dotyczy** — doby, roli i obsady, dla których zabrakło stawki.
  To ta sama zasada, którą 017 przyjął dla odmów przepuszczanych z poziomu doby (ADR-013,
  rozstrzygnięcie 18): sam powód bez wskazania jest bezużyteczny przy pobycie wielodobowym
  i wieloosobowym, bo operator nie wie, którą regułę dopisać.
- Kolejność jest ta sama co wszędzie: **najpierw sprzedawalność, potem cena**. Pobyt niesprzedawalny
  nie jest wyceniany, więc odmowa niesie przyczynę trwalszą („stanowisko wycofane" przed „brak ceny").

#### Dwie odpowiedzi, nie jedna — i kształt konstruktora

Kalendarz podglądowy (019) potrzebuje od tej warstwy **dwóch** rzeczy:

1. **„Czy ten pobyt wolno kupić i ile kosztuje"** — opisane wyżej.
2. **„Jaki jest najkrótszy kupowalny pobyt rozpoczynający się tą dobą"** — bo domyślnym widokiem
   kalendarza są „ceny od".

⚠️ **Druga odpowiedź należy TUTAJ, nie do kalendarza.** Bez niej 019 miałby dwa wyjścia, oba złe:
próbować kolejnych długości (przy siatce stanowiska × doby to dziesiątki tysięcy wywołań) albo
**odtworzyć u siebie reguły z 017** — czyli złamać ADR-013 i zrobić z widoku drugie źródło prawdy.
Wyliczenie składa się wyłącznie z rzeczy, które warstwy niżej już znają:

- doba **w środku pakietu** → brak odpowiedzi: pobytu nie da się tu zacząć; wynik niesie **pierwszą
  dobę pakietu**, żeby wołający mógł powiedzieć „zacznij w środę";
- doba **rozpoczynająca pakiet** → co najmniej długość pakietu **po przycięciu** do dób sprzedawalnych;
- w pozostałych przypadkach co najmniej `min_nights`;
- ⚠️ **z wyjątkiem pobytu obejmującego pakiet ze świętem, który jest z `min_nights` ZWOLNIONY**
  (reguła 7) — przy `min_nights = 5` i trzydobowej Majówce najkrótszy kupowalny pobyt to **3 doby**,
  nie 5. Pominięcie tego wyjątku daje zawyżoną cenę „od" albo fałszywą odmowę dokładnie w dniach,
  które operator sprawdza najuważniej;
- w oknie przedsprzedaży analogicznie `presale_min_nights`, z tym samym zwolnieniem przy włączonej
  fladze;
- gdy wyliczone minimum przekracza `max_nights` → doba jest **niesprzedawalna** (to ten konflikt,
  który zostaje otwartym pytaniem 3).

Dane są już dostępne: `StaySellability::bundleAround()` powstało w 017.

⚠️ **Warstwa oferty konstruuje się PER STANOWISKO**, tak jak `PositionAvailability`
i `StaySellability` (oba biorą `Position` w konstruktorze). Gdyby budowała zależności od nowa przy
każdym wywołaniu, memoizacja dopuszczona przez [`dostepnosc.md`](../conventions/dostepnosc.md) §2
(blokady wczytane raz na instancję) przestałaby działać — a wtedy **pomiar kosztu w 019 wyszedłby zły
z powodu kształtu konstruktora, nie realnej ceny algorytmu**, i skłoniłby do bufora, którego może
w ogóle nie trzeba.

⚠️ **To zmienia zapis, który dziś obowiązuje w trzech miejscach** — ADR-013 (opcja A: `StaySellability`
„jest jedynym wejściem dla panelu, cennika (018), kalendarza (019)"), `dostepnosc.md` §2 oraz docblock
`StaySellability`. Wszystkie trzy wskazują cennik jako wołającego, a po tym zadaniu wołającym jest
**warstwa oferty**. Poprawki są w „Zmianach dokumentacji". Rekomendacja zadania: warstwa oferty
dostaje **własny ADR** — powstał nim [ADR-015](../adr/ADR-015-warstwa-oferty-pobytu.md) — a ADR-013
krótką sekcję **Aktualizacja z odsyłaczem**; decyzja A zostaje w mocy, zmienia się wyłącznie lista
wołających.

### Procentowa obniżka w przedsprzedaży

Zakup dokonany w oknie przedsprzedaży okresu (kolumny z 017) może być tańszy.

| Zmiana | Kolumna | Typ | Uwagi |
|---|---|---|---|
| dodać do `sale_periods` | `presale_discount_percent` | `decimal(5,2)` nullable | obok `presale_min_nights` z 017; `null` = bez obniżki |

⚠️ **Pole renderuje się na ekranie „Sprzedaż i sezony", NIE na „Cenniku"** — jako piąte pole bloku
przedsprzedaży w wierszu okresu (`ManageSaleSettings`), obok okna zakupu, warunku długości i flagi
wyjątku dla świąt z 017. Wszystkie pięć opisuje **tę samą ofertę tego samego sezonu**; rozdzielenie
ich zmuszałoby operatora do składania jednej przedsprzedaży z dwóch formularzy i pozwalałoby ustawić
obniżkę dla okresu, który przedsprzedaży w ogóle nie ma. Pole jest `->visible()` razem z resztą
bloku, a wyłączenie przedsprzedaży czyści je tak samo jak pozostałe cztery kolumny.

- **Liczona PER DOBA**: procent zdejmuje się z kwoty każdej doby osobno i **każda doba pokazuje
  własną obniżkę** w rozbiciu.
- **Ziarnistość i zaokrąglenie**: podstawą jest **suma pozycji tej doby** (wszystkie role razem),
  obniżka liczy się z niej **raz** i zaokrągla do pełnego grosza **połówki w górę**. Nie liczymy
  osobno na każdą pozycję doba × osoba — to mnożyłoby zdarzenia zaokrąglenia bez żadnego zysku,
  skoro obniżka i tak jest pokazywana per doba.
- **Podstawą jest kwota pobytu za tę dobę** — wygrana stawka `rate` **wraz ze wszystkimi pasującymi
  dopłatami** `surcharge`. **Usługi dodatkowe (020) nie podlegają obniżce**; gdy powstaną, mają zostać
  poza podstawą. Potwierdzone w „Rozstrzygnięciach”, pkt 22 — wraz z powodem, dla którego obniżka
  NIE liczy się od samej stawki: jej wysokość zależałaby wtedy od tego, czy operator wpisał kwotę
  jako stawkę, czy rozłożył ją na stawkę i dopłatę.
- Obniżka obowiązuje wyłącznie przy zakupie **w otwartym oknie przedsprzedaży** tego okresu, którego
  dotyczy doba.

⚠️ Znane ograniczenie przyjęte świadomie: liczenie per doba z zaokrągleniem per doba może dać sumę
różną o grosze od procentu policzonego od całości. Wybrano je, bo obniżka ma być **widoczna przy
każdej dobie**. Nie „naprawiaj" tego przy implementacji przeliczaniem na całość.

⚠️ To **nie jest** dopłata procentowa zakazana w M3 („procent od czego", zależność od kolejności) —
to modyfikator całego zakupu w oknie, z jawnie wskazaną podstawą i jednym miejscem naliczania.
Zakaz dopłat procentowych **zostaje w mocy** dla `price_rules`.

### Panel — zmiana na ekranie „Sprzedaż i sezony"

Jedna zmiana, jedno pole: **„Obniżka ceny"** w bloku przedsprzedaży wiersza okresu sprzedaży
(`ManageSaleSettings`, blok z zadania 017). Nagłówek wiersza repeatera pokazuje ją obok okna
przedsprzedaży („przedsprzedaż 4–14.11.2026 · −10%"). Reszta ekranu bez zmian.

### Panel — nowa pozycja sub-nawigacji „Cennik"

| Element | Kształt |
|---|---|
| strona | strona zasobu `FisheryResource` (`getPages()`), wzorem `ManageSaleSettings` i `ManageSaleRules`; **osobna pozycja** w `getRecordSubNavigation()`, **za „Reguły sprzedaży", przed „Stanowiska"** — trzy ekrany konfiguracji sprzedaży stoją razem |
| stawki | `Repeater` reguł `kind = rate`: kwota, etykieta, priorytet, zawieszenie, `effective_*`, warunki |
| dopłaty | drugi `Repeater` (`kind = surcharge`) — ta sama mechanika wpisu, osobna lista, bo operator myśli o nich osobno |
| warunki w wierszu | dni tygodnia (`ToggleButtons`, wielokrotny), zakres dat (`DatePicker` ×2), liczba łowiących (`Select`), rola uczestnika (`Select`) |
| waluta | z `fisheries.currency_id`; kwoty bez wyboru waluty na regule |
| pole kwoty | `SharedFormComponents::getPriceInput()` — istniejący komponent z walidacją i normalizacją przecinka |
| okruszki i powrót po zapisie | `FisheryNavigation::fisheryBreadcrumbs()` i powrót do huba, jak `ManageSaleSettings` |
| autoryzacja | przez `FisheryPolicy` (`EditRecord::authorizeAccess()`), jawnie |
| ostrzeżenia przy zapisie | remis nierozstrzygalny dwóch reguł `rate` (**błąd**, nie ostrzeżenie — G2); **dziura w cenniku**: otwarty sezon bez pasującej stawki (ostrzeżenie, odmowa dopiero przy sprzedaży); **stawka bez warunku roli z priorytetem wyższym niż stawka osoby towarzyszącej** (ostrzeżenie — towarzysząca zapłaci tę stawkę) |

⚠️ **`PriceRule` NIE dostaje zasobu Filamenta, polityki ani uprawnień Shielda** — jak `SalePeriod`
(015) i `WholeTermPeriod` (017). `shield:generate` wyprowadza uprawnienia z zarejestrowanych zasobów,
więc polityka pytająca o `'view_any:price_rule'` wywróciłaby `ShieldPermissionNamesTest`. Dostępu
pilnuje `FisheryPolicy` ([`autoryzacja.md`](../conventions/autoryzacja.md) §5).

⚠️ Strona zasobu, nie strona panelu: `FisheryResource` jest zarejestrowany w obu panelach, więc
zadanie **nie dotyka `OwnerPanelProvider` ani `AdminPanelProvider`** i nie dopisuje uprawnień do
listy w `tests/TestCase.php`.

Model `PriceRule` z `SoftDeletes`, `LogsActivity` i `HasFactory`, relacja `belongsTo(Fishery)`;
`Fishery` dostaje `hasMany` z generykami (`@return HasMany<PriceRule, $this>`), a `SalePeriod` —
`presale_discount_percent` w `$fillable` z castem.

### Bramka udostępnienia stawek warunkowych — NIE budujemy jej

Rozdział 11 wymagań stawia warunek: stawki warunkowe nie trafiają do operatora, dopóki nie działa
kalendarz podglądowy (019), bo samoobsługa bez podglądu wyniku zamienia elastyczność w generator
błędów. **Warunek jest spełniony przez harmonogram wdrożenia, nie przez kod**: wdrożenie obejmuje
zadania 017–021 razem, więc żaden operator nie zobaczy stawek warunkowych przed 019 (decyzja
z 2026-09-21). Nie powstaje ani flaga w konfiguracji, ani kolumna na łowisku, ani rozgałęzienie
po panelu.

⚠️ To jest **zmiana zapisu w wymaganiach**, nie jego obejście — `WYMAGANIA` §M3 i rozdział 11 mówią
dziś o bramce jako o mechanizmie. Trzeba je poprawić (patrz „Zmiany dokumentacji").

### Walidacja — dom w `app/Rules/`

Remis nierozstrzygalny reguł `rate`, porządek `effective_from`/`effective_to`, porządek
`first_day_on`/`last_day_on`, zakres procentu obniżki (0–100), wykrycie dziury w cenniku oraz
**ostrzeżenie o stawce bez warunku roli z priorytetem wyższym niż stawka osoby towarzyszącej** idą do
`app/Rules/`, nie do formularza — inaczej druga ścieżka zapisu (import, seed, przyszłe API) obejdzie
regułę. Sprawdzenia dotyczące **zbioru** reguł (remis, dziura) siedzą na całym `Repeater`-ze, nie na
pojedynczym polu — walidacja jednego wiersza nigdy nie zobaczy pozostałych (ta sama pułapka co
w `SalePeriodsDoNotOverlap`).

### Tłumaczenia

Etykiety pól, tytuł strony, etykieta pozycji w sub-nawigacji, nazwy rodzajów reguł i ról uczestnika,
komunikaty walidacji, ostrzeżenia oraz **komunikat odmowy „brak ceny"** w `lang/pl.json`.

## Kryteria akceptacji

- [x] Migracje przechodzą w obie strony na bazie zawierającej dane; istniejące okresy sprzedaży
      dostają puste `presale_discount_percent` i nic się nie zmienia w ich zachowaniu.
- [x] **Łopienno odwzorowane**: stawka bazowa 70,00 zł bez warunku plus dopłata „Stanowisko tylko dla
      Ciebie" 20,00 zł przy jednym łowiącym w dniach czw–nd, z warunkiem
      `participant_role = angler`. Pobyt jednej osoby od czwartku na 5 dób daje
      90+90+90+90+70 = 430,00 zł, a rozbicie pokazuje, która doba niesie dopłatę.
- [x] **Klasztorne odwzorowane**: stawka 130,00 zł oraz dopłata **zawieszona** — zawieszona reguła
      nie wchodzi do wyceny, ale zostaje w cenniku i daje się włączyć bez wpisywania od nowa.
- [x] **Warunek liczony per doba** (K3/P2): pobyt śr–pt przy dopłacie „czw–nd" nalicza ją za czwartek
      i piątek, nie za środę i nie za cały pobyt.
- [x] **Osoba towarzysząca** wycenia się regułą `rate` z warunkiem roli i kwotą 0,00 — bez gałęzi
      w kodzie.
- [x] **Stawka towarzyszącej kontra stawka warunkowa bez roli**: „Osoba towarzysząca" (rola, prio 10)
      i „90 zł w piątki" (dni, prio 10) są zgłaszane jako **remis przy zapisie**; po podniesieniu
      priorytetu stawki towarzyszącej zapis przechodzi, a w piątek łowiący płaci 90,00 zł, podczas
      gdy towarzysząca 0,00 zł.
- [x] **Priorytet WYŻSZY niż stawka towarzyszącej daje ostrzeżenie, nie błąd**: „Sylwester 150 zł"
      bez warunku roli, priorytet 200, przy stawce towarzyszącej z priorytetem 100 → zapis
      **przechodzi z ostrzeżeniem**, a wycena 31.12 daje 150,00 zł także osobie towarzyszącej.
      Ostrzeżenia **nie ma**, gdy warunków obu reguł nie da się spełnić jednocześnie.
- [x] **Dopłata BEZ warunku roli obciąża także osobę towarzyszącą** — i to jest zachowanie poprawne,
      nie defekt. Przy dopłacie „obsada 1, czw–nd" bez warunku roli czwartek dla łowiącego
      z towarzyszącą kosztuje 70+20 + 0+20 = 110,00 zł; po dołożeniu `participant_role = angler`
      ta sama konfiguracja daje 90,00 zł, czyli tyle, co pobyt samego łowiącego. Oba przypadki mają
      test, bo różnica bierze się z konfiguracji, a nie z kodu.
- [x] **Priorytet wygrywa**: „70 zł zawsze" (priorytet 0) i „90 zł w piątki" (priorytet 10) → piątek
      kosztuje 90,00 zł, czwartek 70,00 zł.
- [x] **Szczegółowość rozstrzyga remis priorytetów**: przy równym priorytecie reguła z dwoma
      spełnionymi warunkami wygrywa z regułą z jednym.
- [x] **Remis nierozstrzygalny to błąd konfiguracji**, nie losowa cena: dwie reguły `rate` o tym samym
      priorytecie i tej samej szczegółowości, obie pasujące do doby → **zapis odrzucony**. Gdyby taki
      stan powstał ścieżką omijającą formularz, wycena **zwraca typowaną informację o niemożności
      wyceny** zamiast wybierać którąkolwiek z dwóch kwot — nie rzuca wyjątku (rozstrzygnięcie 10).
- [x] **Dopłaty sumują się** (K1a): „90 zł w piątki" + „+20 zł za wyłączność" → piątek na jedną osobę
      to 110,00 zł, a rozbicie pokazuje obie pozycje osobno.
- [x] **Dwa wymiary czasu nie mylą się**: reguła z `effective_from` w przyszłości nie wchodzi do
      dzisiejszej wyceny, choć jej warunek zakresu dat pasuje do wycenianej doby; reguła obowiązująca
      dziś, ale z warunkiem dat spoza doby — również nie wchodzi. Test zamraża czas.
- [x] **Dziura w cenniku**: doba w otwartym sezonie bez pasującej reguły `rate` zwraca odmowę
      z nowym powodem, a nie cenę 0,00 zł; ostrzeżenie pojawia się już przy zapisie cennika.
- [x] **Obniżka przedsprzedażowa liczy się per doba**: przy 10% i stawce 70,00 zł z dopłatą 20,00 zł
      doba kosztuje 81,00 zł, a rozbicie pokazuje obniżkę **przy każdej dobie** osobno.
- [x] Obniżka **nie obejmuje** kwot spoza pobytu i nie nalicza się poza otwartym oknem przedsprzedaży
      ani dla dób innego okresu sprzedaży.
- [x] **Pole obniżki mieszka w bloku przedsprzedaży na ekranie „Sprzedaż i sezony"**, nie na
      „Cenniku": jest widoczne dopiero po włączeniu przedsprzedaży okresu, a jej wyłączenie czyści je
      razem z pozostałymi czterema kolumnami. Nie da się zapisać obniżki dla okresu bez przedsprzedaży.
- [x] **Wycena zwraca rozbicie**, nie jedną liczbę: pozycje per doba i per rola ze wskazaniem reguły,
      z której wynikają, oraz sumę — struktura nadaje się do wyrenderowania przez 019.
- [x] Wycena **nie woła `StaySellability`** i nie powtarza warunków sprzedawalności; zmiana reguł
      pobytu z 017 nie zmienia wyniku wyceny.
- [x] **Warstwa oferty składa obie odpowiedzi w JEDNYM miejscu**: pobyt niesprzedawalny wg 017
      dostaje odmowę **bez wyceniania**, a pobyt sprzedawalny bez pasującej stawki — odmowę „brak
      ceny". Przy obu przyczynach naraz wynik niesie tę z 017 (trwalszą).
- [x] **Warstwa oferty podaje najkrótszy kupowalny pobyt dla wskazanej doby** — z pakietem,
      `min_nights`, minimum przedsprzedaży i `max_nights` uwzględnionymi po jej stronie. Doba
      w środku pakietu zwraca „nie da się zacząć" wraz z pierwszą dobą pakietu.
- [x] **Zwolnienie świąteczne działa też w najkrótszym pobycie**: przy `min_nights = 5` i trzydobowym
      święcie najkrótszy kupowalny pobyt od pierwszej doby święta to **3 doby**, a nie 5.
- [x] **Warstwa oferty przyjmuje `Position` w konstruktorze**, jak `PositionAvailability`
      i `StaySellability` — dzięki czemu wielokrotne pytania o to samo stanowisko nie wczytują blokad
      i reguł od nowa. Test liczy zapytania przy serii pytań o jedno stanowisko.
- [x] **Szczegółowość liczona osiami**: reguła z warunkiem dni tygodnia i zakresem dat (2 osie)
      wygrywa przy równym priorytecie z regułą mającą sam zakres dat (1 oś); zakres z jednym otwartym
      końcem liczy się jako jedna oś, nie dwie.
- [x] **Remis wykrywa się przy osi pustej**: dwie reguły o tym samym priorytecie i tej samej
      szczegółowości, z przecinającymi się osiami niezależnymi od doby i nachodzącymi `effective_*`,
      są zgłaszane jako kolizja; rozłączne `effective_*` kolizji nie tworzą.
- [x] **Dni tygodnia i zakres dat sprawdzane RAZEM**: para „30.04–02.05" + „piątki" jest kolizją
      **tylko wtedy**, gdy w tym zakresie wypada piątek. Test ma obie wersje: zakres z piątkiem
      (kolizja) i zakres bez piątku (**zapis przechodzi**) — inaczej walidacja blokuje poprawny
      cennik.
- [x] **Remis powstały poza formularzem nie wywraca kalendarza**: wycena zwraca wtedy typowaną
      informację o niemożności wyceny, a warstwa oferty — odmowę z powodem. Żadna ścieżka nie rzuca
      wyjątku do wołającego.
- [x] **Dziura w cenniku sprawdza zadeklarowany zakres**: doby od dziś do końca ostatniego okresu,
      obsady od 1 do największego `max_anglers`, rola `companion` tylko na łowisku dopuszczającym
      osoby towarzyszące. Ostrzeżenie mówi wprost, że dotyczy dzisiejszego stanu cennika.
- [x] **Stanowisko bez podanej pojemności** (`max_anglers = null`) jest sprawdzane jako 1 łowiący
      i nie włącza sprawdzania roli `companion`; łowisko złożone wyłącznie z takich stanowisk nadal
      dostaje ostrzeżenie o dziurze, jeśli brakuje stawki dla jednego łowiącego.
- [x] **Odmowa „brak ceny" wskazuje dobę, rolę i obsadę**, dla których zabrakło stawki — sam powód
      nie wystarcza, tak samo jak przy odmowach z poziomu doby w 017.
- [x] **Granice domknięte**: reguła obowiązuje w dniu `effective_to`, a warunek zakresu obejmuje dobę
      rozpoczynającą się `last_day_on`; dzień po każdej z tych granic już nie wchodzi.
- [x] **Obniżka zaokrągla RAZ na dobę**: dwóch łowiących po 70,05 zł w dobie objętej przedsprzedażą
      z obniżką 10% → podstawa 140,10 zł, obniżka **14,01 zł**. Liczenie osobno na każdą osobę dałoby
      7,005 → 7,01 i razem 14,02 zł, więc test musi rozróżniać te dwie kwoty. ⚠️ Kwota bez połówki
      grosza (np. 110,00 zł) **niczego tu nie sprawdza**.
- [x] Kwoty zapisują się z przecinkiem i kropką tak samo (wzorzec `SharedFormComponents::getPriceInput()`),
      a asercje o zapisanej wartości idą przez `DB::table(...)->value(...)`, nie przez akcesor
      ([`panel-wlasciciela.md`](../conventions/panel-wlasciciela.md) §4).
- [x] Właściciel nie widzi ani nie edytuje cennika cudzego łowiska.
- [x] `ShieldPermissionNamesTest` jest zielony, a w `app/Policies/` **nie przybywa** żaden plik.
- [x] Trwałe usunięcie łowiska zabiera ze sobą jego reguły cenowe.
- [x] Zielony zakres T2 zadeklarowany niżej.
- [ ] **Pełny pakiet testów ODROCZONY** do punktu kontrolnego B (po zadaniach 017–019) — odroczony,
      nie pominięty.

## Zakres testów

- **Tier:** T2
- **Uruchamiamy:** `docker compose exec app php artisan test --filter="StayPricingTest|PriceRuleTest|PriceRuleResolutionTest|StayOfferTest|PricingPageTest|StaySellabilityTest|PositionAvailabilityTest|FishingDayTest|SalePeriodTest|SaleSettingsPageTest|SaleRulesPageTest|AdditionalServicePriceTest|ActivityLoggingTest|ShieldPermissionNamesTest|AdminPanelTest|OwnerPanelTest|FisheryAccessTest"`
- **Uzasadnienie:** zadanie trafia w **jeden** wyzwalacz T3 z `CLAUDE.md` — migracje. Polityki nie
  dokłada (autoryzacja przez istniejącą `FisheryPolicy`), providerów paneli nie dotyka (strona należy
  do `FisheryResource`, zarejestrowanego w obu panelach) i nie rusza `tests/TestCase.php`, bo nie
  powstaje nowy zasób. Odstępstwo jest **pakietowe, nie punktowe**: rozdział 14.2
  [wymagań](../project/WYMAGANIA-SPRZEDAZ-KROTKOTERMINOWA.md) ustala T2 dla zadań 014–021 i pełny
  pakiet na punktach kontrolnych; dla tego zadania jest to punkt **B**, po zadaniach 017–019.
  Zakres T2 obejmuje klasy nowe w tym zadaniu oraz to, na czym stoją albo z czym dzielą mechanikę:
  doby i sezony, dostępność, reguły pobytu z 017 (dowód, że wycena ich nie rusza), ekrany sezonów
  i reguł, istniejący komponent kwoty, dziennik zmian, nazwy uprawnień, granice obu paneli
  i zawężanie widoczności do własnych łowisk.
  ⚠️ Nazwy pięciu pierwszych klas są **propozycją** — jeśli implementacja nazwie je inaczej,
  poprawia filtr razem z nimi, zamiast zostawiać komendę, która cicho nic nie uruchamia.
  ⚠️ `SaleSettingsPageTest` musi dostać przypadek `presale_discount_percent` — to strona zmieniana,
  nie tylko zależna.
  ⚠️ Zadanie nie trafia do `docs/tasks/implemented/`, dopóki punkt kontrolny B nie jest zielony.

## Zakres wyłączeń

- **Snapshot oferty w transakcji (G1)** — poza tą iteracją. Składa się z ceny z rozbiciem, polityki
  zwrotu, wersji regulaminu, parametrów stanowiska i uczestników; **dwa z tych składników powstają
  dopiero w 021**, a pojemnika (transakcji) i uczestników nie ma w schemacie wcale. 018 wnosi
  wyłącznie **strukturę rozbicia**, którą przyszły snapshot utrwali.
- **Rezerwacje, koszyk i płatności** — poza iteracją. Pobyt i skład uczestników są **parametrami
  zapytania o cenę**, nie zapisywanymi rekordami.
- **Kalendarz podglądowy** „co z tego wynika" doba po dobie — zadanie 019. Tu powstaje wycena
  zwracająca rozbicie, nie ekran, który je rysuje.
- **Wycena usług dodatkowych** — zadanie 020 (jednostka rozliczenia, limit egzemplarzy). Usługi mają
  dziś własną cenę i zostają nietknięte; **nie wchodzą też do podstawy obniżki przedsprzedażowej**.
- **Dopłaty procentowe w `price_rules`** — odrzucone w M3 („procent od czego", zależność od
  kolejności naliczania). Obniżka przedsprzedażowa nie jest wyjątkiem od tego zakazu, tylko innym
  mechanizmem, z jedną jawną podstawą.
- **Bramka udostępnienia stawek warunkowych** — nie powstaje; warunek z rozdziału 11 spełnia
  harmonogram wdrożenia (017–021 razem), nie kod.
- **Progi za kolejne osoby, dopłata za dodatkową wędkę, kody rabatowe, cena per stanowisko** —
  „nie teraz" wprost w M3.
- **Cena zależna od pozwolenia długookresowego** — obowiązuje Z4: nie budujemy, ale nie zamykamy drzwi.
- **Dopisywanie cennika istniejącym łowiskom** — migracja tego nie robi; łowisko bez reguł po prostu
  nie sprzedaje (dziura w cenniku jest odmową).

## Zmiany dokumentacji

- [x] `docs/conventions/cennik.md` — **nowy plik**: cennik jako lista reguł, dwa rodzaje z flagą
      zastępuje/dodaje, kolejność rozstrzygania (priorytet → szczegółowość → błąd), sumowanie dopłat,
      **dwa wymiary czasu na regule i zakaz ich zlewania**, warunek liczony per doba, dziura
      w cenniku jako odmowa, wycena zwracająca rozbicie oraz granica „wycena nie pyta
      o sprzedawalność, sprzedawalność nie pyta o cenę".
      ⚠️ Musi też nieść **niezmiennik o osobie towarzyszącej**: jej stawka ma najwyższy priorytet
      w cenniku, bo każda stawka warunkowa bez warunku roli pasuje także do niej — wraz z tym, że
      zasada chroni tylko przed priorytetem równym, a przed wyższym broni ostrzeżenie przy zapisie.
      To jest wiedza, która ma przetrwać dłużej niż pamięć autorów, więc `MANUAL.md` jej nie zastąpi
- [x] `CLAUDE.md` — wiersz w tabeli „Konwencje powierzchni" kierujący na `docs/conventions/cennik.md`
      (sama tabela routingu, bez niezmienników)
- [x] `docs/conventions/panel-wlasciciela.md` — §6: nowa strona ustawień „Cennik" i jej miejsce
      w kolejności sub-nawigacji
- [x] [`docs/adr/ADR-013-warstwa-regul-pobytu-i-spoiwo-dob.md`](../adr/ADR-013-warstwa-regul-pobytu-i-spoiwo-dob.md)
      — **krótka sekcja Aktualizacja z odsyłaczem** do nowego ADR-a warstwy oferty: `StaySellability`
      przestaje być bezpośrednim wejściem dla cennika, kalendarza i portalu. Decyzja A zostaje
      w mocy — zmienia się wyłącznie lista wołających
- [x] `docs/conventions/dostepnosc.md` §2 — ta sama poprawka listy wołających (dziś: „Panel, portal,
      cennik (018) i kalendarz podglądowy (019) wołają tę klasę")
- [x] Docblock [`StaySellability`](../../app/Services/StaySellability.php) — jak wyżej; klasa
      zostaje jedynym źródłem prawdy o **sprzedawalności pobytu**, ale nie jedynym wejściem dla
      pytających o ofertę
- [x] `docs/project/WYMAGANIA-SPRZEDAZ-KROTKOTERMINOWA.md` — cztery poprawki: §14.1 wiersz 018
      („wnosi G1, G2" → „G2 plus struktura rozbicia pod G1"); §14.1 **wiersz 019** — skreślić
      „bramka udostępnienia stawek warunkowych", bo po tej decyzji nie powstaje; §M3 i rozdział 11 —
      **bramka przestaje być mechanizmem**, warunek spełnia harmonogram wdrożenia; §M3 — dopisać
      procentową obniżkę przedsprzedażową jako część zakresu
- [x] `MANUAL.md` — nowa sekcja „Cennik" (stawki, dopłaty, warunki, zawieszanie, od kiedy obowiązuje
      zmiana, obniżka przedsprzedażowa) z perspektywy operatora; pozycja w liście „Kolejność
      wprowadzania danych" (§2) za „Regułami sprzedaży".
      ⚠️ Musi **uprzedzać** o dwóch rzeczach: (1) dopłata bez warunku roli nalicza się **za każdą
      osobę**, także towarzyszącą — to najłatwiejsza do popełnienia i najtrudniejsza do zauważenia
      pomyłka konfiguracyjna w całym cenniku; (2) **stawka osoby towarzyszącej ma mieć najwyższy
      priorytet**, inaczej zderzy się z każdą stawką warunkową bez warunku roli
- [x] `README.md` — bez zmian
- [x] `CHANGELOG.md` — wpis w changelogu

## Ograniczenia techniczne

- Laravel 13, Filament 5, PHP 8.4 — stan po zadaniu 009.
- **Osobny plik migracji na każdą zmianę**: `price_rules` (nowa tabela) oraz
  `presale_discount_percent` w `sale_periods`.
- **Zadanie idzie po 017** i korzysta z: pojęcia doby ([ADR-010](../adr/ADR-010-doba-wedkarska-jako-przedzial-czasu.md)),
  okresów sprzedaży i kolumn przedsprzedaży (015, 017), pojemności stanowiska (014). **Nie liczy dób
  po swojemu** — robi to `FishingDayCalendar` ([`dostepnosc.md`](../conventions/dostepnosc.md) §1).
- Kwoty: `decimal(8,2)`, jak `additional_services.price`; waluta z `fisheries.currency_id`.
  ⚠️ Przy asercjach o zapisanej kwocie używaj `DB::table(...)->value(...)` — akcesory formatujące
  potrafią skłamać o utracie danych (`panel-wlasciciela.md` §4).
- Wszystkie porównania dat idą w **strefie czasowej łowiska**, nie aplikacji.
- `effective_*` i okno przedsprzedaży zależą od „dzisiaj", więc testy muszą **zamrażać czas**.
- Nazwy tabel, kolumn, klas i tras po angielsku; dokumentacja po polsku.
- Testy biegną na MySQL-u w schemacie `lowiska_test`.

## Rozstrzygnięcia

Ustalenia z wywiadu przy zakładaniu zadania i z dwóch rund przeglądu (2026-09-21). Wiążą
implementację tak samo jak decyzje z ADR-ów, ale nie mają zasięgu poza zadaniem.

1. **Warunki reguł liczone PER DOBA**, osobno dla każdej doby pobytu — potwierdzenie P2, zgodne
   z wyborem K3 z rozdziału 12 wymagań.
2. **G1 (snapshot oferty) nie wchodzi ani do 018, ani do 019** — dwa z pięciu jego składników
   powstają w 021, a transakcji i uczestników nie ma w schemacie; 018 wnosi wyłącznie strukturę
   rozbicia.
3. **Obniżka przedsprzedażowa liczona per doba**, z widoczną obniżką przy każdej dobie; podstawą
   kwota pobytu za tę dobę, bez usług dodatkowych. Świadomie przyjęte, że suma dób może różnić się
   o grosze od procentu policzonego od całości.
4. **Reguła cenowa ma własny okres obowiązywania** (`effective_from`/`effective_to`) obok warunku
   zakresu dat — pierwszy mierzony wobec chwili wyceny, drugi wobec wycenianej doby.
5. **Bramka udostępnienia stawek warunkowych nie powstaje w kodzie** — warunek z rozdziału 11
   spełnia harmonogram wdrożenia, bo 017–021 wdrażają się razem.
6. **Tier T2** mimo wyzwalacza „migracje", na podstawie decyzji pakietowej z §14.2.
7. **Powstaje warstwa oferty** jako jedyne wejście dla 019, koszyka i portalu — wycena
   i sprzedawalność zostają niezależne, ale składa je **jedno** miejsce, nie każdy pytający.
   Wymaga korekty ADR-013, `dostepnosc.md` §2 i docblocka `StaySellability`.
8. **Powód „brak ceny" nazywa warstwa oferty, nie wycena** — wycena zgłasza tylko, że nie umie
   wycenić doby, i dzięki temu nie zna słownika odmów sprzedaży.
9. **Odmowa „brak ceny" niesie dobę, rolę i obsadę**, dla których zabrakło stawki — ta sama zasada
   co w 017 (ADR-013, rozstrzygnięcie 18).
10. **Ani brak stawki, ani remis nie lecą wyjątkiem** — obie sytuacje wracają jako wynik, żeby jedna
    zła para reguł nie wywróciła całego widoku kalendarza (019).
11. **Kolumny warunku zakresu dat nazywają się `first_day_on`/`last_day_on`**, jak
    w `whole_term_periods` — `starts_on`/`ends_on` znaczą w tym projekcie co innego.
12. **Szczegółowość liczy się osiami** (dni tygodnia, zakres dat, liczba łowiących, rola); zakres
    z jednym otwartym końcem to jedna oś.
13. **Remis: osie niezależne od doby sprawdza się osobno, a dni tygodnia i zakres dat RAZEM** —
    kolizja wymaga istnienia doby w przecięciu zakresów, której dzień należy do przecięcia zbiorów
    dni. Sprawdzanie oś po osi odrzucałoby poprawne cenniki (np. „30.04–02.05" plus „piątki"
    w roku, w którym piątek w ten zakres nie wypada).
14. **Zakres sprawdzania dziury w cenniku**: doby od dziś do końca ostatniego okresu, obsady
    1…max(`max_anglers`), rola `companion` tylko gdy łowisko dopuszcza osoby towarzyszące, reguły
    obowiązujące dziś — z jawnym zastrzeżeniem w komunikacie.
15. **Stanowisko bez podanej pojemności liczy się jako 1 łowiący i zero towarzyszących**, a nie jest
    pomijane — `max_anglers` i `max_people` są `nullable` od 014, a pominięcie zostawiłoby łowisko
    bez sprawdzenia w ogóle.
16. **Obie granice domknięte** (`effective_to`, `last_day_on`), jak w 017.
17. **Obniżka: podstawą suma pozycji doby, jedno zaokrąglenie na dobę, połówki w górę.**
18. **Dopłata Łopienna dostaje `participant_role = angler`** — bez tego obciąża też osobę
    towarzyszącą. To konfiguracja, nie wyjątek w kodzie, i `MANUAL.md` ma o tym uprzedzać.
19. **Stawka osoby towarzyszącej ma najwyższy priorytet w cenniku.** Każda stawka warunkowa bez
    warunku roli pasuje też do towarzyszącej; przy równym priorytecie i równej szczegółowości daje
    to remis, czyli błąd zapisu. Podniesienie priorytetu rozwiązuje to raz dla całego cennika,
    zamiast dopisywania warunku „rola: łowiący" do każdej pozostałej stawki.
20. **Dodatkowe ostrzeżenie przy zapisie: stawka bez warunku roli z priorytetem WYŻSZYM niż stawka
    towarzyszącej.** Zasada z punktu 19 chroni tylko w jedną stronę — walidacja remisu łapie
    priorytet równy, a wyższy przepuszcza bez śladu, więc towarzysząca cicho zapłaciłaby pełną
    stawkę. Ostrzeżenie zgłasza się tylko przy warunkach spełnialnych jednocześnie i **nie jest
    błędem**, bo łowisko może świadomie chcieć obciążyć wszystkich.

21. **`effective_from`/`effective_to` mierzy się wobec DZISIEJSZEJ DATY w strefie łowiska**,
    liczonej wewnątrz wyceny (`/review-task`, 2026-09-22). Kolumny są typu `date`, a porównania
    i tak idą na datach, więc „chwila wyceny" i „dzień zakupu" zlewają się w jedną wartość.
    Rozróżnienie koszyk kontra zapłata minutę po północy wymaga **utrwalonej transakcji**, której
    nie ma w schemacie, i należy do snapshotu G1 — nie do tego zadania. Data wyceny **nie jest
    parametrem wywołania**: nikt jej dziś nie podaje, a dokładanie jej byłoby projektowaniem pod
    hipotetyczne wymaganie (`CLAUDE.md`). Testy zamrażają czas, jak w 017.
22. **Podstawą obniżki przedsprzedażowej jest suma pozycji doby: wygrana stawka `rate` PLUS
    wszystkie pasujące `surcharge`** (`/review-task`, 2026-09-22). Usługi dodatkowe (020) zostają
    poza podstawą. Uzasadnienie negatywne, warte zapamiętania: gdyby obniżka obejmowała samą stawkę,
    jej wysokość zależałaby od tego, czy operator wpisał kwotę jako stawkę, czy rozłożył ją na stawkę
    i dopłatę — **ta sama cena końcowa dawałaby dwie różne obniżki**.
23. **Niezmienniki cennika idą do NOWEGO pliku `docs/conventions/cennik.md`**, nie do sekcji
    w `dostepnosc.md` (`/review-task`, 2026-09-22). Inna oś pytania: `dostepnosc.md` odpowiada
    „czy wolno sprzedać", cennik — „ile to kosztuje". Dodatkowo `dostepnosc.md` ma po zadaniu 017
    **20,5 KB**, więc doklejenie cennika zbliżałoby go do miękkiego limitu ~40 KB z `CLAUDE.md`.
    Do `CLAUDE.md` dochodzi wiersz w tabeli routingu powierzchni — pozycja była już na liście
    „Zmian dokumentacji" i zostaje.
24. **Warunek `anglers_count` znaczy RÓWNOŚĆ — „dokładnie N łowiących"** (`/review-task`,
    2026-09-22), porównywaną z **faktyczną obsadą z zapytania**, nigdy z `positions.max_anglers`
    (ta kolumna to pojemność stanowiska i kusi wyłącznie nazwą). Zgodne z makietą M3 i z przypadkiem
    Łopienna, gdzie dopłata za wyłączność należy się przy dokładnie jednym łowiącym. Próg
    („co najmniej N") odpada, bo „co najmniej 1" znaczy „zawsze" i wymagałby dodatkowo górnej
    granicy, czyli drugiej kolumny; przedział `anglers_from`/`anglers_to` odpada, bo dokłada piątą
    oś do liczenia szczegółowości i remisów, a żadne z dwóch łowisk go nie potrzebuje.

<!-- /review-task: powyższe są już rozstrzygnięte. Dopisz tu wyłącznie nowe ustalenia punktowe
     wynikłe z przeglądu; decyzje o zasięgu poza zadaniem idą do ADR-ów. -->

## Powiązane ADR-y

⚠️ **Oba mają PUSTĄ sekcję Decyzja** — `/implement-task` zatrzyma się, dopóki autor ich nie wypełni.

- [ADR-014 — Cennik jako lista reguł z warunkami: rozstrzyganie i kształt nierozstrzygalności](../adr/ADR-014-cennik-jako-lista-regul-z-warunkami.md)
  — model cennika (lista reguł, nie tabela stawek po wymiarach), kolejność rozstrzygania
  („priorytet → szczegółowość osiami → błąd konfiguracji"), sumowanie dopłat oraz to, że remis
  i brak stawki wracają **wynikiem, nie wyjątkiem**.
- [ADR-015 — Warstwa oferty pobytu jako jedyne wejście dla pytających o sprzedaż](../adr/ADR-015-warstwa-oferty-pobytu.md)
  — jedno miejsce składające odpowiedzi z 017 i 018, kolejność „sprzedawalność przed ceną"
  i kształt odmowy. ⚠️ Ten ADR **narusza literę** ADR-013 (Decyzja A: `StaySellability` „jedynym
  wejściem dla cennika i kalendarza"), więc ADR-013 dostaje sekcję **Aktualizacja** z odsyłaczem;
  decyzja A zostaje w mocy, zmienia się wyłącznie lista wołających.

Wiążą też zadanie, choć powstały wcześniej:
[ADR-010](../adr/ADR-010-doba-wedkarska-jako-przedzial-czasu.md) (doba jako przedział),
[ADR-012](../adr/ADR-012-jedno-zrodlo-prawdy-o-dostepnosci.md) (jedno źródło prawdy o dostępności),
[ADR-013](../adr/ADR-013-warstwa-regul-pobytu-i-spoiwo-dob.md) (reguły pobytu i spoiwo dób).

## Otwarte pytania — rozstrzygnięte przez `/review-task` 2026-09-22

Żadne nie zostaje otwarte. Zapis dla historii przeglądu:

1. **Czy silnik rozstrzygania reguł cenowych zasługuje na ADR-a?** → **TAK**, powstał
   [ADR-014](../adr/ADR-014-cennik-jako-lista-regul-z-warunkami.md). Spełnione wszystkie trzy
   warunki kryterium z `CLAUDE.md`; przesądza **koszt odwrócenia** — model cennika jest schematem,
   a kolejność rozstrzygania wiąże każde miejsce pytające o cenę. Kontrargument („rozdział 11
   rozstrzygnął to biznesowo") odrzucony: wymagania mówią, ŻE stawki mają być warunkowe, a nie JAK
   silnik wybiera zwycięzcę — a decyzje „szczegółowość osiami, nie polami" i „dni tygodnia razem
   z zakresem dat" nie wynikają z wymagań ani z kodu.
1a. **Warstwa oferty — własny ADR?** → **TAK**, powstał
   [ADR-015](../adr/ADR-015-warstwa-oferty-pobytu.md), zgodnie z rekomendacją zadania. Przesądza to,
   że warstwa **narusza literę ADR-013** (zastępuje `StaySellability` jako wejście dla cennika,
   kalendarza i portalu) — a to jest więcej niż doprecyzowanie listy wołających, więc nie mieści się
   w samej „Aktualizacji". ADR-013 dostaje Aktualizację z odsyłaczem; jego Decyzja A zostaje w mocy.
   ⚠️ **Dwa ADR-y z jednego zadania to świadomy wybór, nie nadprodukcja.** Obie decyzje są
   rozdzielne: silnik cenowy stałby się listą reguł także wtedy, gdyby składał go każdy pytający,
   a warstwa oferty byłaby potrzebna przy dowolnym modelu cennika, który umie odmówić. Mają różne
   alternatywy, różny koszt odwrócenia i tylko jedna z nich rusza ADR-013 — zlanie ich w jeden plik
   dałoby ADR-a, którego tytuł nie opisuje przedmiotu.
2. **Wobec czego mierzy się `effective_*`?** → **rozstrzygnięcie 21** (dzisiejsza data w strefie
   łowiska, liczona wewnątrz wyceny; bez parametru). Semantyka jednego porównania — nie ADR.
3. **Podstawa obniżki przedsprzedażowej?** → **rozstrzygnięcie 22** (stawka plus dopłaty tej doby,
   bez usług dodatkowych). Anti-sygnał z `CLAUDE.md`: wartość progu i podstawa wyliczenia.
4. **`cennik.md` czy sekcja w `dostepnosc.md`?** → **rozstrzygnięcie 23** (nowy plik). Miejsce
   zapisu dokumentacji, nie decyzja architektoniczna.
5. **`anglers_count`: równość czy próg?** → **rozstrzygnięcie 24** (równość, porównywana z obsadą
   z zapytania). Semantyka jednej kolumny w tabeli, którą to zadanie samo tworzy; szerszy kontekst
   modelu cennika nosi ADR-014.
