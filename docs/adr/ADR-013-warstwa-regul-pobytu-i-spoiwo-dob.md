# ADR-013 — Warstwa reguł pobytu: spoiwo dób, kolejność sprawdzania i kształt odmowy

- **Status:** accepted
- **Data:** 2026-09-21
- **Zadanie:** [017 — Reguły sprzedaży: długość pobytu, weekend i święta w całości, przedsprzedaż i horyzont](../tasks/implemented/017-reguly-sprzedazy-dlugosc-terminy-przedsprzedaz.md)

## Kontekst

Po zadaniach 015, 014 i 016 system odpowiada na pytania o **pojedynczą dobę**: czy wolno ją sprzedać
na tym stanowisku i dlaczego nie ([ADR-012](ADR-012-jedno-zrodlo-prawdy-o-dostepnosci.md)). Klasztorne
ma cztery reguły spisane wprost na stronie, których **żadna nie daje się wyrazić jako własność
pojedynczej doby**: weekendy sprzedawane wyłącznie w całości, dwa terminy świąteczne sprzedawane
wyłącznie w całości, przedsprzedaż kolejnego sezonu z warunkiem długości oraz horyzont sprzedaży.
Bez nich sezon 2026 nie da się odwzorować.

Brakuje samego pojęcia: **pobytu** — ciągu dób kupowanego razem, na jednym stanowisku. Reguły
dotyczą ciągu, nie jego elementów.

⚠️ **Rzecz, której nie widać z samego zadania — a jest dokładnie tym, przed czym ostrzegał
ADR-012.** Nowa klasa odpowiadająca na pytanie „czy wolno to kupić" wygląda jak **drugie źródło
prawdy o dostępności**, a różnica między nią a `PositionAvailability` („pobyt" kontra „doba") jest
niewidoczna w nazwie. ADR-012 opisał tę samą pułapkę między `FishingDayCalendar`
a `PositionAvailability` i rozstrzygnął ją przez kompozycję, nie przez zastąpienie. To zadanie
powtarza ten układ o jedno piętro wyżej — i właśnie dlatego zasługuje na własny zapis, a nie na
milczące założenie, że „to oczywiste".

**Kontrargument, który trzeba odnotować:** można twierdzić, że to zadanie jest wyłącznie
**zastosowaniem** ADR-012, więc nowy ADR jest zbędny. Kontrargument upada na tym, że rozstrzygnięcia
niżej dotyczą rzeczy, o których ADR-012 nie mówi nic: czy weekend i święto to jedna mechanika, czy
dwie, oraz komu należy się zwolnienie z minimum długości. Odwrócenie żadnej z nich nie jest zmianą
w `PositionAvailability`.

**Pierwsza wersja zadania modelowała to inaczej i pękła.** Długość pobytu była regułą zależną od dnia
rozpoczęcia (`stay_length_rules`: dzień tygodnia → min/max dób). Przegląd z 2026-09-20 wykazał, że
ten model **nie wyraża** reguły „weekendy tylko w całości": „przyjazd w piątek → min. 2 doby" nie
zabrania przyjazdu w sobotę — sama sobota przechodzi przy bazowym minimum 1, a przy podniesionym
do 2 przechodzi sobota–poniedziałek.

Do rozstrzygnięcia są więc trzy rzeczy naraz, bo każda osobno nie ma sensu — tak samo jak
w ADR-012: **gdzie to mieszka**, **czym są weekend i święto względem siebie** i **w jakiej
kolejności warunki się składają** (kolejność przesądza komunikat, który zobaczy wędkarz, więc jest
umową produktową, nie szczegółem implementacji).

## Alternatywy

### Opcja A — osobna warstwa pobytu komponująca `PositionAvailability`; weekend i święto jako JEDNA mechanika („spoiwo dób")

`PositionAvailability` zostaje przy tym, co ustala ADR-012: odpowiada o **dobę**. Nowa klasa
w `app/Services/` **woła ją** dla każdej doby pobytu i dokłada warunki dotyczące **ciągu**. Jest
jedynym wejściem dla panelu, cennika (018), kalendarza (019) i przyszłego portalu.

**Spoiwo** to jedno pojęcie o dwóch regułach powtarzania: doby, które idą wyłącznie razem —
**cyklicznie** (weekend: zbiór dni rozpoczęcia dób na łowisku) albo **jednorazowo** (święto
datowane). *Instancja pakietu* to maksymalny ciąg kolejnych dób spiętych spoiwem; nic nie jest
zmaterializowane, instancje liczą się w chwili pytania. Instancje buduje się **wyłącznie z dób
sprzedawalnych** (przycinanie) i **zlewa**, gdy na siebie zachodzą (przechodniość spoiwa).

Warunki składane w stałej kolejności, od przyczyny **najtrwalszej** do najbardziej czasowej —
tym samym kryterium, którym ADR-012 uporządkował warunki wewnątrz dostępności:

1. **dostępność każdej doby** pobytu (przez `PositionAvailability`),
2. **spoiwo** — pobyt przecinający instancję pakietu musi ją objąć w całości,
3. **długość** — `min_nights` / `max_nights`,
4. **horyzont i przedsprzedaż**.

**Zwolnienie z minimum długości należy do PAKIETU zawierającego dobę święta**, a nie do samego
święta, i jest zero-jedynkowe (bez arytmetyki): wystarczy, że pobyt obejmuje choć jeden taki pakiet,
a `min_nights` przestaje go dotyczyć. `max_nights` obowiązuje dalej. Pakiet czysto weekendowy nie
zwalnia z niczego — „spoiwo tylko zaostrza, święto może łagodzić".

**Zalety:**
- Granica między klasami czytelna i zgodna z ADR-012 oraz ADR-010: kalendarz wie o **czasie**,
  `PositionAvailability` o **dobie na stanowisku**, nowa klasa o **ciągu dób**. Żaden z dwóch
  poprzednich ADR-ów nie wymaga przepisania.
- **Jedna mechanika zamiast dwóch** to jedna reguła objęcia, jedno przycinanie, jedno zlewanie
  i jeden zbiór testów. Weekend i święto różnią się wyłącznie sposobem powtarzania i komunikatem
  odmowy — reszta jest wspólna.
- Kolejność odpowiada temu, co jest użyteczne dla pytającego: „stanowisko jest wycofane" jest
  trwalsze niż „przerwany weekend", a to trwalsze niż „za krótki" czy „poza horyzontem". Wędkarz
  najpierw dostaje powód, z którym może coś zrobić na poziomie wyboru stanowiska.
- **Zwolnienie należące do pakietu daje wynik monotoniczny.** Przy `min_nights = 5` i trzydobowym
  święcie przechodzą pobyty 3-, 4-, 5- i 6-dobowe. Wariant „zwolnienie tylko dla pobytu równego
  świętu" dałby ciąg „3 przechodzi, 4 odmowa, 5 przechodzi", którego wędkarz nie zrozumie.
- **Zlanie nie czyni święta niekupowalnym.** Gdyby zwolnienie należało do święta, a nie do pakietu,
  samo dołożenie reguły weekendu odbierałoby zwolnienie pakietowi śr–sob — czyli konfiguracja
  jednej reguły psułaby drugą, a pakiet jest niepodzielny, więc jego długość **jest** najmniejszą
  kupowalną jednostką.
- Przycinanie do dób sprzedawalnych zamiast unieważniania całego pakietu: blokada soboty nie wyłącza
  cicho piątku, a wędkarz nie dostaje odmowy bez czytelnej przyczyny.

**Wady:**
- Trzecia klasa w łańcuchu; ktoś musi wiedzieć, którą wołać. Odpowiedź jest jednoznaczna
  (pytasz o dobę → `PositionAvailability`, pytasz o pobyt → nowa), ale nie wynika z nazw.
- Instancje liczone w chwili pytania oznaczają, że kalendarz z zadania 019 przelicza je dla każdej
  pary doba × stanowisko. Koszt jest realny i nieznany do pomiaru.
- Zlewanie pakietów jest skutkiem **niewidocznym w formularzu** — operator nie zobaczy, że święto
  śr–pt plus weekend pt–sob dało czterodobowy pakiet, dopóki nie powstanie kalendarz (019).
- Kolejność jest umową, której baza nie wymusza; złamanie jej nie zapali się nigdzie poza testem.

### Opcja B — reguły długości zależne od dnia rozpoczęcia (wariant 1 zadania)

Zamiast pojęcia pakietu — tabela `stay_length_rules`: dzień tygodnia rozpoczęcia → min/max dób.
Weekend „w całości" wyraża się jako „przyjazd w piątek: min. 2 doby".

**Zalety:**
- Żadne nowe pojęcie: to ta sama reguła długości, tylko sparametryzowana dniem.
- Formularz jest tabelką siedmiu wierszy — nic do wyjaśniania operatorowi.
- Brak przycinania, zlewania i zwolnień, więc brak całej klasy przypadków brzegowych.

**Wady:**
- ⚠️ **Nie wyraża reguły, dla której powstała.** „Przyjazd w piątek → min. 2 doby" nie zabrania
  przyjazdu w sobotę: sama sobota przechodzi przy bazowym minimum 1, a przy podniesionym do 2
  przechodzi sobota–poniedziałek. Weekend nadal da się rozerwać.
- Dwie rozważane łatki pogarszają sprawę: **jawny zakaz dnia przyjazdu** miesza limit z zakazem
  i jest niezrozumiały dla operatora, a **cykliczny generator terminów** wnosi masę przypadków
  brzegowych przy blokadach, świętach i zmianach w trakcie sezonu.
- Nie obejmuje świąt datowanych w ogóle — te wymagałyby drugiego, osobnego mechanizmu.

### Opcja C — dwie osobne mechaniki: weekend jako reguła cykliczna, święto jako turnus

Weekend zostaje regułą na łowisku, a święto staje się **osobnym bytem sprzedażowym** (turnusem)
z własnym zakresem, a w przyszłości własną ceną.

**Zalety:**
- Święto jako produkt daje naturalne miejsce na własną cenę i własny limit — czego reguła nałożona
  na doby nie ma.
- Każdy z dwóch mechanizmów da się opisać bez odwoływania się do drugiego.

**Wady:**
- **Dwa razy ta sama mechanika**: objęcie w całości, przycinanie do dób sprzedawalnych, zachowanie
  przy nachodzeniu, komunikat odmowy. Rozjazd między kopiami jest kwestią czasu i nie zapali się
  nigdzie.
- Nachodzenie weekendu na turnus wymaga **osobnej** reguły pierwszeństwa, bo nie ma wspólnego
  pojęcia, w którym mogłyby się zlać. To dokładnie ta reguła, której opcja A nie musi mieć.
- Turnus jako produkt jest sprzeczny z ustaleniem O2 z wymagań (święto sprzedawane w całości jest
  **regułą nałożoną na doby**, nie osobnym bytem sprzedażowym) i wnosi do schematu rekord, który
  trzymałby sprzedany pobyt — a model rezerwacji jest świadomie poza iteracją.

## Rekomendacja

**Opcja A**, w całości: osobna warstwa pobytu komponująca `PositionAvailability`, spoiwo jako jedna
mechanika o dwóch regułach powtarzania, kolejność „doby → spoiwo → długość → horyzont
i przedsprzedaż" oraz zwolnienie z minimum należące do pakietu zawierającego dobę święta.

Rozstrzyga to, czego opcje B i C nie potrafią:

1. **Opcja B nie wyraża reguły źródłowej** — to nie jest kwestia gustu, tylko wykazany brak.
   Przegląd z 2026-09-20 pokazał kontrprzykład (sama sobota), a obie łatki odrzucono z powodów
   niezależnych od tego zadania.
2. **Opcja C dubluje mechanikę**, a `CLAUDE.md` traktuje drugi literał tej samej reguły jako defekt.
   Dodatkowo wymaga reguły pierwszeństwa przy nachodzeniu, której opcja A nie potrzebuje, bo pakiety
   się zlewają.
3. **Kolejność trzyma się precedensu ADR-012** i tego samego uzasadnienia: odmowa niesie przyczynę
   trwalszą. Nie jest to więc nowa umowa do obrony, a rozszerzenie istniejącej na jedno piętro wyżej.

⚠️ **Rekomendacja świadomie zostawia dwa skutki jako zamierzone, nie jako luki:** zlany pakiet jest
zwolniony z minimum razem z weekendem, który do niego wpadł, a **przycięty** pakiet zachowuje
zwolnienie także wtedy, gdy zostaje z niego jedna doba. Wyjątki od obu byłyby przypadkami
szczególnymi bez właściciela, a pierwszy z nich odwrócony znaczyłby, że dołożenie reguły weekendu
czyni święto niekupowalnym.

⚠️ **Czego rekomendacja NIE rozstrzyga i co zostaje ryzykiem do pomiaru:** kosztu liczenia instancji
pakietów w chwili pytania, gdy kalendarz z zadania 019 zapyta o iloczyn dób i stanowisk. Buforowanie
werdyktu jest wykluczone przez [`dostepnosc.md`](../conventions/dostepnosc.md) §2 bez własnego
uzasadnienia **pomiarowego**, więc jeśli koszt okaże się realny, będzie to osobna decyzja z własnymi
liczbami — nie rozwinięcie tej.

## Decyzja
Decyzja: A

## Aktualizacja (zadanie 018, 2026-09-22)

⚠️ **`StaySellability` przestaje być bezpośrednim wejściem dla cennika, kalendarza i portalu.**
Decyzja A **zostaje w mocy** w całości — kompozycja `PositionAvailability`, spoiwo jako jedna
mechanika, kolejność warunków i przynależność zwolnienia do pakietu nie zmieniają się ani o krok.
Zmienia się wyłącznie **lista wołających**: od zadania 018 dziura w cenniku też jest odmową, więc
na pytanie „czy wolno to sprzedać" odpowiadają dwaj niezależni dostawcy, a składa ich
**warstwa oferty** — [ADR-015](ADR-015-warstwa-oferty-pobytu.md).

Zdanie z opcji A „jest jedynym wejściem dla panelu, portalu, cennika (018) i kalendarza (019)"
czytaj odtąd jako: **jedyne źródło prawdy o sprzedawalności pobytu**. Panel konfiguracyjny nadal
woła tę klasę wprost; kalendarz (019), koszyk i portal wołają warstwę oferty.
