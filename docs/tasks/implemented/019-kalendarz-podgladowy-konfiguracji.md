# 019 — Kalendarz podglądowy konfiguracji

## Opis problemu

Po zadaniach 015–018 operator może skonfigurować całą ofertę: dobę i sezony, stanowiska, blokady,
reguły pobytu i cennik. **Nie ma natomiast ani jednego miejsca, w którym zobaczyłby skutek tych
ustawień.** Formularze pokazują, co wpisał; nie pokazują, co z tego wynika.

Trzy mechanizmy zbudowane w 017 i 018 są z założenia **niewidoczne w formularzu**, a mimo to
przesądzają o sprzedaży:

1. **Zlewanie pakietów** — święto śr–pt nachodzące na weekend pt–sob daje jeden pakiet śr–sob
   (4 doby). Operator nie zobaczy tego w żadnym z dwóch formularzy, bo nigdzie tego nie wpisał
   ([ADR-013](../../adr/ADR-013-warstwa-regul-pobytu-i-spoiwo-dob.md) nazywa to skutkiem zamierzonym,
   ale niewidocznym).
2. **Przycinanie pakietów** — blokada soboty skraca weekend do samego piątku, więc reguła „weekend
   w całości" przestaje obowiązywać w tym tygodniu.
3. **Złożenie cen** — przy stawce i dopłacie warunkowej doba dla jednego łowiącego kosztuje
   110 zł, a wymagania mówią wprost, że **musi to być widoczne przed sprzedażą, a nie po niej**
   (K1a). To samo dotyczy dziur w cenniku (K2).

Zadanie realizuje mechanizm **G4** z
[Wymagań konfiguracji sprzedaży krótkoterminowej](../../project/etapy/00-konfiguracja-sprzedazy/WYMAGANIA-SPRZEDAZ-KROTKOTERMINOWA.md)
— „kalendarz podglądowy «co z tego wynika»: dla najbliższych N dni pokazuje, czy sprzedawalne, po
jakiej cenie, a jeśli nie — dlaczego". Wymagania nazywają go **najtańszym mechanizmem
antykonfliktowym** i **głównym narzędziem onboardingu concierge** (IDEA 10.1).

⚠️ **To jest zadanie weryfikacyjne dla 017 i 018**, nie kolejny moduł obok nich. Pierwsze realne
spojrzenie na to, czy spoiwo dób i silnik cen robią to, co miały robić, następuje dopiero tutaj —
dlatego idzie przed 020, mimo że G4 wymienia także usługi dodatkowe.

⚠️ **Zadanie zostało zestrojone z przedefiniowanym 018 (2026-09-22).** Tamto uprościło **model
cennika** — ze stawki wypadły priorytety, szczegółowość, remis jako błąd, oś dni tygodnia, oś roli
i drugi wymiar czasu; warunki zostały przy dopłacie. **Kontraktu, z którego korzysta kalendarz, to
nie ruszyło**: warstwa oferty (ADR-015), `shortestOffer()`, rozbicie wyceny, odmowa „brak ceny"
i obniżka przedsprzedażowa są tam wprost oznaczone jako **bez zmian**. Poprawki w tym pliku są więc
punktowe i dotyczą czterech miejsc: przykładu złożenia cen, wskazania przy „braku ceny", tabeli
powodów i wzmianek o remisie.

⚠️ **Przedefiniowane 018 nie jest jeszcze zaimplementowane** — w repozytorium stoi kod poprzedniego
modelu, przeznaczony do przebudowy. Implementacja 019 rusza po tamtej przebudowie, a nazwy klas
weryfikuje się wtedy w kodzie, nie w tym pliku.

Makieta: [`makieta-019-kalendarz-podgladowy.html`](../../project/mockups/makieta-019-kalendarz-podgladowy.html)
— umiejscowienie w sub-nawigacji, siatka stanowiska × doby z kontrolkami „ceny od", trzy stany
komórki wraz z kształtem podpowiedzi, **zlewanie i przycinanie pakietów** rozrysowane osobno,
złożenie cen na przykładzie Łopienna oraz zakres pomiaru kosztu.

## Wymagania

### Ekran — nowa pozycja sub-nawigacji „Kalendarz"

| Element | Kształt |
|---|---|
| strona | strona zasobu `FisheryResource` (`getPages()`), **tylko do odczytu**; osobna pozycja w `getRecordSubNavigation()`, **za „Cennik", przed „Stanowiska"** — trzy ekrany konfiguracji, a zaraz po nich ich skutek |
| siatka | **wiersz = stanowisko, kolumna = doba**, okno **kalendarzowe**: **miesiąc** (domyślnie) albo **tydzień**, przesuwane w granicach sezonu |
| wiersz „Pakiety" | **nad stanowiskami**: pakiety spoiwa na poziomie **łowiska**, rozciągnięte na obejmowane doby, z nazwą i liczbą dób („Boże Ciało + weekend · śr–sob · 4 doby") |
| wiersz stanowiska wycofanego | **jeden komunikat na całą szerokość** zamiast trzydziestu identycznych komórek — przyczyna jest niezależna od dat, więc powtarzanie jej trzydzieści razy niczego nie dodaje |
| kontrolki nad siatką | **sezon**, **okno** (miesiąc / tydzień), **przesuwanie okna** (poprzedni / następny) oraz **liczba łowiących**, **liczba osób towarzyszących** i **długość pobytu**; domyślnie **1 łowiący, 0 towarzyszących i najkrótszy kupowalny pobyt** — czyli widok „ceny od" |
| komórka — sprzedawalna | cena pobytu rozpoczynającego się tą dobą **wraz z liczbą dób**, której dotyczy |
| komórka — niesprzedawalna | powód odmowy, skrócony do etykiety; pełny powód w podpowiedzi wraz ze wskazaniem (zakres pakietu albo doba) |
| podpowiedź blokady | dodatkowo **ile stanowisk objęła blokada i jak je wybrano** (`selection_label` z 016), np. „obejmuje 6 stanowisk wybranych po cesze »pomost«". ⚠️ `selection_label` jest **nullable** (zbiór zaznaczony ręcznie go nie ma), więc potrzebny jest wariant bez opisu kryterium — samo „obejmuje 6 stanowisk", nigdy pusty nawias |
| komórka — doba w środku pakietu | **„zacznij 03.06"** — sama doba startu; **długość pakietu idzie do podpowiedzi**, bo komórka ma kilkanaście znaków szerokości |
| licznik nachodzących stawek | przy komórce, gdy do doby pasuje **więcej niż jedna** stawka — **także przy identycznych kwotach**; w podpowiedzi zwycięzca i kwoty przegranych |
| oznaczenie martwej stawki | **jedno na regule**, nie na dobie: stawka, która nie wygrywa nigdzie w swoim okresie |
| okruszki i autoryzacja | `FisheryNavigation::fisheryBreadcrumbs()`; dostęp przez `FisheryPolicy`, jawnie |

⚠️ **Siatka stanowiska × doby jest najdroższym z rozważanych wariantów i wybrana świadomie** —
bo **blokady wskazują ZBIORY stanowisk** (016), więc widok jednego stanowiska naraz ukrywa dokładnie
to, co najłatwiej przeoczyć przy konfiguracji: że blokada objęła piętnaście miejsc zamiast pięciu.
Okno kalendarzowe (miesiąc albo tydzień) ogranicza koszt; patrz „Pomiar kosztu" niżej.

⚠️ **Kwota bez liczby dób wprowadza w błąd.** W widoku „ceny od" sąsiadują komórki o różnej
długości — 130,00 zł za jedną dobę obok 520,00 zł za czterodobowy pakiet. Liczba dób jest więc
**częścią treści komórki**, nie ozdobnikiem.

⚠️ **Zasięg blokady musi być widoczny, bo to on uzasadnia ten wariant siatki.** Argumentem za
najdroższym widokiem jest „blokada objęła piętnaście miejsc zamiast pięciu" — ale operator zobaczy
to tylko wtedy, gdy podpowiedź **poda liczbę objętych stanowisk i sposób ich wyboru**. Bez tego
zostaje mu liczenie zaczernionych komórek wzrokiem, czyli dokładnie to, przed czym kalendarz ma
chronić. Dane są gotowe od 016: zbiór jest zmaterializowany, a `selection_label` mówi, jak powstał.

**Wiersz „Pakiety" oddziela regułę od jej skutku.** Pakiety spoiwa są własnością **łowiska** (weekend
i święta są wspólne dla wszystkich stanowisk), ale ich **przycinanie jest per stanowisko**, bo zależy
od blokad i stanu konkretnego miejsca. Wiersz nad siatką pokazuje więc regułę, a wiersze stanowisk —
to, co z niej zostało. ⚠️ Gdy oba się różnią, **prawdę o sprzedaży niesie wiersz stanowiska**, nie
wiersz „Pakiety"; podpowiedź przy przyciętym pakiecie ma to mówić wprost.

⚠️ **Ten wiersz NIE MA DZIŚ SKĄD WZIĄĆ DANYCH — i to jest zależność poza tym zadaniem.**
Jedynym wejściem do pakietów jest `StaySellability::bundleAround()`, a ta klasa bierze `Position`
w konstruktorze i buduje instancje **wyłącznie z dób sprzedawalnych na tym stanowisku** (reguła 5
z 017). Pakietu **nieprzyciętego** nie zwraca nic. Z trzech dróg dwie są złe:

| Droga | Dlaczego odpada |
|---|---|
| wziąć dowolne stanowisko jako wzorzec | fałszuje wynik **dokładnie wtedy**, gdy to stanowisko ma blokadę — czyli w jedynym przypadku, dla którego ten wiersz istnieje |
| złożyć pakiety w widoku z `weekend_days` i świąt | odtworzenie zlewania i jego przechodniości w kalendarzu, czyli drugie źródło prawdy — wprost przeciw ADR-013 |
| **wystawić pakiety łowiska dla okna z warstwy oferty** | jedyna spójna z ADR-013, a przy tym tania: składa się z danych, które 017 już zna |

**Potrzebna jest trzecia droga**, czyli **trzecia odpowiedź warstwy oferty**: „pakiety spoiwa
obowiązujące w tym oknie, przed przycięciem". ⚠️ Wymaganie **wykracza poza to zadanie** — należy
do 018.

⚠️ **Stan na dziś: 018 tego NIE wystawia.** Przy przedefiniowaniu tamtego zadania dołożono
diagnostykę cennika (komplet kandydatów i martwe stawki — patrz niżej), ale **pakietów łowiska nie**.
Nie wiadomo, czy to świadome zawężenie, czy przeoczenie przy okazji — do rozstrzygnięcia przez autora
przed wdrożeniem. Dopóki tej odpowiedzi nie ma, **wiersz „Pakiety" nie powstaje**, a siatka działa
bez niego: wiersze stanowisk niosą pełną prawdę same z siebie.

### Kotwica widoku — sezon, nie „dziś"

⚠️ Okno „najbliższe 30 dni od dziś" **nie pokrywa głównego przypadku użycia**. Kalendarz służy do
sprawdzenia **dopiero co wprowadzonej konfiguracji**, a ta zwykle dotyczy przyszłego sezonu:
Klasztorne ustawia sezon 2027 w listopadzie 2026, więc widok „od dziś" pokazałby same odmowy „poza
sezonem", czyli nic. Dotyczy to tak samo onboardingu concierge, dla którego G4 powstało.

Dlatego nad siatką stoi **lista rozwijana sezonów** — okresów sprzedaży **trwających i przyszłych** —
z akcją „przejdź do", która przestawia okno na początek wybranego sezonu.

- **Kotwica** to dziś, jeśli dzisiejsza data mieści się w którymś okresie sprzedaży; w przeciwnym
  razie **początek najbliższego przyszłego okresu**.
- Okresy **zakończone** nie trafiają na listę — przeszłości nie sprzedajemy, więc nie ma czego
  weryfikować.
- Łowisko bez ani jednego okresu sprzedaży nie ma kotwicy — patrz „Stany puste".

**Okno jest jednostką KALENDARZOWĄ, nie liczbą dób.** Pokazujemy **miesiąc albo tydzień**, a nie
„trzydzieści dni od kotwicy" — kolumny wypadają wtedy tam, gdzie operator ich szuka, i da się
powiedzieć „czerwiec", zamiast „1–30.06".

- **Okno domyślne: miesiąc, w którym zaczyna się wybrany okres sprzedaży.** Przy zmianie sezonu widok
  przeskakuje na miesiąc zawierający jego początek, a nie na sam dzień startu.
- **Druga i jedyna alternatywa: tydzień**, z tą samą zasadą — pierwszy tydzień zawierający początek
  pokazywanego okresu.
- **Dłuższych okien nie oferujemy w ogóle**, bo koszt rośnie z iloczynem dób i stanowisk, a dopiero
  go mierzymy. Dwie wartości zamiast listy czterech to świadome zamknięcie najgorszego przypadku,
  a nie ostrożność na wyrost.

**Kotwica rozwiązuje wejście do sezonu, nie poruszanie się po nim.** Sezony obu łowisk trwają
dziesięć miesięcy, więc bez przesuwania operator widziałby pierwszy miesiąc i nie miał jak zapytać
o sierpień. Dlatego nad siatką stoi **przesuwanie okna** — „poprzedni / następny" miesiąc albo
tydzień, **w granicach wybranego sezonu**; na jego krańcach odpowiedni kierunek jest niedostępny.

⚠️ **Pomiar wykonuje się przy oknie miesięcznym**, czyli najszerszym, jakie oferujemy: dla
Klasztornego to około **806 komórek** (26 stanowisk × 31 dób), a nie tydzień, który daje ich 182.
Jeśli miesiąc okaże się nie do udźwignięcia, zostaje tydzień — a decyzja o buforze nadal wymaga
własnego uzasadnienia pomiarowego.

Skutek uboczny wart odnotowania: przy oknie miesięcznym **widać, gdzie tnie horyzont sprzedaży** —
dziś nie widać tego nigdzie w panelu.


### Co pokazuje komórka — i skąd bierze odpowiedź

**Kalendarz woła WYŁĄCZNIE warstwę oferty z zadania 018.** Nie pyta osobno o sprzedawalność
i osobno o cenę, nie woła `StaySellability` ani wyceny wprost. Powód jest ten sam, dla którego
warstwa oferty powstała: dwóch dostawców odpowiedzi nie może mieć dwóch miejsc składania
(ADR-012, ADR-013 i decyzja z 018).

Komórka niesie jeden z trzech stanów:

1. **Sprzedawalna** — kwota za pobyt o zadanej długości, rozpoczynający się tą dobą.
2. **Niesprzedawalna** — powód z `SaleUnavailabilityReason`: sześć wartości z 017 (pobyt za krótki,
   za długi, przerwany weekend, przerwane święto, poza horyzontem, poniżej minimum przedsprzedaży),
   wartości dobowe z 015/016 (stanowisko wycofane, poza sezonem, blokada) oraz „brak ceny" z 018.
   Odmowa niesie **wskazanie** — dobę albo pełny zakres pakietu — i to wskazanie ma trafić do
   podpowiedzi, bo bez niego wędkarz ani operator nie wie, ile dobrać.
   ⚠️ **„Brak ceny" wskazuje DOBĘ, i tylko dobę.** Po przedefiniowaniu 018 dopasowanie stawki zależy
   **wyłącznie od daty** — obsada i rola zeszły ze stawki na dopłatę, a dopłaty dziur nie tworzą, bo
   tylko dodają. Nie ma też przyczyny „remis nierozstrzygalny": wycena nie ma już jak nie umieć
   wybrać stawki, bo przy nachodzeniu wygrywa tańsza.
   ⚠️ **Powody „brak ceny" są DWA i prowadzą w przeciwne strony** (018): `NoPriceDefined` znaczy, że
   do tej doby nie pasuje **żadna** stawka — operator musi dopisać regułę; `NoCompanionPrice` znaczy,
   że stawka jest, ale **nie ma ceny dla osoby towarzyszącej** — operator poprawia jedno pole
   w regule, która już istnieje. Komórka rozróżnia je etykietą, podpowiedź pełnym zdaniem.
   ⚠️ Skutek widoczny w kontrolkach: **dołożenie jednej osoby towarzyszącej potrafi przestawić całą
   siatkę w odmowy** `NoCompanionPrice`. To nie jest usterka, tylko najszybsza diagnoza, jaką ten
   ekran daje — operator widzi w jednym ruchu, że cennik nie przewiduje towarzyszenia.
3. **Nie można tu zacząć** — doba leży w środku pakietu, więc żaden pobyt rozpoczynający się nią
   nie obejmie pakietu w całości. Komórka wskazuje pierwszą dobę pakietu.

### Najkrótszy kupowalny pobyt PYTA warstwę oferty, nie liczy sam

Domyślny widok „ceny od" potrzebuje dla każdej doby **najkrótszego pobytu, który wolno kupić** —
i to jest **odpowiedź warstwy oferty**, a nie wyliczenie kalendarza.

⚠️ **Kalendarz nie ma prawa tego odtwarzać.** Wartość składa się z pakietu, `min_nights`, zwolnienia
świątecznego, minimum przedsprzedaży i `max_nights`, czyli **z reguł należących do 017**. Odtworzenie
ich w widoku złamałoby [ADR-013](../../adr/ADR-013-warstwa-regul-pobytu-i-spoiwo-dob.md) i zrobiło
z kalendarza drugie źródło prawdy — a wołanie `StaySellability` wprost łamałoby zasadę „jedno
wejście", którą 018 ustanowił. Alternatywa „próbuj kolejnych długości, aż warstwa powie tak" odpada
z innego powodu: przy siatce stanowiska × doby to dziesiątki tysięcy wywołań.

**Odpowiedź daje warstwa oferty** (018), w kształcie, który przetrwał przedefiniowanie tamtego
zadania:
`StayOffer::shortestOffer($startsOn, $anglers, $companions)` zwraca `ShortestStayVerdict` w jednej
z trzech postaci — `found(nights, breakdown)`, `startsEarlier(reason, bundleFirstDay)` albo
`none(reason)`. Kalendarz **woła ją raz na komórkę** i mapuje te trzy postacie wprost na trzy stany
komórki opisane wyżej.

⚠️ **Sama warstwa oferty szuka długości iteracyjnie** — czyta zakres pakietu z odmowy i skacze od
razu na długość, która go pokrywa, zamiast dochodzić po jednej dobie, a całość ma twardy limit prób.
To jest jej sprawa, nie kalendarza: **komórka kosztuje jedno wywołanie `shortestOffer()`, ale
kilka wywołań `offer()` pod spodem** — i właśnie dlatego pomiar niżej mierzy render, a nie pojedyncze
zapytanie.

### Pomiar kosztu — element zakresu, nie „zobaczymy potem"

[ADR-013](../../adr/ADR-013-warstwa-regul-pobytu-i-spoiwo-dob.md) zostawił jawnie nierozstrzygnięty
koszt liczenia instancji pakietów w chwili pytania, „gdy kalendarz z zadania 019 zapyta o iloczyn
dób i stanowisk". To zadanie ten koszt **mierzy i zapisuje w treści zadania**: czas renderowania
siatki dla łowiska o realnej wielkości przy **oknie miesięcznym** (Klasztorne: ~26 stanowisk × 31 dób
= ok. 806 komórek) oraz liczba zapytań do bazy.

⚠️ **Buforowanie werdyktu jest zakazane** przez [`dostepnosc.md`](../../conventions/dostepnosc.md) §2
bez własnego uzasadnienia **pomiarowego**. Jeśli pomiar wypadnie źle, bufor jest **osobną decyzją
z liczbami**, a nie odruchem przy implementacji. Tanie środki, które wolno zastosować od razu i które
buforem nie są: jedna instancja `FishingDayCalendar` i jedna `PositionAvailability` na stanowisko,
wczytanie blokad i reguł raz na cały render, `with()` na relacjach.

#### Wynik pomiaru (2026-09-23)

Łowisko o wielkości Klasztornego — **26 stanowisk, okno miesięczne, 806 komórek**, cennik ze
stawką i dopłatą warunkową, jedno święto i jedna blokada trzydobowa:

| Miara | Wynik |
|---|---|
| komórki | **806** |
| zapytania do bazy | **191** |
| czas renderu siatki | **≈ 876 ms** |

**Wniosek: okno miesięczne udźwignie, bufor NIE powstaje.** Liczba zapytań rośnie z liczbą
stanowisk (po kilka na stanowisko: blokady, okresy, święta, cennik), a **nie** z liczbą komórek —
806 komórek kosztuje 191 zapytań, a nie 806. Diagnostyka cennika pobierana raz na okno działa
zgodnie z założeniem.

⚠️ Pomiar wykonano w kontenerze testowym, na MySQL-u ze świeżą bazą i bez współbieżności —
traktuj go jako **rząd wielkości**, nie jako budżet produkcyjny. Gdyby po wdrożeniu okazał się
nieadekwatny, bufor nadal wymaga **własnego** uzasadnienia pomiarowego (`dostepnosc.md` §2).

### Nachodzące stawki — po 018 kalendarz jest JEDYNYM miejscem, gdzie to widać

⚠️ **Przedefiniowanie 018 przeniosło ciężar wykrywania na ten ekran.** Wcześniej dwie stawki pasujące
do tej samej doby przy równym priorytecie były **błędem zapisu**; po uproszczeniu rozstrzygają się
cicho — wygrywa tańsza — i nic o nich nie mówi. Kalendarz przestaje więc być wyłącznie narzędziem
do oglądania **skutków** konfiguracji, a staje się także jedynym narzędziem do wyłapywania **bałaganu
w niej**.

⚠️ **Dwie stawki na tę samą dobę są pomyłką ZAWSZE**, także wtedy, gdy mają identyczną kwotę i nic
nie zmieniają w cenie. Dlatego licznik pokazuje się przy **każdym** nachodzeniu, a nie dopiero przy
rozbieżności kwot — inaczej najczystszy przypadek bałaganu (dwie identyczne reguły) pozostałby
niewidoczny.

Trzy rzeczy, w kolejności rosnącej wartości dla operatora:

| Co | Gdzie | Po co |
|---|---|---|
| **Ile stawek pasuje do tej doby** | licznik przy komórce, gdy pasuje **więcej niż jedna** | sam fakt nachodzenia jest informacją, niezależnie od kwot |
| **Która wygrała i po ile są pozostałe** | podpowiedź komórki | zamyka pytanie „czemu widzę 70, skoro wpisałem 90" |
| **Która stawka nie wygrywa NIGDZIE w swoim okresie** | **jedno oznaczenie na regule**, nie na każdej dobie | droższa reguła w całości przesłonięta tańszą jest martwym wpisem — operator myśli, że coś ustawił |

⚠️ **Trzecia pozycja to analiza zbioru reguł, nie renderu siatki.** Liczy się ją raz na cennik,
porównując przedziały dat i kwoty — po uproszczeniu 018 stawka ma już tylko zakres dat, więc jest to
arytmetyka przedziałów, niezależna od stanowisk i od okna. Nie liczymy jej per doba i nie
rozciągamy na całą siatkę.

⚠️ **Danych dostarcza 018, kalendarz ich nie liczy.** Kalendarz **nie ma prawa dopasowywać stawek
sam**: po uproszczeniu jest to wprawdzie samo porównanie dat, ale nadal logika cennika, a ta ma jeden
dom (ADR-014, `cennik.md`). Dlatego 018 wystawia **diagnostykę cennika** — decyzja z 2026-09-22:

| Czego potrzebuje kalendarz | Co wystawia 018 |
|---|---|
| licznik i kwoty przegranych | wynik `PriceRuleResolver` niesie **komplet kandydatów** na dobę, nie sam zwycięzca |
| oznaczenie martwej stawki | `PricingConfigurationAudit` wskazuje stawki niewygrywające w żadnej dobie swojego okresu |

⚠️ **Diagnostykę pobiera się raz na łowisko i okno, nie raz na komórkę.** Kandydaci zależą wyłącznie
od daty — stawka po uproszczeniu nie zna ani stanowiska, ani składu uczestników — więc to trzydzieści
zapytań, a nie trzydzieści razy liczba stanowisk. Przy Klasztornym różnica wynosi 30 kontra 780
i ma znaczenie dla pomiaru kosztu niżej.

Uwaga na przyszłość, poza zakresem: docelowo oznaczenie martwej stawki przydałoby się także **na
ekranie „Cennik"**, przy samej regule. 018 tego nie robi świadomie — wiedza o nachodzeniu ma na razie
jedno miejsce, tutaj.

### Stany puste — kalendarz jest narzędziem onboardingu, więc mówi, czego brakuje

Łowisko bez godzin doby, bez okresu sprzedaży albo bez stanowisk dałoby siatkę **samych odmów**.
Trzydzieści kolumn „poza sezonem" nie jest odpowiedzią dla kogoś, kto dopiero konfiguruje obiekt —
a to jest główny scenariusz tego ekranu (IDEA 10.1). Zamiast siatki pokazuje się wtedy **czego
brakuje i dokąd pójść**:

| Czego brakuje | Komunikat kieruje do |
|---|---|
| godzin doby (`day_start_time`/`day_end_time`) | „Sprzedaż i sezony" — bez doby nie ma czego liczyć |
| ani jednego okresu sprzedaży | „Sprzedaż i sezony" — brak okresu jest odmową, nie sprzedażą bez ograniczeń |
| ani jednego stanowiska | „Stanowiska" |
| ani jednej reguły stawki | „Cennik" — siatka renderuje się, ale z samym „brak ceny", więc komunikat stoi **nad** nią |

⚠️ Trzy pierwsze przypadki **zastępują** siatkę; czwarty ją **poprzedza**, bo dziura w cenniku bywa
częściowa i wtedy widać, których dób dotyczy.

⚠️ **Czwarty przypadek to zwykłe sprawdzenie istnienia** („czy łowisko ma choć jedną regułę stawki"),
a **nie druga implementacja wykrywania dziur**. Pełna analiza — iteracja po dobach okresów sprzedaży
od dziś do końca ostatniego okresu — mieszka w walidacji zadania 018 i ma tam zostać.

### Czego kalendarz nie robi

Jest **wyłącznie podglądem**. Nie ma z niego edycji ani skrótów „napraw to" — kliknięcie prowadzi co
najwyżej do właściwego ekranu konfiguracji. Nie zapisuje niczego do bazy; zadanie **nie dokłada ani
jednej migracji**.

### Tłumaczenia

Etykieta pozycji w sub-nawigacji, tytuł strony, etykiety czterech kontrolek (sezon, łowiący, osoby
towarzyszące, długość pobytu) wraz z opcją „najkrótszy możliwy", **skrócone etykiety powodów odmowy**
widoczne w komórce, pełne komunikaty w podpowiedzi, komunikat **„nie można tu zacząć"** wraz
ze wskazaniem pierwszej doby pakietu oraz cztery komunikaty stanów pustych — wszystko
w `lang/pl.json`.

⚠️ **Dwa powody „brak ceny" mają dwie różne etykiety** — `NoPriceDefined` („brak stawki")
i `NoCompanionPrice` („brak ceny dla towarzyszącej"). Sklejenie ich w jeden tekst kasuje całą wartość
rozróżnienia wprowadzonego w 018.

⚠️ Skrócona etykieta w komórce i pełny komunikat w podpowiedzi to **dwa różne teksty dla tego samego
powodu** — komórka ma kilkanaście znaków szerokości, a `SaleUnavailabilityReason::label()` zwraca
zdanie. Nie próbuj zmieścić jednego w drugim.

## Stan po implementacji (2026-09-23)

Zrealizowane w całości poza trzema rzeczami, wszystkie odnotowane świadomie:

1. ⚠️ **Motyw panelu NIE został zarejestrowany, a widok siatki używa stylów wpisanych wprost.**
   [ADR-016](../../adr/ADR-016-wlasny-motyw-panelu.md) zakłada w opcji A, że rejestracja `viteTheme()`
   jest zmianą **pustą wizualnie**. W tym repozytorium to nieprawda: aplikacja kompiluje CSS
   **Tailwindem 3** przez PostCSS (`tailwind.config.js`, `postcss.config.js`, `@tailwind base`
   w `app.css`), a motyw Filamenta 5 wymaga **Tailwinda 4** (`@import 'tailwindcss' source(none)`).
   Rejestracja motywu oznacza więc migrację całego potoku zasobów, razem ze stylami **strony
   publicznej** — czyli zmianę wizualną, której nie da się zweryfikować bez przeglądu w
   przeglądarce. Próba wykonana i **wycofana w całości**; `public/build` przywrócony do stanu z gita.
   **Do rozstrzygnięcia przez autora: osobne zadanie na migrację Tailwinda.** Po nim widok siatki
   przepisuje się z `style="…"` na klasy — dane i treść się nie zmieniają.
2. **Wiersz „Pakiety" nie powstał** — zgodnie z zapisem w wymaganiach: warstwa oferty nie wystawia
   pakietów łowiska przed przycięciem, a żadna z dwóch pozostałych dróg nie jest zgodna z ADR-013.
   Siatka działa bez niego, bo wiersze stanowisk niosą pełną prawdę same z siebie.
3. **Otwarte pytanie 2** (komórka przy minimum przekraczającym `max_nights`) zamyka się samo:
   taka doba trafia w zwykłą ścieżkę odmowy i pokazuje powód z `SaleUnavailabilityReason`, jak
   każda inna. Osobny komunikat nie powstał, bo nie ma dla niego osobnego powodu.

## Kryteria akceptacji

- [ ] Nowa pozycja „Kalendarz" stoi w sub-nawigacji łowiska za „Cennikiem" i **otwiera się w obu
      panelach** — test sprawdza to jawnie, bo dopisanie pozycji do `getPages()` dotyka klasy
      współdzielonej przez oba panele.
- [ ] Siatka pokazuje **wiersz na stanowisko** i **okno kalendarzowe** — domyślnie **miesiąc,
      w którym zaczyna się wybrany okres sprzedaży** — w strefie czasowej łowiska; czas w testach
      jest zamrożony. ⚠️ Wyjątkiem jest **stanowisko wycofane**, które zajmuje wiersz jednym
      komunikatem na całą szerokość — test ma to sprawdzać jawnie, żeby nie stanął w sprzeczności
      z regułą „wiersz na stanowisko".
- [ ] **Okno przyjmuje wyłącznie dwie wartości: miesiąc i tydzień.** Przełączenie na tydzień pokazuje
      **pierwszy tydzień zawierający początek pokazywanego okresu**; zmiana sezonu przy obu
      ustawieniach przeskakuje na jednostkę zawierającą **początek nowego okresu**, a nie na sam dzień
      startu.
- [ ] **Przesuwanie** („poprzedni / następny") przenosi okno o pełną jednostkę i **nie wychodzi poza
      wybrany sezon** — na jego krańcach odpowiedni kierunek jest niedostępny.
- [ ] Miesiące o różnej długości renderują się poprawnie: luty daje 28 kolumn, a lipiec 31.
- [ ] **Wiersz „Pakiety"** nad siatką pokazuje pakiety łowiska z nazwą i liczbą dób, rozciągnięte na
      obejmowane doby. ⚠️ Kryterium obowiązuje **tylko wtedy, gdy warstwa oferty wystawi pakiety
      łowiska** (zależność opisana w wymaganiach); bez tej odpowiedzi wiersz nie powstaje, a pozostałe
      kryteria obowiązują bez zmian.
- [ ] **Gdy pakiet jest przycięty na konkretnym stanowisku, wiersz stanowiska wygrywa z wierszem
      „Pakiety"**: przy blokadzie soboty nagłówek nadal pokazuje weekend dwudobowy, a wiersz tego
      stanowiska — pobyt jednodobowy w piątek, z podpowiedzią mówiącą o przycięciu.
- [ ] **Kotwica domyślna**: dziś, gdy dzisiejsza data mieści się w okresie sprzedaży; w przeciwnym
      razie początek najbliższego przyszłego okresu. Łowisko z sezonem zaczynającym się za pół roku
      pokazuje **ten sezon**, a nie trzydzieści odmów „poza sezonem".
- [ ] **Lista sezonów** zawiera okresy trwające i przyszłe, nie zawiera zakończonych, a „przejdź do"
      przestawia okno na początek wybranego.
- [ ] **Komórka pokazuje liczbę dób obok kwoty** — bez tego 130,00 zł za dobę i 520,00 zł za pakiet
      czterodobowy wyglądają jak ceny tego samego.
- [ ] Kontrolki zmieniają widok: ustawienie długości pobytu na 3 doby przelicza całą siatkę dla
      pobytów trzydobowych, a dołożenie osoby towarzyszącej zmienia kwoty zgodnie z cennikiem.
- [ ] Domyślny widok to **„ceny od"**: 1 łowiący, 0 towarzyszących, najkrótszy kupowalny pobyt.
- [ ] **Zlewanie widać**: święto śr–pt przy weekendzie `{5, 6}` daje w kalendarzu pakiet śr–sob —
      doby czw, pt i sob są oznaczone jako „nie można tu zacząć", a środa pokazuje pobyt czterodobowy.
- [ ] **Przycinanie widać**: blokada soboty sprawia, że piątek tego tygodnia sprzedaje się jako pobyt
      jednodobowy, mimo reguły „weekend w całości".
- [ ] **Złożenie cen widać**: w Łopiennie (stawka 70 zł, dopłata za wyłączność 20 zł przy obsadzie 1
      w dniach czw–nd) komórka czwartkowa dla jednego łowiącego pokazuje **90 zł**, a środowa 70 zł —
      operator widzi skutek dopłaty bez otwierania cennika (K1a).
- [ ] **Dopłata naliczana per osoba jest widoczna w kwocie**: ta sama doba dla jednego łowiącego
      i jednej osoby towarzyszącej pokazuje **90 zł**, bo dopłata Łopienna ma `applies_to = angler`,
      a stawka towarzyszącej wynosi 0 zł.
- [ ] **Dziura w cenniku widać**: doba w otwartym sezonie bez pasującej stawki jest niesprzedawalna
      z powodem „brak ceny", a nie pokazuje 0 zł (K2). Wskazanie niesie **samą dobę** — po
      przedefiniowaniu 018 dopasowanie stawki zależy wyłącznie od daty.
- [ ] **Nachodzenie stawek widać, nawet gdy nie zmienia ceny**: dwie stawki o **identycznej kwocie**
      pasujące do tej samej doby dają licznik przy komórce; podpowiedź podaje zwycięzcę i kwoty
      pozostałych. Jedna pasująca stawka licznika **nie** pokazuje.
- [ ] **Martwa stawka jest oznaczona raz, na regule**: droższa stawka w całości przesłonięta tańszą
      w swoim zakresie dat dostaje jedno oznaczenie, a nie oznaczenie na każdej dobie.
- [ ] **Diagnostyka pobierana jest raz na okno**: render siatki dla 26 stanowisk i 31 dób wykonuje
      **31** zapytań o kandydatów, a nie 806 — kandydaci zależą wyłącznie od daty.
- [ ] **Dwa powody „brak ceny" są rozróżnione**: doba bez żadnej pasującej stawki daje
      `NoPriceDefined`, a doba ze stawką bez ceny dla osoby towarzyszącej — `NoCompanionPrice`,
      z inną etykietą w komórce i innym zdaniem w podpowiedzi. ⚠️ Dołożenie jednej osoby
      towarzyszącej na łowisku bez takiej ceny przestawia **całą siatkę** w `NoCompanionPrice` —
      to zachowanie poprawne i ma je potwierdzać test, żeby nikt go później nie „naprawił".
- [ ] Odmowa pokazuje **wskazanie**: przy przerwanym pakiecie podpowiedź podaje pełny zakres, który
      trzeba objąć; przy odmowie z poziomu doby — której doby dotyczy.
- [ ] **Podpowiedź blokady podaje zasięg**: liczbę objętych stanowisk i sposób ich wyboru
      (`selection_label`). Blokada założona na grupę „Brzeg wschodni" pokazuje „15 stanowisk", a nie
      samo „sprzedaż zablokowana" — bez tego najdroższy wariant siatki nie realizuje argumentu,
      którym został uzasadniony. Blokada z **ręcznym** zaznaczeniem (`selection_label = null`)
      pokazuje samą liczbę, bez pustego nawiasu.
- [ ] Kalendarz woła **wyłącznie warstwę oferty** — nie ma w nim wywołania `StaySellability`,
      wyceny ani `PositionAvailability` wprost.
- [ ] Najkrótszy kupowalny pobyt **pochodzi z `StayOffer::shortestOffer()`**, a nie z wyliczenia
      w widoku: kalendarz woła ją **raz na komórkę**, nie próbuje długości po swojemu i nie zawiera
      reguł z 017. ⚠️ Liczba wywołań `offer()` pod spodem jest sprawą warstwy oferty — kryterium
      dotyczy tego, czego **nie robi kalendarz**.
- [ ] **Zwolnienie świąteczne widać w „cenach od"**: przy `min_nights = 5` i trzydobowym święcie
      komórka pierwszej doby święta pokazuje pobyt **trzydobowy**, a nie pięciodobowy ani odmowę.
- [ ] **Stany puste zastępują siatkę komunikatem**: brak godzin doby, brak okresu sprzedaży i brak
      stanowisk kierują do właściwego ekranu zamiast rysować trzydzieści kolumn odmów; brak stawek
      pokazuje komunikat **nad** siatką, bo dziura bywa częściowa.
- [ ] **Pomiar kosztu wykonany i zapisany w zadaniu**: czas renderu i liczba zapytań dla łowiska
      o realnej wielkości, **przy oknie miesięcznym** — czyli najszerszym, jakie oferujemy
      (Klasztorne: ok. 806 komórek przy 26 stanowiskach i 31 dobach, wobec 182 przy tygodniu).
      Bufor nie powstaje; jeśli miesiąc nie udźwignie, zostaje tydzień.
- [ ] Właściciel nie widzi kalendarza cudzego łowiska; administrator widzi go w ramach wglądu
      onboardingowego.
- [ ] Zadanie **nie dokłada migracji**, polityki ani uprawnień Shielda; `app/Policies/` bez zmian.
- [ ] Zielony zakres T1 zadeklarowany niżej.
- [ ] **Pełny pakiet testów ODROCZONY** do wspólnego przeglądu po pakiecie 017–021 — odroczony,
      nie pominięty.

## Zakres testów

- **Tier:** T1
- **Uruchamiamy:** `docker compose exec app php artisan test --filter="SaleCalendarTest|SaleCalendarPageTest"`
- **Uzasadnienie:** zadanie **nie trafia w żaden wyzwalacz T3** z `CLAUDE.md` — nie ma migracji, nie
  rusza `User`, polityk ani Shielda, nie dotyka providerów paneli, `phpunit.xml` ani
  `tests/TestCase.php`. Kalendarz jest **liściem**: konsumuje warstwę oferty i niczego nie wystawia
  dalej. T1 wynika więc ze **zwykłej reguły** doboru tieru, a nie z odstępstwa pakietowego z §14.2.
  ⚠️ Nazwy obu klas są **propozycją** — jeśli implementacja nazwie je inaczej, poprawia filtr razem
  z nimi.
  ⚠️ **Jedno zastrzeżenie do tezy „nie zmienia kontraktu":** dopisanie pozycji do
  `FisheryResource::getPages()` i `getRecordSubNavigation()` dotyka klasy **współdzielonej przez oba
  panele**. Zachowania to nie zmienia, ale zamiast zakładać, `SaleCalendarPageTest` **sprawdza
  otwarcie strony w obu panelach** — wtedy teza jest udowodniona tam, gdzie i tak piszemy test,
  bez podnoszenia tieru.
  ⚠️ **Czego świadomie nie uruchamiamy:** `AdminPanelTest`, `OwnerPanelTest` ani testów warstw
  niższych — cały pakiet przechodzi wspólny przegląd po 017–021.

## Zakres wyłączeń

- **Usługi dodatkowe w kalendarzu** — zadanie 020. G4 wymienia M6, ale usługi powstają dopiero tam;
  **rozszerzenie kalendarza o usługi wchodzi w zakres 020** i jest tam zapisane, żeby nie zginęło.
- **Edycja z poziomu kalendarza** — ekran jest podglądem. Żadnych akcji zmieniających konfigurację,
  najwyżej odnośnik do właściwego formularza.
- **Buforowanie werdyktu** — zakazane bez uzasadnienia pomiarowego (`dostepnosc.md` §2). Jeśli pomiar
  je uzasadni, jest to osobna decyzja z własnymi liczbami.
- **Portal wędkarza** — kalendarz jest narzędziem operatora i concierge'a, nie widokiem sprzedażowym.
- **Rezerwacje, koszyk, snapshot oferty (G1)** — poza iteracją, tak jak w 017 i 018.
- **Bramka udostępnienia stawek warunkowych** — nie powstaje; warunek z rozdziału 11 wymagań spełnia
  harmonogram wdrożenia (017–021 razem), co rozstrzygnęło zadanie 018.
- **Kalendarz dla wielu łowisk naraz** — widok należy do jednego łowiska, jak wszystkie ekrany
  sub-nawigacji.

## Zmiany dokumentacji

- [ ] `docs/conventions/panel-wlasciciela.md` — §6: ekran **podglądowy** obok stron ustawień; jego
      miejsce w kolejności sub-nawigacji oraz zasada, że podgląd **woła warstwę oferty**, a nie
      warstwy niższe
- [ ] `docs/project/etapy/00-konfiguracja-sprzedazy/WYMAGANIA-SPRZEDAZ-KROTKOTERMINOWA.md` — trzy poprawki: §14.1 wiersz **019**
      (skreślić „bramka udostępnienia stawek warunkowych"; zawęzić „G4 dla M2–M4, M6" do **M2–M4**,
      z adnotacją, że usługi dokłada 020); §14.1 wiersz **020** — dopisać rozszerzenie kalendarza
      o usługi; §14.2 — **punkty kontrolne B i C zastąpione jednym wspólnym przeglądem po pakiecie
      017–021**
- [ ] `lang/pl.json` — etykieta sub-nawigacji i tytuł strony, etykiety czterech kontrolek, **skrócone
      etykiety powodów odmowy** do komórki (osobne od pełnych zdań z `SaleUnavailabilityReason::label()`),
      komunikat „nie można tu zacząć" oraz cztery komunikaty stanów pustych
- [ ] `MANUAL.md` — nowa sekcja „Kalendarz": do czego służy, jak czytać komórki, co znaczy „nie można
      tu zacząć" i dlaczego cena bywa inna niż w cenniku (dopłaty, obniżka przedsprzedażowa); pozycja
      w liście „Kolejność wprowadzania danych" (§2) za „Cennikiem", z uwagą, że to **krok
      sprawdzający**, a nie kolejny formularz do wypełnienia
- [ ] `docs/tasks/020-uslugi-dodatkowe-jednostka-i-limit.md` i
      `docs/tasks/021-polityka-zwrotu-i-regulamin-wersjonowany.md` — **poprawione przy zakładaniu
      tego zadania**: oba szkielety mówiły o punkcie kontrolnym C, którego już nie ma
- [ ] `README.md` — bez zmian
- [ ] `CLAUDE.md` — bez zmian
- [ ] `CHANGELOG.md` — wpis w changelogu

## Ograniczenia techniczne

- Laravel 13, Filament 5, PHP 8.4 — stan po zadaniu 009.
- **Zadanie idzie po 018** i konsumuje jego warstwę oferty. Nazwy klas z 017 (`StaySellability`,
  `StaySellabilityVerdict`) są ustalone; nazwy z 018 były w chwili pisania propozycjami — sprawdź
  w kodzie zamiast zgadywać.
- ⚠️ **Żaden panel nie rejestruje własnego motywu** (`viteTheme()`), więc klasy Tailwinda użyte
  w widoku **nie mają skąd wziąć CSS-u** ([`panel-wlasciciela.md`](../../conventions/panel-wlasciciela.md) §1).
  Siatka kalendarza jest pierwszym ekranem w tym projekcie, który realnie potrzebuje własnego układu.

#### Rozpoznanie wtyczek (2026-09-22) — trzy drogi, dwie odrzucone

| Droga | Werdykt |
|---|---|
| **`guava/calendar`** (GuavaCZ, MIT, **stabilna dla Filamenta 5**), widoki `ResourceTimeline*` na silniku `vkurko/calendar` — **bez opłat licencyjnych** | **odrzucona, ale warta odnotowania**: nasze komórki nie są zdarzeniami, tylko wyliczonymi werdyktami. Oddanie ich kalendarzowi zdarzeń znaczy produkowanie setek sztucznych „eventów" jednodobowych na render i walkę z `eventContent`, żeby wcisnąć w nie kwotę i liczbę dób |
| **`saade/filament-fullcalendar`** | odrzucona podwójnie: wsparcie dla Filamenta 5 jest dziś **w becie**, a widok `resourceTimeline` to **FullCalendar Premium** — licencja od **480 USD**, odnawiana rocznie, na zastosowanie komercyjne |
| **Tabela Filamenta z kolumnami generowanymi na doby** | odrzucona: tabele Filamenta 5 **nie znają colspanu**, więc wiersz „Pakiety" jest w nich niewykonalny. Sortowanie, filtry i przełączniki kolumn są tu martwym balastem przy setkach komórek |
| **✅ Własny widok Blade na stronie zasobu** | wybrana: ekran jest tylko do odczytu, a zwykły `<table>` z colspanem, tooltipem i kwotą w komórce nie potrzebuje modelu zdarzeń |

⚠️ **To była moja wcześniejsza rekomendacja i była błędna** — zakładałem, że tabela Filamenta pozwoli
uniknąć własnego motywu. Nie pozwala, a przy okazji nie unosi colspanu.

- ⚠️ **PREREKWIZYT POZA TYM ZADANIEM: rejestracja motywu panelu.** Własny widok Blade **i obie
  wtyczki** wymagają `viteTheme()`, którego projekt nie ma w żadnym panelu. To zmiana dotykająca
  **obu paneli i wszystkich przyszłych ekranów**, więc nie mieści się w 019 i nie powinna się w nim
  przemycić „przy okazji". Patrz otwarte pytanie 2.
- ⚠️ **Nie czytaj wartości kontrolek z DOM-u** i nie trzymaj stanu w `x-data` obok pól `live()` —
  obie pułapki są opisane w `panel-wlasciciela.md` §4 i obie objawiają się **wyłącznie
  w przeglądarce**.
- **Doby liczy wyłącznie `FishingDayCalendar`**, a sprzedawalność i cenę — warstwa oferty. Kalendarz
  nie liczy niczego po swojemu (`dostepnosc.md` §1 i §2).
- Wszystko w **strefie czasowej łowiska**; widok zależy od „dzisiaj", więc testy **zamrażają czas**.
- Nazwy klas i tras po angielsku; dokumentacja po polsku.
- Testy biegną na MySQL-u w schemacie `lowiska_test`.

## Rozstrzygnięcia

Ustalenia z wywiadu przy zakładaniu zadania (2026-09-22).

1. **Komórka pokazuje dobę wraz z najkrótszym kupowalnym pobytem**, a nad siatką stoją kontrolki
   składu osobowego i długości pobytu. Domyślnie 1 łowiący, 0 towarzyszących i najkrótszy możliwy
   pobyt — widok typu „ceny od". Wariant czysto dobowy odpadł, bo nie pokazywałby skutków spoiwa,
   czyli tego, po co kalendarz powstaje.
2. **Zasięg: siatka stanowiska × doby.** Wariant „jedno stanowisko, długi zakres" odpadł mimo
   niższego kosztu, bo blokady wskazują **zbiory** stanowisk i przy widoku jednego stanowiska naraz
   najłatwiejszy do przeoczenia błąd konfiguracji pozostaje niewidoczny. Szerokość okna rozstrzyga
   punkt 20.
3. **019 idzie przed 020, a kalendarz nie obejmuje usług dodatkowych.** Powód: 019 jest mechanizmem
   weryfikacyjnym dla 017 i 018, a odsunięcie go opóźniałoby pierwsze realne sprawdzenie spoiwa
   i silnika cen oraz **pomiar kosztu**, który ADR-013 zostawił jako jedyne nieznane ryzyko pakietu.
   Dołożenie usług do gotowego kalendarza jest addytywne i tańsze niż odwrotna kolejność.
4. **Tier T1 ze zwykłej reguły**, nie z odstępstwa pakietowego — zadanie nie dotyka migracji ani
   żadnego innego wyzwalacza T3, a kalendarz jest liściem bez kontraktu na zewnątrz.
5. **Punkty kontrolne B i C zastąpione jednym wspólnym przeglądem po pakiecie 017–021.** Zmienia to
   §14.2 wymagań; odroczenie pełnego pakietu pozostaje odroczeniem, nie zwolnieniem.
6. ~~Najkrótszy kupowalny pobyt jest wyliczany z konfiguracji, nie szukany przez próbowanie kolejnych
   długości.~~ **Nieaktualne — zastąpione punktem 8.** Odpowiedzi udziela warstwa oferty, a jej
   `shortestOffer()` **szuka iteracyjnie**, tyle że skacze po zakresie pakietu zamiast dochodzić po
   jednej dobie. Kalendarza to nie dotyczy: on woła raz na komórkę i niczego nie próbuje.
7. **Kalendarz jest tylko do odczytu** i nie dokłada migracji.
8. **Najkrótszy kupowalny pobyt wystawia WARSTWA OFERTY, a kalendarz go konsumuje.** Wyliczenie
   składa się z reguł należących do 017 (pakiet, `min_nights`, zwolnienie świąteczne, minimum
   przedsprzedaży, `max_nights`), więc odtworzenie go w widoku łamałoby ADR-013, a wołanie
   `StaySellability` wprost — zasadę „jedno wejście" z 018. Wymaganie zostało dopisane do 018,
   dopóki było w implementacji.
9. **Kotwicą widoku jest sezon, nie „dziś".** Nad siatką stoi lista trwających i przyszłych okresów
   sprzedaży z akcją „przejdź do"; domyślnie dziś, a poza sezonem — początek najbliższego przyszłego
   okresu. Okno „30 dni od dziś" pokazywałoby same odmowy dokładnie wtedy, gdy kalendarz jest
   najbardziej potrzebny: przy konfigurowaniu przyszłego sezonu.
10. **Komórka pokazuje liczbę dób obok kwoty**, bo w widoku „ceny od" sąsiadują pozycje o różnej
    długości i sama kwota wprowadzałaby w błąd.
11. **Stany puste zastępują siatkę komunikatem** kierującym do brakującego ekranu — kalendarz jest
    narzędziem onboardingu, więc trzydzieści kolumn „poza sezonem" jest gorsze niż jedno zdanie
    „najpierw ustaw godziny doby".
12. **`SaleCalendarPageTest` sprawdza otwarcie strony w obu panelach** — teza „zadanie nie zmienia
    kontraktu używanego gdzie indziej" ma być udowodniona, a nie założona, skoro dopisujemy pozycję
    do współdzielonego `FisheryResource`. Tier pozostaje **T1**.
13. **Podpowiedź blokady podaje liczbę objętych stanowisk i sposób ich wyboru.** Bez tego siatka
    stanowiska × doby nie realizuje argumentu, którym została wybrana — operator miałby liczyć
    zaczernione komórki wzrokiem. Dane istnieją od 016 (zmaterializowany zbiór i `selection_label`).
14. **Stanowisko wycofane zajmuje wiersz jednym komunikatem**, a nie kolumną po kolumnie; przyczyna
    jest niezależna od dat. Kryterium o „wierszu na stanowisko" ma ten wyjątek zapisany, żeby test
    nie stanął w sprzeczności z ekranem.
15. **Wiersz „Pakiety" pokazuje regułę na poziomie łowiska, a wiersze stanowisk jej skutek.** Przy
    rozbieżności — czyli po przycięciu pakietu na konkretnym stanowisku — **prawdę o sprzedaży niesie
    wiersz stanowiska**. ⚠️ Wiersz wymaga **trzeciej odpowiedzi warstwy oferty** (pakiety łowiska
    przed przycięciem), której dziś nie ma; to zależność poza tym zadaniem, opisana w wymaganiach.
16. **Okno jest przesuwane w granicach sezonu.** Kotwica sezonowa rozwiązała **wejście** do sezonu,
    ale nie poruszanie się po nim: sezony obu łowisk trwają dziesięć miesięcy, więc bez przesuwania
    operator widziałby pierwszy miesiąc i nie miał jak zapytać o sierpień. ⚠️ Szerokość okna
    rozstrzyga **punkt 20** — wcześniejszy wariant „zamknięta lista 14 / 30 / 60 dób" jest
    **nieaktualny**.
17. **Stan pusty „brak reguł stawki" to sprawdzenie istnienia**, nie druga implementacja wykrywania
    dziur — pełna analiza, po przedefiniowaniu 018 iterująca **wyłącznie po dobach**, mieszka
    w walidacji tamtego zadania.
18. **Podpowiedź blokady działa też bez `selection_label`** (kolumna jest nullable dla zaznaczenia
    ręcznego): pokazuje wtedy samą liczbę stanowisk, nigdy pusty nawias.
19. **„Nie można tu zacząć" to OSOBNY STAN WYNIKU, nie nowa wartość enuma** — rozstrzygnięte już
    w kodzie 018, nie do rozstrzygania tutaj. `StayOffer::shortestOffer()` zwraca
    `ShortestStayVerdict` o trzech postaciach: `found(nights, breakdown)`, **`startsEarlier(reason,
    bundleFirstDay)`** oraz `none(reason)`. Środkowa niesie istniejący powód ze spoiwa
    (`WeekendBroken` / `WholeTermBroken`) **wraz z pierwszą dobą pakietu**, więc komórka ma z czego
    złożyć „zacznij 03.06" bez dokładania czternastej wartości do `SaleUnavailabilityReason`.
    Kalendarz mapuje trzy postacie werdyktu wprost na trzy stany komórki.
20. **Okno jest jednostką kalendarzową: miesiąc (domyślnie) albo tydzień** — nie „N dni od kotwicy".
    Przy zmianie sezonu widok przeskakuje na jednostkę zawierającą **początek** wybranego okresu,
    a nie na dzień startu. Dłuższych okien nie oferujemy w ogóle, bo koszt rośnie z iloczynem dób
    i stanowisk; pomiar idzie przy oknie miesięcznym jako najszerszym.
21. **Siatkę buduje własny widok Blade, nie tabela Filamenta ani wtyczka kalendarza** — rozpoznanie
    z 2026-09-22. Tabela Filamenta 5 nie zna colspanu (wiersz „Pakiety" byłby niewykonalny),
    `saade/filament-fullcalendar` wymaga płatnego FullCalendar Premium dla widoku zasobowego,
    a `guava/calendar` — choć darmowy i stabilny dla Filamenta 5 — zakłada model **zdarzeń**, którym
    nasze komórki nie są. Wszystkie trzy drogi i tak wymagają własnego motywu, więc ograniczenie
    „brak `viteTheme()`" nie przemawia za żadną z nich.
22. **Kalendarz pokazuje nachodzenie stawek, bo po 018 nie robi tego już nic innego.** Uproszczenie
    zniosło remis jako błąd zapisu, więc dwie reguły na tę samą dobę rozstrzygają się cicho. Licznik
    pojawia się przy **każdym** nachodzeniu, także przy identycznych kwotach — dwie takie same stawki
    są pomyłką zawsze, a przy progu „pokazuj dopiero przy różnicy kwot" byłyby jedynym rodzajem
    bałaganu, którego nie widać nigdzie. Podpowiedź podaje zwycięzcę i kwoty przegranych, co zamyka
    pytanie „czemu widzę 70, skoro wpisałem 90".
23. **Martwa stawka to analiza zbioru, nie renderu** — liczona raz na cennik, z porównania
    przedziałów dat i kwot, i oznaczana **jeden raz na regule**. Rozciągnięcie jej na każdą dobę
    siatki byłoby kosztem bez informacji.
24. **Wynik pomiaru kosztu trafia do treści tego zadania**, nie do osobnego dokumentu ani do ADR-a.
    ADR powstaje wtedy, gdy liczby uzasadnią decyzję o buforze — zakładanie go przed pomiarem byłoby
    odwróceniem kolejności, przed którym ostrzega ADR-013.

## Powiązane ADR-y

- [ADR-016 — Własny motyw panelu i granica stosowania klas Tailwinda](../../adr/ADR-016-wlasny-motyw-panelu.md)
  — **założony przy przeglądzie tego zadania**, sekcja „Decyzja" do wypełnienia. Bez niego 019 nie da
  się zaimplementować żadną z trzech rozważanych dróg.

Zadanie **stosuje**, ale nie zmienia: [ADR-012](../../adr/ADR-012-jedno-zrodlo-prawdy-o-dostepnosci.md)
(jedno źródło prawdy o dostępności), [ADR-013](../../adr/ADR-013-warstwa-regul-pobytu-i-spoiwo-dob.md)
(spoiwo dób, przycinanie, zlewanie), [ADR-014](../../adr/ADR-014-cennik-jako-lista-regul-z-warunkami.md)
(cennik jako lista reguł) oraz [ADR-015](../../adr/ADR-015-warstwa-oferty-pobytu.md) (warstwa oferty
jako jedyne wejście).

## Otwarte pytania dla `/review-task`

1. **Rejestracja motywu panelu — ADR założony, czeka na „Decyzję".**
   [ADR-016](../../adr/ADR-016-wlasny-motyw-panelu.md) opisuje trzy warianty i rekomenduje osobne małe
   zadanie przed 019. ⚠️ Kolejność jest wiążąca niezależnie od wyboru: **motyw musi istnieć przed
   implementacją 019**, bo bez niego nie działa żadna z trzech dróg budowy siatki.
1a. **Czy 018 ma wystawić pakiety łowiska?** Przy przedefiniowaniu dołożono diagnostykę cennika,
   ale pakietów przed przycięciem nie. Od tej odpowiedzi zależy, czy powstaje wiersz „Pakiety" —
   patrz „Wymagania".
2. **Co pokazuje komórka, gdy wyliczone minimum przekracza `max_nights`?** To konflikt, który 018
   zostawił jako otwarty (pakiet dłuższy niż maksimum jest niekupowalny). Kalendarz jest pierwszym
   miejscem, w którym operator by go zobaczył — warto ustalić komunikat razem z rozstrzygnięciem
   tamtego pytania, a nie osobno.
<!-- Pytania o kształt „nie można tu zacząć" oraz o miejsce pomiaru zostały rozstrzygnięte —
     patrz „Rozstrzygnięcia", punkty 19 i 20. -->
