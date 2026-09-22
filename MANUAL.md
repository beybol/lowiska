# Instrukcja obsługi

Portal składa się z dwóch paneli. **Panel właściciela** jest dla operatora łowiska: tutaj konfiguruje
się obiekt, stanowiska, sprzedaż i ograniczenia. **Panel administratora** jest dla prowadzących
portal: tutaj powstają słowniki, z których korzystają wszystkie łowiska, oraz konta i uprawnienia.

Część I opisuje panel właściciela, część II — panel administratora.

---

# Część I — Łowisko (panel właściciela)

## 1. Wprowadzenie

Ten rozdział opisuje panel właściciela łowiska: co robi się w każdej z zakładek widocznych po lewej
stronie, gdy wejdziesz w swoje łowisko. Jest przewodnikiem dla osoby, która loguje się pierwszy raz
i chce wiedzieć, od czego zacząć i gdzie czego szukać.

Każda zakładka odpowiada za jedną rzecz i można do niej wracać w dowolnym momencie — ustawienia
zapisują się osobno i nie trzeba wypełniać wszystkiego za jednym razem.

## 2. Kolejność wprowadzania danych

Najwygodniej iść po kolei; każdy krok korzysta z tego, co ustawiłeś wcześniej.

1. [Dane łowiska](#3-dane-łowiska) — sprawdź i uzupełnij podstawowe informacje o obiekcie.
2. [Sprzedaż i sezony](#4-sprzedaż-i-sezony) — powiedz systemowi, czym handlujesz i kiedy.
3. [Reguły sprzedaży](#5-reguły-sprzedaży) — powiedz, jaki pobyt wolno kupić.
4. [Cennik](#6-cennik) — powiedz, ile to kosztuje.
5. [Stanowiska](#7-stanowiska) — wprowadź miejsca, które sprzedajesz.
6. [Grupy stanowisk](#8-grupy-stanowisk) — pogrupuj stanowiska, jeśli to ułatwi Ci pracę.
7. [Usługi dodatkowe](#9-usługi-dodatkowe) — dodaj to, co wędkarz może dokupić.
8. [Pozwolenia długoterminowe](#10-pozwolenia-długoterminowe) — dodaj sezonówki, jeśli je sprzedajesz.
9. [Blokady i ograniczenia](#11-blokady-i-ograniczenia) — wracaj tutaj, ilekroć coś wypada z użytku.

Usługi dodatkowe i pozwolenia przypisuje się do konkretnych stanowisk na formularzu stanowiska —
dlatego po ich wprowadzeniu warto wrócić na chwilę do zakładki „Stanowiska".

## 3. Dane łowiska

Podgląd najważniejszych informacji o obiekcie: nazwa, firma, powierzchnia, liczba stanowisk i adres.
Jest to również strona startowa łowiska — stąd wchodzisz we wszystkie pozostałe zakładki.

Żeby zmienić którąkolwiek z tych informacji, użyj przycisku **Edytuj** w prawym górnym rogu.

Część pól na tym formularzu to **wybór ze słownika prowadzonego przez administratora**: rodzaj
łowiska, metody łowienia, udogodnienia, występujące ryby, waluta rozliczenia, kraj i województwo.
Nie dopisujesz do nich własnych pozycji — zaznaczasz te, które pasują do Twojego obiektu. Jeśli
któregoś z tych pól w ogóle nie widzisz, znaczy to, że odpowiedni słownik jest jeszcze pusty
(patrz [Słowniki](#14-słowniki--wspólne-dla-całego-portalu)).

## 4. Sprzedaż i sezony

Tutaj ustawiasz, czym dokładnie handlujesz: o której godzinie zaczyna się i kończy doba wędkarska
oraz w jakiej strefie czasowej ją liczymy. Doba zawsze kończy się **dnia następnego**, więc przechodzi
przez północ — typowo od 15:00 do 15:00.

Na tej samej stronie wyznaczasz okresy, w których łowisko sprzedaje. Poza nimi nie da się kupić nic,
nawet gdy stanowisko jest wolne, a łowisko bez ani jednego okresu nie sprzedaje wcale. Okresy nie mogą
na siebie zachodzić — jeśli się pokryją, system nie pozwoli zapisać zmian.

Przy każdym okresie możesz włączyć **przedsprzedaż**: okno, w którym wolno kupować doby tego sezonu,
zanim wejdzie on w normalną sprzedaż. Podajesz, od kiedy do kiedy okno jest otwarte, i możesz wymagać,
żeby zakup w tym oknie obejmował co najmniej tyle dób. To wymaganie dotyczy **każdego** zakupu dób
tego sezonu w czasie otwartego okna — przedsprzedaż jest ofertą na dłuższy pobyt, a nie na pojedynczą
dobę. Osobny przełącznik decyduje, czy święta sprzedawane w całości są z tego wymagania wyjęte
(domyślnie tak — trzydobowa majówka przechodzi w oknie wymagającym pięciu dób). W tym samym bloku
podajesz **obniżkę ceny** w procentach: zdejmuje się ją od każdej doby osobno, od stawki wraz
z dopłatami, i wędkarz widzi ją przy każdej dobie.

Na końcu strony ustawiasz **horyzont sprzedaży**: jak daleko w przód wędkarz może kupować. Doba
rozpoczynająca się dokładnie tyle dni od dziś jeszcze się sprzedaje. Okno przedsprzedaży jest
wyjątkiem właśnie od horyzontu — pozwala kupić doby dalej, niż on sięga. Jeśli nie ustawisz
horyzontu, samo okno niczego nie otworzy (doby i tak są kupowalne), a jedynie ograniczy sprzedaż
swoim wymaganiem długości; system ostrzeże Cię o tym przy zapisie.

## 5. Reguły sprzedaży

Tutaj mówisz, **jaki pobyt** wolno kupić. Poprzednia zakładka odpowiada, kiedy sprzedajesz; ta —
co dokładnie wolno wziąć z tego, co sprzedajesz. Łowisko bez żadnej z tych reguł sprzedaje pobyty
dowolnej długości, więc pusta strona też jest poprawnym ustawieniem.

**Długość pobytu** — najkrótszy i najdłuższy pobyt liczony w dobach. Puste pole znaczy „bez
granicy", a nie zero.

**Weekend sprzedawany wyłącznie w całości** — po włączeniu przełącznika zaznaczasz doby, które idą
razem. ⚠️ Zaznaczasz **doby, nie dni**: każda pozycja na liście to pobyt od godziny do godziny
(„pt → sob · 15:00 → 15:00"), więc weekend od piątku 15:00 do niedzieli 15:00 to **dwie** doby —
piątkowa i sobotnia — a nie trzy dni. Pod listą widzisz podsumowanie, żeby to sprawdzić. Pobyt,
który dotyka takiego weekendu, musi objąć go w całości: samej soboty kupić się nie da, ale piątek
z sobotą i dwoma dniami dalej — owszem. Doba niedzielna (niedziela 15:00 → poniedziałek 15:00) leży
poza weekendem i sprzedaje się jak zwykły dzień.

**Święta sprzedawane w całości** — terminy z datą, na przykład majówka. Podajesz nazwę własną
(zobaczysz ją tylko Ty), **pierwszą dobę** i **ostatnią dobę** terminu. ⚠️ „Ostatnia doba" to doba
objęta terminem, a **nie** dzień wyjazdu — dlatego pod polami pokazujemy wyliczony pobyt („czw 30.04
15:00 → nd 3.05 15:00 · 3 doby"). Sprawdź tę linijkę: to ona mówi, co wędkarz faktycznie kupi.

Święto musi mieścić się w okresie sprzedaży, każdą swoją dobą — inaczej zapis nie przejdzie.
Święta mogą na siebie zachodzić i mogą zachodzić na weekend; wtedy zlewają się w jeden większy
pakiet, który też sprzedaje się w całości. Jeśli blokada wyłączy część dób pakietu, resztę nadal
można kupić — wyłączone doby po prostu z niego wypadają.

Pobyt obejmujący święto **nie podlega** najkrótszej długości pobytu: trzydobowa majówka sprzedaje
się także wtedy, gdy zwykle wymagasz pięciu dób. Weekend sam z siebie takiego wyjątku nie daje.

Obie sekcje — weekend i święta — są nieaktywne, dopóki nie ustawisz godzin doby w „Sprzedaż
i sezony". Bez nich nie da się pokazać, od której do której godziny trwa doba.

## 6. Cennik

Tutaj mówisz, **ile kosztuje doba** na stanowisku. Cennik nie jest tabelą cen, tylko **listą
reguł z warunkami** — dzięki temu nowy wymiar cennika dokładasz warunkiem, a nie czekaniem na
zmianę w programie.

Reguły są dwojakiego rodzaju i różni je tylko jedno:

- **Stawka** — *zastępuje* cenę. Dla jednej doby i jednej roli wygrywa dokładnie jedna.
- **Dopłata** — *dodaje się* do stawki. Wszystkie pasujące sumują się.

Przy każdej regule podajesz kwotę **za osobę za dobę**, opis widoczny dla wędkarza, priorytet
oraz — opcjonalnie — warunki: doby tygodnia, zakres dat, dokładną liczbę łowiących i rolę
uczestnika. **Reguła bez żadnego warunku jest stawką bazową** łowiska; większości obiektów
wystarczy jedna taka stawka i jedna dopłata.

⚠️ **Warunek liczy się osobno dla każdej doby.** Dopłata „czwartek–niedziela" przy pobycie
od środy do piątku naliczy się za czwartek i piątek, a nie za cały pobyt ani za nic.

**Która reguła wygrywa.** Najpierw wyższy **priorytet**, a przy równym — ta z **większą liczbą
warunków**. Jeśli dwie stawki mają ten sam priorytet i tyle samo warunków, i obie pasują do tej
samej doby, system **nie zapisze cennika**: nie zgadujemy, którą cenę miałeś na myśli. Wyjściem
jest podniesienie priorytetu jednej z nich.

⚠️ **Dwie rzeczy, na które trzeba uważać — obie łatwe do przeoczenia:**

1. **Dopłata bez warunku roli nalicza się za KAŻDĄ osobę**, także towarzyszącą. Jeśli dopłata
   ma dotyczyć tylko łowiących, ustaw jej rolę na „łowiący". To najczęstsza pomyłka w całym
   cenniku i najtrudniejsza do zauważenia, bo wszystko wygląda poprawnie.
2. **Stawka osoby towarzyszącej musi mieć najwyższy priorytet.** Każda stawka warunkowa bez
   warunku roli pasuje także do niej — przy równym priorytecie zderzy się z nią i cennik nie
   przejdzie, a przy niższym towarzysząca zapłaci pełną stawkę. Gdy dopiszesz stawkę o priorytecie
   wyższym niż stawka towarzyszącej, system Cię o tym ostrzeże, ale zapis przepuści: czasem
   właśnie o to chodzi (np. w sylwestra płacą wszyscy).

**Zawieszenie zamiast kasowania.** Reguły, której czasowo nie chcesz stosować, nie musisz usuwać
— wystarczy ją zawiesić. Zostaje w cenniku i włączysz ją jednym kliknięciem.

**Od kiedy obowiązuje zmiana.** Każda reguła ma własny okres obowiązywania („obowiązuje od / do").
To **coś innego** niż warunek zakresu dat: okres obowiązywania mówi, od kiedy ten zapis bierze
udział w wycenie, a warunek — których dób dotyczy. Dzięki temu w maju możesz przygotować cennik
wakacji, który zacznie działać dopiero 1 czerwca.

**Osoba towarzysząca** nie jest osobnym ustawieniem, tylko stawką z rolą „osoba towarzysząca"
i kwotą 0,00 zł. Jeśli chcesz, żeby płaciła — wpisz inną kwotę.

⚠️ **Doba bez pasującej stawki jest niesprzedawalna**, a nie darmowa. Po zapisie system ostrzeże
Cię, jeśli w otwartym sezonie znajdzie taką dobę — ale ostrzeżenie sprawdza **dzisiejszy** stan
cennika, więc wydłużenie sezonu albo wygaśnięcie reguły może otworzyć dziurę później.

## 7. Stanowiska

Lista miejsc, które sprzedajesz. Przy każdym podajesz nazwę, stan („w sprzedaży" albo „wycofane"),
pojemność — ilu wędkarzy może na nim łowić i ile osób może na nim przebywać łącznie — oraz opis
i cechy, po których wędkarz wybiera miejsce (na przykład pomost, wjazd samochodem czy odległość do
parkingu).

Na tym samym formularzu przypisujesz stanowisko do grup, do pozwoleń długoterminowych i do usług
dodatkowych. Jeśli tę samą cechę chcesz ustawić wielu miejscom naraz, zaznacz je na liście i użyj
działania **Ustaw cechę** — przed zapisem zobaczysz, ilu stanowisk dotyczy.

Sekcja z cechami pojawia się dopiero wtedy, gdy administrator dopisał do słownika choć jedną cechę
(patrz [Cechy stanowisk](#15-cechy-stanowisk)).

## 8. Grupy stanowisk

Grupa to nazwana etykieta, którą łączysz stanowiska mające ze sobą coś wspólnego — brzeg, dojazd,
część obiektu. W opisie grupy mieści się wszystko, czego nie da się zapisać pojedynczą cechą: jak się
dojeżdża, gdzie stoi szlaban, czym różni się ten fragment łowiska.

Stanowisko może należeć do kilku grup naraz, a grupy nie nadają stanowiskom żadnych właściwości —
służą do opisu i do szybkiego wskazywania większej liczby miejsc. Z poziomu grupy możesz też ustawić
cechę wszystkim jej stanowiskom naraz.

## 9. Usługi dodatkowe

Wszystko, co wędkarz **dokupuje** do pobytu: łódka, prysznic, wypożyczenie sprzętu. Przy każdej usłudze
podajesz nazwę, opis, cenę, liczbę dostępnych sztuk oraz to, czy usługa jest aktywna.

Samo dodanie usługi jej nie udostępnia — przypisujesz ją do konkretnych stanowisk na formularzu
stanowiska, gdzie możesz też oznaczyć, że jest dla danego miejsca obowiązkowa.

## 10. Pozwolenia długoterminowe

Pozwolenia na dłuższy okres, na przykład sezonowe. Podajesz opis, okres ważności, cenę, limit sprzedaży
oraz to, czy pozwolenie jest aktywne.

Podobnie jak usługi, pozwolenie wiążesz ze stanowiskami na formularzu stanowiska — dzięki temu wiadomo,
gdzie dane pozwolenie obowiązuje.

## 11. Blokady i ograniczenia

Tutaj wyłączasz coś na określony czas: zawody, zarybianie, remont, decyzja urzędu. Wpis ma dwa możliwe
skutki — albo **blokuje sprzedaż** wskazanych stanowisk, albo **zawiesza jedną cechę** (na przykład
wjazd samochodem), nie zmieniając jej na stałe. Po upływie terminu wszystko wraca samo.

Wybierasz daty (data końcowa może zostać pusta, czyli „do odwołania"), wpisujesz powód i decydujesz,
czy wędkarz go zobaczy. Zbiór stanowisk wskazujesz dowolnie — całe łowisko, grupa, stanowiska z daną
cechą albo ręczne zaznaczenie — a po przeliczeniu listy możesz ją jeszcze poprawić. Zapisana lista już
się nie zmienia: stanowisko założone później nie wejdzie do istniejącego wpisu, o czym system ostrzeże
przy jego zakładaniu.

Skutek „zawieszenie cechy" i kryterium „stanowiska z cechą" widać tylko wtedy, gdy w słowniku jest
choć jedna cecha typu **tak/nie** — tylko taka da się zawiesić. Przy pustym słowniku obie opcje są
ukryte, bo nie miałyby czego dotyczyć.

---

# Część II — Admin (panel administratora)

## 12. Do czego służy panel administratora

Panel administratora jest narzędziem **prowadzących portal**, nie operatorów łowisk. Odpowiada za
trzy rzeczy:

- **słowniki** — wspólne listy, z których wybierają wszystkie łowiska;
- **rejestr podmiotów i obiektów** — firmy i łowiska założone w portalu;
- **dostępy** — konta użytkowników i ich uprawnienia.

Właściciel łowiska nie ma tu wstępu i nie edytuje niczego z tej listy. Odwrotnie też: administrator
nie ustawia za niego cen, sezonów ani stanowisk — to należy do panelu właściciela.

## 13. Firmy i łowiska

**Firmy** to podmioty gospodarcze prowadzące łowiska. Dane adresowe da się pobrać automatycznie
z rejestru GUS po numerze NIP albo REGON, zamiast przepisywać je ręcznie. Numer rachunku bankowego
jest sprawdzany jako IBAN — błędny nie przejdzie zapisu.

**Łowiska** to widok wszystkich obiektów w portalu, niezależnie od tego, kto je założył. Służy do
przeglądu i do interwencji; codzienna konfiguracja obiektu odbywa się po stronie właściciela.

## 14. Słowniki — wspólne dla całego portalu

Grupa **Słowniki** w menu zbiera listy, z których korzystają formularze właścicieli. Zasada jest
jedna i ważna: **słownik jest wspólny dla całego portalu, a właściciel tylko z niego wybiera**.
To warunek, pod którym wyszukiwanie i porównywanie łowisk między sobą ma sens — gdyby każdy operator
dopisywał własne pozycje, ta sama rzecz nazywałaby się w portalu na kilkanaście sposobów.

| Słownik | Czego dotyczy | Gdzie widzi to właściciel |
|---|---|---|
| **Udogodnienia** | całego obiektu | formularz łowiska |
| **Rodzaje łowisk** | całego obiektu | formularz łowiska |
| **Metody łowienia** | całego obiektu | formularz łowiska |
| **Ryby** | całego obiektu | formularz łowiska (występujące ryby, ryba dominująca) |
| **Cechy stanowisk** | pojedynczego stanowiska | formularz stanowiska |
| **Kraje**, **Województwa** | adresów | formularze firmy i łowiska |
| **Waluty** | rozliczeń | formularz łowiska |

⚠️ **Pusty słownik znika z formularza właściciela.** Pole nie pokazuje się jako puste — nie ma go
wcale. Właściciel nie dostaje żadnego komunikatu i nie ma jak się domyślić, że dana możliwość
w ogóle istnieje. Dlatego każdy słownik, który ma być używany, wymaga zestawu startowego wpisanego
przez administratora.

## 15. Cechy stanowisk

Najmłodsza i najbogatsza pozycja słownikowa. Cecha opisuje **pojedyncze stanowisko**, a nie cały
obiekt: pomost, zadaszenie, wjazd samochodem, odległość do parkingu, rodzaj dna.

Każda cecha ma **typ**, który przesądza o tym, jak wygląda pole na formularzu stanowiska:

| Typ | Co wpisuje właściciel | Uwagi |
|---|---|---|
| **Tak / nie** | jedną z dwóch wartości | jedyny typ, który da się czasowo **zawiesić** blokadą |
| **Liczba** | liczbę w zadanej jednostce | jednostkę (m, min) podaje się przy definicji cechy |
| **Wybór z listy** | jedną z opcji | opcje definiuje administrator przy cesze |

Dodatkowo:

- **Do filtrowania** — znacznik dla przyszłej wyszukiwarki portalu. Sama wyszukiwarka jeszcze nie
  istnieje; znacznik mówi, które cechy mają się w niej znaleźć.
- **Puste pole na stanowisku znaczy „nie wiadomo", a nie „nie ma".** To trzeci stan i jest zamierzony
  — nowa cecha dopisana do słownika pojawia się na wszystkich stanowiskach jako niewypełniona,
  zamiast kłamać, że żadne stanowisko jej nie ma.
- Dopisanie cechy do słownika udostępnia ją **we wszystkich łowiskach naraz**, bez żadnej dodatkowej
  czynności po stronie właścicieli.
- Tylko cecha typu **tak/nie** da się zawiesić wpisem w „Blokadach i ograniczeniach". Zawieszenie
  liczby albo wyboru z listy byłoby nadpisaniem wartości, czyli czymś innym niż czasowe wyłączenie.

## 16. Udogodnienia a cechy stanowisk — gdzie przebiega granica

Oba słowniki opisują „co tu jest", więc łatwo je pomylić. Różni je **przedmiot opisu**:

- **udogodnienie** dotyczy **całego łowiska** — parking, toaleta, sklep, restauracja, całodobowy
  dozór;
- **cecha** dotyczy **jednego stanowiska** — pomost, zadaszenie, wjazd samochodem, odległość do
  parkingu.

**Test rozstrzygający:** czy to samo zdanie może być prawdziwe dla jednego stanowiska i fałszywe dla
sąsiedniego w tym samym łowisku? Jeśli tak — to cecha. Jeśli dotyczy obiektu jako całości — to
udogodnienie.

Przy tak postawionej granicy **oba słowniki zostają i się nie pokrywają**. Warto jednak wiedzieć, że
nie są równoważne technicznie: udogodnienie to **sama nazwa**, którą łowisko ma albo nie ma. Cecha
niesie typ, jednostkę, listę opcji, trzeci stan „nie wiadomo", znacznik do wyszukiwarki, ustawianie
hurtem i możliwość czasowego zawieszenia. Cecha jest więc nadzbiorem udogodnienia.

⚠️ **Realne ryzyko to nie duplikat modeli, tylko duplikat NAZW.** „Prąd", „parking" czy „zadaszenie"
da się w dobrej wierze wpisać do obu słowników i dopiero wtedy portal zaczyna się rozjeżdżać. Przed
dopisaniem pozycji zadaj pytanie z testu wyżej.

Gdyby kiedyś przyszła decyzja o połączeniu obu słowników w jeden, kierunek jest tylko jeden:
udogodnienia stają się cechami na poziomie łowiska, nie odwrotnie. Jest to jednak osobna decyzja
wymagająca migracji danych, a nie porządek do zrobienia przy okazji.

## 17. Dostępy: użytkownicy i role

Grupa **Dostępy** zbiera konta i uprawnienia.

- **Użytkownicy** — konta w portalu. Konto oznaczone jako administracyjne dostaje wstęp do panelu
  administratora; pozostałe logują się do panelu właściciela.
- **Role** — zestawy uprawnień. To rola, a nie sam znacznik konta, przesądza o tym, co użytkownik
  widzi w panelu i co może w nim zrobić. Konto administracyjne bez roli wejdzie do panelu, ale nie
  zobaczy w nim żadnej pozycji menu.

⚠️ Uprawnienia są **wyprowadzane z listy elementów panelu**, nie wpisywane ręcznie. Po dołożeniu
nowego elementu trzeba je przegenerować — inaczej nowa pozycja nie pojawi się w menu **nikomu**,
łącznie z administratorem mającym komplet uprawnień sprzed zmiany.
