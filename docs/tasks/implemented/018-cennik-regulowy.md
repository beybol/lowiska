# 018 — Cennik regułowy

> ⚠️ **Zadanie zostało PRZEDEFINIOWANE 2026-09-22, po pierwszej implementacji.** Commit
> `08c8d38 "T018 - cennik łowiska"` zostaje w historii, ale opisany w nim model rozstrzygania cennika
> jest **wycofany**. Powody są w sekcji „Historia kształtu" niżej — przeczytaj ją przed implementacją,
> bo tłumaczy, czego świadomie **nie** budujemy i dlaczego.

## Opis problemu

Po zadaniach 015, 014, 016 i 017 system wie, **czym** handluje (doba), **czym** dysponuje
(stanowiska), **kiedy jest wyłączony** (sezony, blokady) i **co wolno kupić** (długość pobytu,
spoiwo dób, horyzont, przedsprzedaż). Nie wie natomiast **ile to kosztuje**.

Ceny są dziś w projekcie wyłącznie tam, gdzie nie rozstrzygają o sprzedaży doby:
`additional_services.price` i `long_term_permits`. **Nie istnieje cena doby na stanowisku** — a bez
niej kalendarz podglądowy (019) nie pokaże „po jakiej cenie", portal nie sprzeda niczego, a operator
nie zobaczy skutku własnej konfiguracji.

Zadanie realizuje moduł **M3** wraz z mechanizmem **G2** (deterministyczne rozstrzyganie)
i częścią **G3** (walidacja konfiguracji przy zapisie) z
[Wymagań konfiguracji sprzedaży krótkoterminowej](../../project/etapy/00-konfiguracja-sprzedazy/WYMAGANIA-SPRZEDAZ-KROTKOTERMINOWA.md).
Jest drugim zadaniem **punktu kontrolnego B**.

**Podział pracy z 017 jest jednokierunkowy: 017 mówi, CO wolno kupić, 018 ILE to kosztuje.** Wycena
nie powtarza warunków sprzedawalności i nie woła `StaySellability`; `StaySellability` nie pyta
o cenę. Składaniem obu odpowiedzi zajmuje się **warstwa oferty** (ADR-015) — ta część pierwszej
implementacji **zostaje bez zmian**.

Makiety:

- **wiążąca** — [`makieta-018-cennik-regulowy-v2.html`](../../project/mockups/makieta-018-cennik-regulowy-v2.html):
  ekran „Cennik" po uproszczeniu (stawka = oś czasu, dopłata = trzy warunki + „dla kogo"),
  rozstrzyganie na korzyść wędkarza, domykanie okresów, rozbicie wyceny i dziura w cenniku.
- [`makieta-018-cennik-regulowy.html`](../../project/mockups/makieta-018-cennik-regulowy.html) —
  ⚠️ **powstała dla modelu WYCOFANEGO i zostaje wyłącznie jako zapis historyczny.** Jej sekcje
  o czterech osiach warunku, priorytetach i szczegółowości **nie obowiązują**. Nie posługuj się nią
  przy implementacji.
- [`makiety-panelu-lowiska-v2.html`](../../project/mockups/makiety-panelu-lowiska-v2.html), sekcja
  **M3** — pomocnicza, powstała przed 014–017, nie zna pojęcia pobytu ani spoiwa dób.

---

## Historia kształtu — dlaczego model został uproszczony

Sekcja wymagana przez autora zadania; streszcza rozmowę z 2026-09-22, w której pierwotny model
upadł. Bez niej za pół roku nie da się odróżnić świadomego uproszczenia od niedoróbki.

### Co zbudowano najpierw

Cennik jako **lista reguł z czterema osiami warunku** (dni tygodnia, zakres dat, liczba łowiących,
rola uczestnika) plus **jawny priorytet**, a przy jego remisie — rozstrzyganie po **szczegółowości**
(liczbie wypełnionych osi). Nierozstrzygalny remis był **błędem zapisu**. Do tego **dwa niezależne
wymiary czasu** na każdej regule: `effective_*` („czy ten zapis bierze dziś udział w wycenie")
oraz `first_day_on`/`last_day_on` („których dób reguła dotyczy"). Uzasadnienie:
[ADR-014](../../adr/ADR-014-cennik-jako-lista-regul-z-warunkami.md).

### Co się posypało

**Formularz okazał się nie do opanowania.** Zgłoszenie autora: sekcja z polami „Pierwsza doba",
„Ostatnia doba", „Tylko przy dokładnie tylu łowiących", „Tylko dla tej roli" jest nieczytelna i nie
wiadomo, po co jest. Najmocniejszy dowód, że to nie kwestia wyglądu, siedzi w samej makiecie: żeby
cztery osie dały się zrozumieć, musiała je **numerować** („oś 1 z 4"), wyświetlać licznik
**„Szczegółowość 0 z 4 osi"** i oznaczyć dwa pola etykietą **„pułapka"**. Makieta nie zaprzeczała
modelowi — ona udokumentowała, jak trudno go wytłumaczyć.

### Sprawdzenie modelu wobec realnych cenników

Rozstrzygnęły dane z wymagań (**O3** i tabela referencyjna), nie preferencja:

| | Łopienno | Klasztorne |
|---|---|---|
| stawka bazowa | 70 zł/os/dobę | 130 zł/os/dobę |
| osoba towarzysząca | 0 zł | 0 zł |
| dopłata „stanowisko tylko dla Ciebie" | +20 zł | +40 zł (**zawieszona** od 2026) |
| warunek dopłaty | **obsada 1** i dni **czw–nd** | **obsada 1** i daty **26.04–30.11** |

Wynikły z tego cztery ustalenia, każde poparte zapisem w wymaganiach:

1. ⚠️ **Priorytety, szczegółowość i remisy nie służą ŻADNEMU realnemu przypadkowi.** O3 mówi wprost:
   cennik obu łowisk to **jedna stawka bazowa plus warunkowa dopłata**, a nie zestaw konkurujących
   stawek. Cała maszyneria rozstrzygania istniała, bo stawki *mogą* się nakładać — a u obu klientów
   jest **jedna**. Makieta zdradza, dla kogo to zbudowano: *„warunki są przygotowane na **trzecie
   łowisko** z ceną »pt–sb drożej«"*. Czyli pod klienta, którego nie mamy.
2. ⚠️ **Rola jako oś warunku była nadmiarowa i niosła najgorszą pułapkę w module.** Osoba
   towarzysząca jest **darmowa** u obu łowisk („brak opłaty w cenniku", O15). Modelowanie jej jako
   konkurencyjnej reguły stawki wymuszało zasadę „stawka towarzyszącej musi mieć najwyższy priorytet
   w cenniku", której model nie umiał wyegzekwować — broniło jej wyłącznie ostrzeżenie. Jako
   **kolumna na stawce** znika i zasada, i pułapka, i ostrzeżenie.
3. ⚠️ **Dni tygodnia na STAWCE nie mają zastosowania.** O4 stwierdza to wprost: dzień tygodnia
   i zakres dat *„nie zmieniają ceny bazowej — one warunkują dopłatę"*, a w Łopiennie cena bazowa
   jest **identyczna przez cały tydzień**. Kto chce różnicować cenę dniami, robi to **dopłatą** —
   i wtedy widzi to jako dopłatę, czyli jako to, czym to jest. Stawka zostaje czystą osią czasu.
4. ⚠️ **Drugi wymiar czasu rozwiązywał problem, którego jeszcze nie ma.** Różnica między „kiedy
   zapis działa" a „których dób dotyczy" ujawnia się dopiero wtedy, gdy istnieje **utrwalona
   transakcja** pamiętająca cenę z chwili zakupu. Rezerwacji nie ma, snapshot G1 jest poza iteracją,
   więc różnica jest dziś **nieobserwowalna** — a kosztowała dwa zestawy dat na formularzu.

### Co zostaje, a co wypada

| Element | Los | Powód |
|---|---|---|
| `priority`, szczegółowość, remis jako błąd zapisu | **wypada** | zero realnych przypadków (O3); nachodzenie rozstrzyga się na korzyść wędkarza |
| oś `participant_role` **na stawce** | **wypada** | zastąpiona kolumną „kwota za osobę towarzyszącą" na stawce |
| „dla kogo" **na dopłacie** (`applies_to`) | **nowość** | dopłat nie da się ograniczyć do samych łowiących bez tego pola, a dopłata naliczona darmowej towarzyszącej zawyża rachunek u obu klientów |
| `effective_from`/`effective_to` jako drugi wymiar czasu | **wypada** | jeden przedział dat o jednym znaczeniu: **których dób dotyczy reguła** |
| oś dni tygodnia **na stawce** | **wypada** | O4: cena bazowa nie zależy od dnia; różnicowanie dniami robi się dopłatą |
| oś dni tygodnia **na dopłacie** | **zostaje** | Łopienno warunkuje dopłatę dniami czw–nd |
| oś zakresu dat | **zostaje** na obu rodzajach | Klasztorne warunkuje dopłatę datami 26.04–30.11; stawka potrzebuje **daty początku** na nowy cennik. ⚠️ Stawką z datą **końca** ceny się nie podnosi — patrz „Automatyczne domykanie" |
| warunek obsady | **zostaje, ale TYLKO na dopłacie** | bez niego nie da się odwzorować **żadnego** z dwóch łowisk — patrz niżej |
| wycena, rozbicie, grosze, obniżka przedsprzedażowa | **bez zmian** | sposób rozstrzygania ich nie dotyczy |
| warstwa oferty (ADR-015), najkrótszy kupowalny pobyt | **bez zmian** | nie obchodzi jej, **jak** cennik wybiera stawkę |
| dziura w cenniku jako odmowa | **zostaje** | tylko się upraszcza (znika przyczyna „remis") |

Efekt netto: **stawka ma pięć pól, dopłata osiem**, a jedyna reguła pierwszeństwa brzmi „wygrywa
tańsza". Poprzedni model miał na stawce dziewięć pól i trzy poziomy rozstrzygania. Złożoność nie
zniknęła — **przeniosła się ze stawki na dopłatę**, czyli z reguły, która ustala cenę bazową, na
regułę, która tylko dodaje. To jest ta zamiana, o którą chodziło: pomyłka w dopłacie zawyża
rachunek o znaną kwotę, pomyłka w stawce podmieniała cenę bazową bez śladu.

### Dlaczego warunek obsady musiał zostać

Był jedyną rzeczą, której uproszczenie nie pokrywało — i nie jest to szczegół. O3 nazywa tę dopłatę
wprost: *dopłata za to, że stanowisko zajmuje **jedna osoba zamiast dwóch***. Bez tego warunku:

- **Łopienno**, czwartek, dwóch wędkarzy: 90 + 90 = **180 zł** zamiast 70 + 70 = **140 zł**;
- **Klasztorne 2025**, maj, dwóch wędkarzy: 170 + 170 = **340 zł** zamiast **260 zł**.

Czyli kasowalibyśmy ludziom wyłączność, której nie mają — u **obu** klientów. Dlatego dopłata
zachowuje jedno opcjonalne pole „tylko gdy łowi dokładnie N osób" i jest to **cała** złożoność
warunkowa, jaka w module zostaje.

### Dlaczego commit zostaje w historii

Cofnięcie `08c8d38` wyrzuciłoby warstwę oferty, wycenę, rozbicie, zaokrąglanie groszy, obniżkę
przedsprzedażową i odmowę „brak ceny" — czyli **większość zadania**, której nowy model w ogóle nie
rusza. Wycofaniu podlega sposób **rozstrzygania**, nie moduł.

---

## Wymagania

### Tabela `price_rules` — po przedefiniowaniu

| Kolumna | Typ | Uwagi |
|---|---|---|
| `id` | `id()` | |
| `fishery_id` | `foreignId`, **wymagany**, `cascadeOnDelete()` | jak `sale_periods`, `whole_term_periods` |
| `kind` | `string` (enum `PriceRuleKind`: `rate`, `surcharge`) | `rate` **zastępuje** stawkę, `surcharge` **dodaje się** |
| `label` | `string` nullable | opis widziany przez wędkarza („Stanowisko tylko dla Ciebie"); jednojęzyczny (D8) |
| `amount` | `decimal(8,2)` | stawka: **kwota za osobę łowiącą za dobę**; dopłata: kwota dopłaty |
| `amount_companion` | `decimal(8,2)` nullable | **tylko dla `rate`**: kwota za osobę towarzyszącą za dobę. U obu łowisk `0.00`. ⚠️ Znaczenie `null` — patrz niżej |
| `is_suspended` | `boolean`, domyślnie `false` | zawieszenie bez usuwania — Klasztorne robi to dziś z dopłatą |
| `first_day_on` | `date` nullable | od której doby reguła obowiązuje; `null` = bez dolnej granicy |
| `last_day_on` | `date` nullable | do której doby; `null` = **bezterminowo**, co przy stawce ma dodatkowe znaczenie — patrz „Automatyczne domykanie" |
| `weekdays` | `json` nullable | **tylko dla `surcharge`**: dni ISO 1–7 **rozpoczęcia doby**; `null`/pusty = każda doba |
| `anglers_count` | `unsignedTinyInteger` nullable | **tylko dla `surcharge`**: dopłata należy się wyłącznie przy dokładnie tylu łowiących. Porównanie przez RÓWNOŚĆ z faktyczną obsadą z zapytania, **nigdy** z `positions.max_anglers` (tamto to pojemność i kusi wyłącznie nazwą). ⚠️ **Obsadę liczą sami łowiący** — osoba towarzysząca jej nie podnosi |
| `applies_to` | `string` **nullable** (enum `SurchargeAudience`: `everyone`, `angler`, `companion`), **bez domyślnej wartości w bazie** | **tylko dla `surcharge`**: komu dopłata jest naliczana. `null` na stawce. Domyślna wartość żyje w **formularzu** i wynosi `angler` — patrz niżej |
| | `softDeletes()`, `timestamps()` | |
| — | `index(['fishery_id','kind'])` | |

**Kolumny usunięte wobec commita `08c8d38`:** `priority`, `participant_role`, `effective_from`,
`effective_to`. **Dodane:** `amount_companion`, `applies_to`.

⚠️ **`weekdays`, `anglers_count` i `applies_to` zostają w schemacie, ale są polami DOPŁATY.** Kolumny
są wspólne, bo rodzaj reguły jest flagą na jednej tabeli (O3) — formularz stawki ich nie pokazuje,
a wycena stawki ich nie czyta. Przy zapisie stawki idą na `null`, żeby nie zostawiać w bazie danych,
których nikt nie interpretuje.

⚠️ **`applies_to` to NIE jest powrót osi `participant_role`.** Tamta odpowiadała na pytanie „**która
stawka** obowiązuje tego uczestnika" i konkurowała z innymi regułami — stąd priorytety i pułapka.
Ta odpowiada na „**komu** nalicza się ta dopłata" i z niczym nie konkuruje. Różnica jest widoczna
w skutku: `participant_role` mogło cicho zmienić **cenę bazową**, `applies_to` może tylko dodać albo
nie dodać dopłatę. Dlatego nowy enum, a nie odzyskany `ParticipantRole`: ten drugi nie ma i nie
powinien mieć wariantu „każdy".

⚠️ **Domyślną wartością jest `angler` („dla łowiącego"), NIE `everyone`** — i to jest decyzja
o kosztach pomyłki, nie o wygodzie. Przy domyślnym „dla każdego" dopłata dodana bez zastanowienia
obciążyłaby **darmową osobę towarzyszącą**: w Łopiennie 110,00 zł zamiast 90,00 zł. To dokładnie
ta pomyłka, którą poprzednia redakcja zadania nazywała najtrudniejszą do zauważenia w całym
cenniku — wróciłaby tylnymi drzwiami przez wartość domyślną. Oba realne łowiska ustawiają `angler`,
a model ma się mylić w stronę **tańszą i mniej zaskakującą**. Podpowiedź pod polem tłumaczy, kiedy
sięgnąć po „dla każdego"; ostrzeżenie o tej pułapce przestaje być potrzebne.

⚠️ **`amount_companion = null` na stawce to BRAK CENY, nie cena zerowa** — czyli dokładnie to samo,
czym jest brak pasującej stawki. Zapytanie z osobą towarzyszącą o dobę, której wygrana stawka nie ma
ceny towarzyszącej, dostaje odmowę `NoCompanionPrice`. Formularz czyni to pole **wymaganym dla stawki**
z domyślnym `0,00`, więc `null` da się osiągnąć wyłącznie zapisem poza panelem. Alternatywa
„`null` = za darmo" została odrzucona: darmowa towarzysząca to `0,00` wpisane świadomie, a nie
puste pole, którego operator nie zauważył.

⚠️ **`first_day_on`/`last_day_on` to DNI ROZPOCZĘCIA DÓB**, identycznie jak w `whole_term_periods`
(017). Nazw `starts_on`/`ends_on` **nie wolno tu użyć** — w `sale_periods` znaczą co innego
(`ends_on` to ostatni dzień okna, a ostatnia sprzedawalna doba zaczyna się dzień wcześniej),
[`dostepnosc.md`](../../conventions/dostepnosc.md) §4. Etykieta dla operatora brzmi „Obowiązuje od /
do", bo tak on o tym myśli.

⚠️ **Jest tylko JEDEN wymiar czasu.** `first_day_on`/`last_day_on` odpowiadają na pytanie **których
dób dotyczy ta reguła**, a nie „kiedy ten zapis staje się aktywny". Nowy cennik od 1 lipca to nowa
stawka z `first_day_on = 1.07`: obowiązuje każdego, kto kupuje doby od tej daty, i działa **od razu
po zapisie**. Funkcji aktywowania cennika z wyprzedzeniem świadomie **nie ma**.

### Rozstrzyganie — na korzyść wędkarza, bez priorytetów

Dla jednej doby:

0. ⚠️ **bierz pod uwagę wyłącznie reguły nieusunięte** — miękko skasowana reguła nie wraca do
   wyceny. Załatwia to globalny zakres `SoftDeletes`, ale przy wyborze „najniższa kwota" warto to
   mieć napisane: cicha pomyłka dałaby cenę z kosza, i to **korzystniejszą**, więc nie rzucającą
   się w oczy;
1. odrzuć reguły **zawieszone**;
2. odrzuć te, których warunek nie jest spełniony **dla tej doby**:
   - **stawka** — wyłącznie zakres dat;
   - **dopłata** — zakres dat, dni tygodnia, obsada;
3. spośród pozostałych `rate` wygrywa **najkorzystniejsza dla wędkarza**, czyli o **najniższej
   kwocie za osobę łowiącą** (`amount`). Wygrana stawka wnosi też swoją `amount_companion` —
   jedna stawka na dobę, bez sklejania kwot z dwóch reguł. Jeśli **żadna** stawka nie pasuje →
   odmowa `NoPriceDefined`; jeśli stawka pasuje, ale ma `amount_companion = null`, a w zapytaniu
   jest osoba towarzysząca → odmowa **`NoCompanionPrice`**;
4. **wszystkie** pasujące `surcharge` **sumują się**, każda naliczona **temu, kogo wskazuje jej
   `applies_to`** — każdemu, samym łowiącym albo samym osobom towarzyszącym.

⚠️ **Nachodzenie się stawek NIE jest błędem zapisu i nie ma priorytetów.** Po zdjęciu dni tygodnia
stawki mogą się nakładać już tylko **datami** — i wtedy wycena wybiera wariant korzystniejszy dla
wędkarza, a **kalendarz podglądowy (019) ma to pokazać i oznaczyć**. To świadome przeniesienie
odpowiedzialności z walidacji zapisu na widok skutku.

⚠️ **Determinizm przy równych kwotach:** gdy dwie stawki mają tę samą `amount`, rozstrzyga niższa
`amount_companion`, a przy pełnej równości — mniejsze `id`. Wynik jest wtedy i tak identyczny
kwotowo; chodzi wyłącznie o to, żeby rozbicie wskazywało zawsze tę samą regułę.
**Stawka z `amount_companion = null` przegrywa remis zawsze — sortuje się na koniec**, żeby wybór
nie padł na regułę, która odmówi wyceny osobie towarzyszącej, gdy obok stoi równoważna kwotowo
stawka z wypełnioną ceną.

⚠️ **Warunek dopłaty sprawdza się osobno dla KAŻDEJ doby** (K3/P2), nie „całe albo wcale": pobyt
śr–pt przy dopłacie „czw–nd" dostaje ją za czwartek i piątek, a nie za środę i nie za cały pobyt.

### Automatyczne domykanie okresów stawki

⚠️ **Rozstrzyganie „na korzyść wędkarza" NIE zastępuje domykania — bez niego każda podwyżka cicho
przestałaby działać.** Stara stawka 70 zł bezterminowo i nowa 80 zł od 2027 nachodzą na siebie, więc
bez domknięcia wygrałaby tańsza i podwyżka nigdy by nie weszła. Oba mechanizmy mają rozłączne
zadania: domykanie pilnuje **osi czasu**, wybór na korzyść wędkarza — reszty.

**Reguła:** przy **utworzeniu** stawki, która ma `first_day_on = D` **i jest sama bezterminowa**
(`last_day_on = null`), każda **inna stawka tego łowiska** z `last_day_on = null`, zaczynająca się
nie później niż `D`, dostaje `last_day_on = D − 1 dzień`.

⚠️ **Warunek „nowa stawka sama jest bezterminowa" jest KONIECZNY — bez niego reguła psuje cennik.**
Wersja bez niego (pierwsza redakcja tego zadania) przy dodaniu promocji „90 zł na 01.07–31.08"
domknęłaby bezterminowe 70 zł na 30.06, a od 01.09 nie zostałaby **żadna** stawka. Operator
dostałby dziurę w cenniku na resztę sezonu za to, że dodał okno. Rozróżnienie jest proste i daje
się napisać na ekranie jednym zdaniem:

| Kształt nowej stawki | Znaczenie | Skutek |
|---|---|---|
| `first_day_on = D`, `last_day_on` pusty | **nowy cennik od D** | domyka poprzednią bezterminową na `D − 1`; podwyżka działa |
| `first_day_on` i `last_day_on` wypełnione | **okno nakładkowe** | nie domyka niczego; w części wspólnej wygrywa tańsza |

⚠️ **Konsekwencja: stawką z datą końca można cenę tylko OBNIŻYĆ.** Promocja „50 zł w maju"
zadziała; „90 zł w lipcu" nigdy nie wygra z bezterminowymi 70 zł, bo rozstrzyganie wybiera tańszą.
To jest zamierzone i spójne z regułą „różnicowanie w górę robi się dopłatą".

⚠️ **Panel tego nie sygnalizuje przy zapisie — pokazuje to kalendarz podglądowy (019).** Ostrzeżenie
w formularzu byłoby drugim domem dla wiedzy, którą kalendarz i tak musi pokazać: to on odpowiada za
uwidocznienie nachodzenia stawek i za to, co naprawdę wychodzi w cenie danej doby. Operator zobaczy
tam, że lipiec nadal kosztuje 70 zł, czyli **fakt**, a nie domysł formularza o intencji.

Pozostałe własności:

- ⚠️ **Reguła nie ma żadnego warunku poza datami** — to zysk ze zdjęcia dni tygodnia ze stawki.
  Wcześniej trzeba by rozstrzygać, czy „70 zł zawsze" domyka „80 zł od 2027 w weekendy", i co
  z dniami roboczymi 2027. Ten przypadek brzegowy przestał istnieć.
- ⚠️ **Tylko przy UTWORZENIU, nigdy przy edycji.** Domknięcie jest **nieodwracalne**: domknięta
  stawka ma już `last_day_on`, więc cofnięcie daty na nowej stawce nie przywraca poprzedniej i
  zostawia dziurę. Przy edycji istniejącej stawki operator świadomie rusza rekord, który już żyje —
  automat mutujący wtedy sąsiadów jest bardziej zaskakujący niż pomocny. Nachodzenie powstałe przez
  edycję rozstrzyga się na korzyść wędkarza i **widać je na kalendarzu (019)**.
- **Powiadomienie jest obowiązkowe** — z nazwą domkniętej reguły i nową datą. Cicha zmiana cudzego
  wpisu jest gorsza niż brak automatu.
- **Domknięcie idzie w TEJ SAMEJ transakcji co zapis** i trafia do dziennika zmian jak każda inna
  modyfikacja rekordu ([`dziennik-zmian.md`](../../conventions/dziennik-zmian.md)) — modyfikujemy wpis,
  którego operator w tym formularzu nie dotknął.
- ⚠️ **Gdy jeden zapis repeatera wnosi kilka nowych stawek bezterminowych, przetwarzaj je
  w kolejności rosnącego `first_day_on`.** Ekran jest jednym repeaterem zapisywanym w całości, więc
  bez ustalonej kolejności wynik zależałby od kolejności wierszy w formularzu.
- Dotyczy **wyłącznie stawek**. Dopłaty mogą się nakładać dowolnie i nie są domykane.
- ⚠️ **Stawki z ustawioną datą końca nie są domykane**, więc rozłączne okresy przeżywają: cennik
  Klasztornego 2025 miał dwa rozłączne okresy po 130 zł (O5) i dodanie drugiego nie może uciąć
  pierwszego.

### Diagnostyka cennika dla podglądu — 018 wystawia, 019 rysuje

Po zdjęciu priorytetów nachodzenie stawek przestało być błędem zapisu, a ostrzeżenie w formularzu
zostało świadomie usunięte. Odpowiedzialność za uwidocznienie nachodzenia niesie kalendarz (019) —
ale **kalendarz nie ma prawa dopasowywać stawek sam**: po uproszczeniu jest to wprawdzie samo
porównanie dat, lecz nadal logika cennika, a ta ma jeden dom ([ADR-014](../../adr/ADR-014-cennik-jako-lista-regul-z-warunkami.md)).
Dlatego cennik wystawia **diagnostykę**: dwie odpowiedzi, których wycena sama w sobie nie potrzebuje.

| Pytanie | Kto odpowiada | Kształt odpowiedzi |
|---|---|---|
| **Ile stawek pasuje do tej doby i po ile są przegrane** | `PriceRuleResolver` | wynik rozstrzygnięcia niesie obok zwycięzcy **komplet kandydatów** (identyfikator, etykieta, `amount`) w kolejności rozstrzygania |
| **Która stawka nie wygrywa w ŻADNEJ dobie swojego okresu** | `PricingConfigurationAudit` | analiza zbioru reguł raz na cennik: arytmetyka przedziałów dat i kwot, bez stanowisk i bez okna |

⚠️ **To zmiana kształtu wyniku, nie nowa logika.** Resolver **już** materializuje zbiór kandydatów,
żeby wybrać najtańszego — dziś po prostu wyrzuca przegranych. Nie dochodzi ani jedna kolumna, ani
jedna migracja, ani jedno pole formularza; zmiana leży poza całą ryzykowną częścią przebudowy.

⚠️ **Diagnostyka jest czytelna i nie zmienia werdyktu.** Wycena wybiera dokładnie tak samo, czy
wołający po nią sięgnie, czy nie. Nie wchodzi też do `StayPriceBreakdown` — rozbicie niesie
zwycięzcę, bo to ono opisuje, za co wędkarz płaci; komplet kandydatów opisuje **stan konfiguracji**
i jest osobnym pytaniem.

⚠️ **Kandydaci zależą wyłącznie od daty** — po uproszczeniu stawka nie zna ani stanowiska, ani
składu uczestników. Kalendarz pobiera więc diagnostykę **raz na łowisko i okno** (30 dób), a nie raz
na komórkę (30 dób × liczba stanowisk). To jest różnica między trzydziestoma a ośmiuset wywołaniami
przy Klasztornym i dlatego należy do treści zadania, a nie do optymalizacji przy implementacji.

⚠️ **Martwa stawka to analiza zbioru, nie renderu.** Liczy się ją raz na cennik; `PricingConfigurationAudit`
bada już dziurę w cenniku i jest naturalnym domem także dla tego pytania. ⚠️ Ostrzeżenia w formularzu
z tego **nie robimy** — pokazuje to kalendarz, żeby wiedza o nachodzeniu miała jedno miejsce.

### Wycena pobytu — bez zmian wobec pierwszej implementacji

Wycena ma jeden dom w `app/Services/`. Odpowiada na pytanie: **ile kosztuje ten pobyt i z czego się
to składa.** Pyta się o pobyt (stanowisko, doba rozpoczęcia, liczba dób) i **skład uczestników**
(ilu łowiących, ile osób towarzyszących) — uczestnik jest **parametrem zapytania**, nie rekordem.

⚠️ **Skład wymaga CO NAJMNIEJ JEDNEGO ŁOWIĄCEGO** i pilnuje tego obiekt zapytania, odrzucając zero
`InvalidArgumentException`-em. Bez tej bramki zapytanie „0 łowiących, 1 osoba towarzysząca"
przechodzi przez cały model bez zgrzytu: warunek obsady nie wchodzi, stawka wnosi
`amount_companion = 0,00` i wychodzi **oferta za 0,00 zł**. To niepoprawne wejście, nie odmowa
biznesowa, więc nie dostaje własnego `SaleUnavailabilityReason` — samo pojęcie pobytu bez wędkarza
nie istnieje.

Wynikiem jest **struktura rozbicia**: pozycje per doba i per rola z kwotą, etykietą i wskazaniem
reguły, plus suma. ⚠️ **To nie jest snapshot G1** — patrz „Zakres wyłączeń".

**Naliczanie dopłaty: za każdą dobę i za każdą osobę wskazaną przez `applies_to`** — `everyone`
liczy wszystkich uczestników, `angler` samych łowiących, `companion` same osoby towarzyszące.
Warunek obsady (`anglers_count`) decyduje, **czy** dopłata w ogóle wchodzi; `applies_to` decyduje,
**przez ilu** się ją mnoży. Oba są opcjonalne i niezależne.

⚠️ **Łopienno i Klasztorne ustawiają `angler`.** Dopłata za wyłączność należy się za to, że
stanowisko zajmuje jedna osoba łowiąca — naliczenie jej także darmowej osobie towarzyszącej dałoby
w Łopiennie 20 + 20 = 40 zł za wyłączność, której obecność towarzysza właśnie zaprzecza.

### Dziura w cenniku jest ODMOWĄ, nie ceną zerową

Doba w otwartym sezonie, do której **nie pasuje żadna reguła `rate`**, jest niesprzedawalna
z jawnym powodem (K2, G3) — `SaleUnavailabilityReason::NoPriceDefined`. Bez zmian wobec pierwszej
implementacji, poza tym, że **znika przyczyna „remis nierozstrzygalny"**: wycena nie ma już jak nie
umieć wybrać stawki.

⚠️ **Brak ceny dla osoby towarzyszącej dostaje WŁASNY powód: `SaleUnavailabilityReason::NoCompanionPrice`.**
Jedna wartość na oba przypadki wyglądałaby na oszczędność, a byłaby defektem — **te dwie odmowy
prowadzą operatora w przeciwne strony**: przy `NoPriceDefined` trzeba **dopisać stawkę**, przy
`NoCompanionPrice` **poprawić jedno pole w stawce, która już jest**. Kalendarz (019) pokazuje powód
odmowy wprost, więc zlanie ich w jedno posłałoby operatora szukać nieistniejącej dziury w cenniku.
Dochodzi gałąź w `label()` i klucz w `lang/pl.json`.

⚠️ **Zakres sprawdzenia przy zapisie UPRASZCZA SIĘ i to nie jest kosmetyka.** Dopasowanie stawki
zależy teraz **wyłącznie od daty** — obsada i rola zeszły ze stawki — więc iterowanie po obsadach
`1…max_anglers` i po rolach nie może już znaleźć nic, czego nie znajdzie sama iteracja po dobach.
Dopłaty dziur nie tworzą, bo tylko dodają. Sprawdzenie przebiega więc **po dobach okresów sprzedaży
od dziś do końca ostatniego okresu** i tyle; znikają zarówno pętla po obsadach, jak i reguła
„stanowisko bez pojemności liczy się jako jeden łowiący", i znika obsada z treści komunikatu.

⚠️ Sprawdzenie nadal widzi **dzisiejszy stan cennika** i mówi o tym wprost; gwarancją jest odmowa
przy sprzedaży, nie ostrzeżenie przy zapisie.

⚠️ **Po zdjęciu dni tygodnia ze stawki dziura może mieć już tylko przyczynę datową** — komunikat ma
to odzwierciedlać. Znika zdanie o „regule wygasającej później" (wskazywało na nieistniejący wymiar
`effective_*`), a dzień tygodnia w komunikacie („piątek, 25.09.2026") zostaje jako sposób nazwania
doby, nie jako podpowiedź przyczyny.

### Warstwa oferty — bez zmian (ADR-015)

Cała ta część pierwszej implementacji **zostaje nietknięta**: kolejność „najpierw sprzedawalność,
potem cena", odmowa z jednym powodem, wskazanie doby i obsady przy braku ceny oraz
`shortestOffer()` (najkrótszy kupowalny pobyt dla widoku „ceny od" w 019). Warstwy niżej pozostają
niezależne; tylko warstwa oferty zna obie.

### Procentowa obniżka w przedsprzedaży — bez zmian

`sale_periods.presale_discount_percent`, pole w bloku przedsprzedaży na ekranie „Sprzedaż i sezony",
liczone **per doba** od sumy pozycji tej doby, zaokrąglane **raz na dobę, połówki w górę**.
Migracja `add_presale_discount_to_sale_periods_table` przechodzi przez przedefiniowanie **bez
zmiany treści** — zmienia się tylko to, że trzeba ją cofnąć i wykonać ponownie razem z sąsiadką.

### Panel — ekran „Cennik"

| Element | Kształt |
|---|---|
| strona | strona zasobu `FisheryResource`, za „Reguły sprzedaży", przed „Stanowiska" — bez zmian |
| **stawki** | `Repeater`, **pięć pól**: kwota za łowiącego, **kwota za osobę towarzyszącą** (wymagana, domyślnie `0,00`), obowiązuje od, obowiązuje do, zawieszenie |
| **dopłaty** | `Repeater`, **osiem pól**: kwota, opis dla wędkarza, **dla kogo**, obowiązuje od, obowiązuje do, dni tygodnia, **tylko przy obsadzie N**, zawieszenie |
| pole „dla kogo" | `Select` z trzema wariantami (`Dla łowiącego` / `Dla każdego` / `Dla osoby towarzyszącej`), domyślnie **Dla łowiącego**, **bez opcji pustej**. Podpowiedź: *„Dla każdego" obciąży także osoby towarzyszące — wybierz to dla dopłat za coś, z czego korzysta każdy (prąd, altana, parking)* |
| pole kwoty | `SharedFormComponents::getPriceInput()` z minimum **0,00** (towarzysząca bywa darmowa) |
| nagłówek wiersza | streszczenie wzorem makiety: stawka `70,00 zł · od 01.01.2026` albo `70,00 zł · 01.05–31.05 · okno`, dopłata `+20,00 zł · dla łowiącego · obsada 1 · czw–nd`, a przy zawieszonej — `· zawieszona` |
| opisy pól | ⚠️ **każde pole warunku dopłaty dostaje jednozdaniowe wyjaśnienie** („Dni rozpoczęcia doby. Pusty wybór = każda doba"). To wprost naprawa zgłoszenia o nieczytelności — samo usunięcie osi go nie zamyka |
| podpowiedź przy dopłatach | jedno zdanie tłumaczące, **po co** są dopłaty: to nimi różnicuje się cenę dniami tygodnia, bo stawka tego nie potrafi |
| błędy zapisu | `last_day_on` przed `first_day_on`; obniżka spoza 0–100 |
| ostrzeżenia | **dziura w cenniku**; **domknięcie okresu** poprzedniej stawki. ⚠️ Tyle i nic więcej — skutki nachodzenia stawek pokazuje kalendarz (019), nie formularz |
| co ZNIKA z ekranu | z całego ekranu: `priorytet`, `rola uczestnika`, drugi zakres dat, ostrzeżenie o stawce przebijającej stawkę towarzyszącej; ze **stawki** dodatkowo: dni tygodnia, obsada |

⚠️ **`PriceRule` nadal NIE dostaje zasobu Filamenta, polityki ani uprawnień Shielda** — jak
`SalePeriod` (015) i `WholeTermPeriod` (017); dostępu pilnuje `FisheryPolicy`
([`autoryzacja.md`](../../conventions/autoryzacja.md) §5).

⚠️ **Reguła o `getRedirectUrl()` z `CLAUDE.md` tej strony nie dotyczy** — to strona ustawień, nie
standardowy CRUD z osobnym `Create*`.

### Przebudowa migracji i rollback bazy — jawny krok implementacji

⚠️ **Migracje 018 są zacommitowane i WYKONANE na bazie roboczej**, a przedefiniowanie zmienia
schemat. Kolejność jest następująca i **wymaga zgody autora na operację na bazie** (zasada
z `CLAUDE.md`, „Bezpieczeństwo bazy danych"):

1. **Zapytaj o zgodę.** Operacja dotyka bazy `lowiska`, nie tylko testowej.
2. `docker compose exec app php artisan migrate:rollback --step=2` — cofa
   `2026_09_24_100100_add_presale_discount_to_sale_periods_table`
   i `2026_09_24_100000_create_price_rules_table`.
   ⚠️ **`--step=2`, nie `migrate:fresh`.** Fresh skasowałby łowiska, stanowiska, okresy sprzedaży
   i reguły pobytu z zadań 014–017.
3. **Przepisz pliki migracji w miejscu** — `create_price_rules_table` dostaje docelowy kształt od
   razu; `add_presale_discount_to_sale_periods_table` bez zmiany treści.
4. `docker compose exec app php artisan migrate` i sprawdzenie, że dane łowisk, stanowisk, okresów
   sprzedaży i reguł pobytu przetrwały.

**Dlaczego w miejscu, a nie migracją poprawiającą:** 018 nie jest wydane (punkt kontrolny B nie jest
zielony, zadanie nie jest w `docs/tasks/implemented/`), a `price_rules` nie ma danych produkcyjnych.
Migracja poprawiająca utrwaliłaby w historii schematu cztery kolumny, które nigdy nie miały
uzasadnienia, i kazałaby każdemu nowemu środowisku je zakładać po to, żeby zaraz skasować.

✅ **Sprawdzone 2026-09-22: `08c8d38` NIE jest wypchnięty na `origin/dev`** (razem z `78a2f98`
z zadania 017), więc wdrożenie nigdy nie wykonało tych migracji. **Rollback dotyczy wyłącznie
lokalnej bazy roboczej `lowiska`** — żadne środowisko zdalne nie wymaga interwencji.

⚠️ **To przestaje być prawdą w chwili pierwszego `git push`.** Gdyby commit trafił na `dev` przed
przebudową, staging wymagałby tej samej operacji: przepisana migracja o **tej samej nazwie** jest
tam odnotowana jako wykonana i nie uruchomi się drugi raz, więc kolumny zostałyby w starym
kształcie. **Do czasu przebudowy nie wypychaj `dev`.**

### Walidacja — dom w `app/Rules/`

Zostaje: porządek `first_day_on`/`last_day_on`, zakres procentu obniżki (0–100).
**Wypada w całości:** `PriceRulesDoNotTie` i `PriceRuleOverlap` wraz z testami — nie ma remisów do
wykrywania.

### Tłumaczenia

Etykiety i opisy nowych pól, usunięcie kluczy po polach, które znikają (w tym po dniach tygodnia na
stawce), komunikat o domknięciu okresu. Klucze w `lang/pl.json` — jak dotąd.

---

## Mapa plików — co ruszamy, a czego NIE

Commit `08c8d38` wniósł **47 plików**; do tego wiszą niezacommitowane poprawki z 2026-09-22.
Bez tego podziału implementacja poszłaby po omacku, a najdroższy błąd w tym zadaniu to
**skasowanie czegoś, co przeżyło przedefiniowanie**. Lista jest wiążąca: plik spoza koszyka 1 i 2
zostaje nietknięty.

### Koszyk 1 — DO USUNIĘCIA

| Plik | Uwaga |
|---|---|
| `app/Rules/PriceRulesDoNotTie.php` | ⚠️ **najpierw przenieś `withoutBlankConditions()`** do `ManagePricing` — normalizacja pustego `Select` na `null` musi przeżyć, choć remisy nie |
| `app/Services/PriceRuleOverlap.php` | jedyny konsument to reguła wyżej |
| testy obu powyższych | jeśli mają własne pliki |

### Koszyk 2 — DO PRZEPISANIA

| Plik | Co konkretnie |
|---|---|
| `database/migrations/2026_09_24_100000_create_price_rules_table.php` | docelowy kształt kolumn |
| `app/Models/PriceRule.php` | usuń `specificity()`, rzut `participant_role`, zakresy po `effective_*`; dodaj `amount_companion` |
| `app/Services/PriceRuleResolver.php` | wybór po najniższej kwocie zamiast priorytetu i szczegółowości; wynik niesie **komplet kandydatów**, nie sam zwycięzca (diagnostyka dla 019) |
| `app/Services/NightPriceResolution.php` | ⚠️ **sprawdź, czy niesie wynik „remis"** — jeśli tak, ten wariant znika |
| `app/Enums/PricingFailure.php` | ⚠️ **sprawdź przypadek remisu** — jeśli jest, znika |
| `app/Enums/ParticipantRole.php` | ⚠️ **NIE kasuj.** Przestaje być osią warunku, ale **zostaje etykietą roli w rozbiciu** (pozycje „Łowiący" / „Osoba towarzysząca") |
| **NOWY** `app/Enums/SurchargeAudience.php` | `everyone` / `angler` / `companion` — komu naliczyć dopłatę. ⚠️ **Osobny enum, nie rozszerzony `ParticipantRole`**: „każdy" nie jest rolą uczestnika i nie ma czego etykietować w rozbiciu |
| `app/Enums/SaleUnavailabilityReason.php` | ⚠️ **przesunięty z koszyka „nie ruszamy"**: dochodzi wariant `NoCompanionPrice` + gałąź w `label()`. Sam `NoPriceDefined` zostaje bez zmian |
| `app/Services/PricingConfigurationAudit.php` | wyrzuć `ratesOutrankingCompanionRate()`; zostaw badanie dziury w cenniku; **dołóż wskazanie martwych stawek** (nie wygrywających w żadnej dobie swojego okresu) |
| `app/Services/StayPricing.php` | cena towarzyszącej z kolumny wygranej stawki, nie z osobnej reguły; dopłata mnożona przez liczbę osób z `applies_to` |
| `app/Filament/.../Pages/ManagePricing.php` | dwa repeatery o nowym kształcie (5 i 8 pól) + przeniesiona normalizacja + powiadomienie o domknięciu okresu |
| `database/factories/PriceRuleFactory.php` | stany po nowych kolumnach |
| `lang/pl.json` | ⚠️ klucze usuwaj **po sprawdzeniu, że nikt ich nie używa** |
| `tests/Feature/PriceRuleResolutionTest.php` | priorytet/szczegółowość/remis → korzyść wędkarza + domykanie |
| `tests/Feature/PriceRuleTest.php`, `PricingPageTest.php`, `StayPricingTest.php` | nowe kolumny i nowy formularz |
| `docs/conventions/cennik.md`, `MANUAL.md`, `CHANGELOG.md`, `ADR-014` | patrz „Zmiany dokumentacji" |

### Koszyk 3 — NIE RUSZAMY

`StayOffer`, `StayOfferVerdict`, `ShortestStayVerdict`, `StayPriceBreakdown`, `StayPriceItem`,
`StayNightPrice`, `SalePeriodFinder`, `StaySellability`, `SharedFormComponents`,
`PresaleDiscountIsPercentage`, `PriceRuleDatesAreOrdered`, `PriceRuleKind`,
`ManageSaleSettings`, `Fishery`, `SalePeriod`, `FisheryResource`,
migracja `add_presale_discount_to_sale_periods_table`, `ADR-015`, `ADR-013`, `dostepnosc.md`,
`StayFixtures`, `StayOfferTest`, `SaleSettingsPageTest`.

⚠️ **`StayOfferTest` i `SaleSettingsPageTest` mają zostać zielone BEZ jednej zmiany.** Jeśli
któryś zaczerwienieje, znaczy to, że przedefiniowanie wyciekło poza swój zakres — to jest
najczulszy czujnik w tym zadaniu.

### Niezacommitowane poprawki z 2026-09-22 — MUSZĄ przeżyć

Wiszą w `ManagePricing.php`, `PriceRulesDoNotTie.php`, `lang/pl.json`, `PricingPageTest.php`:

1. **Normalizacja pustych warunków** (`withoutBlankConditions()`) — pusty `Select` przysyła pusty
   łańcuch, nie `null`. ⚠️ Powstała w klasie, która **idzie do kasacji** — to najłatwiejsza rzecz do
   zgubienia w całym zadaniu. Kryterium akceptacji o pustym łańcuchu jest jej strażnikiem.
2. **Czytelny komunikat o dziurze w cenniku** z nazwą dnia tygodnia — zostaje, minus zdanie
   o regułach wygasających.

⚠️ **Przed pierwszą edycją zrób punkt odwrotu** — bo te zmiany nie są w żadnym commicie:
`git stash push -m "018 poprawki przed przedefiniowaniem"` albo kopia czterech plików. W tej sesji
zdarzyło się już raz przywrócenie **nieaktualnej** kopii pliku, które po cichu cofnęło dwie reguły
bezpieczeństwa; ratunkiem był test, nie czujność.

---

## Kryteria akceptacji

- [ ] Migracje przechodzą **w obie strony** na bazie zawierającej dane z zadań 014–017; po
      przebudowie w `price_rules` **nie ma** kolumn `priority`, `participant_role`,
      `effective_from`, `effective_to`, a są `amount_companion` i `applies_to` (nullable, bez
      domyślnej wartości w bazie).
- [ ] **Łopienno odwzorowane jedną stawką i jedną dopłatą**: stawka 70,00 zł za łowiącego i 0,00 zł
      za osobę towarzyszącą, bezterminowo; dopłata „Stanowisko tylko dla Ciebie" 20,00 zł, dni
      czw–nd, **tylko przy obsadzie 1**, **dla łowiącego**. Pobyt jednej osoby od czwartku na 5 dób
      daje 90+90+90+90+70 = **430,00 zł**.
- [ ] **Dopłata za wyłączność NIE nalicza się przy dwóch łowiących**: ten sam czwartek w Łopiennie
      dla dwóch wędkarzy kosztuje 70+70 = **140,00 zł**, a nie 180,00 zł. To kryterium, przez które
      model bez warunku obsady został odrzucony.
- [ ] **Dopłata „dla łowiącego" nie dotyka osoby towarzyszącej**: czwartek w Łopiennie dla jednego
      łowiącego z jedną osobą towarzyszącą kosztuje 70 + 0 + 20 = **90,00 zł**, a nie 110,00 zł.
- [ ] **Trzy warianty „dla kogo" dają trzy różne ROZBICIA** na tym samym składzie (1 łowiący
      + 1 towarzysząca, dopłata 20,00 zł): `everyone` → +40,00 zł w dwóch pozycjach;
      `angler` → +20,00 zł w pozycji **łowiącego**; `companion` → +20,00 zł w pozycji **osoby
      towarzyszącej**. ⚠️ Dwa ostatnie dają **tę samą sumę** — asercja idzie po pozycjach rozbicia,
      nie po kwocie łącznej, bo test napisany na trzech różnych sumach nie przejdzie.
- [ ] **Domyślną wartością pola „dla kogo" jest `angler`** — dopłata dodana bez dotknięcia tego pola
      nie obciąża osoby towarzyszącej.
- [ ] **Wycena odrzuca skład bez łowiących**: zapytanie „0 łowiących, 1 osoba towarzysząca" rzuca
      `InvalidArgumentException`, a nie zwraca ofertę za 0,00 zł.
- [ ] **Klasztorne odwzorowane**: stawka 130,00 zł za łowiącego i 0,00 zł za towarzyszącą; dopłata
      40,00 zł z zakresem 26.04–30.11, obsadą 1 i „dla łowiącego", **zawieszona** — nie wchodzi do
      wyceny, ale zostaje w cenniku i włącza się bez wpisywania od nowa.
- [ ] **Osoba towarzysząca jest kolumną, nie regułą**: przy stawce 70,00/0,00 pobyt jednego
      łowiącego z jedną osobą towarzyszącą kosztuje 70,00 zł za dobę. W cenniku **nie ma** osobnej
      reguły dla towarzyszącej ani pojęcia priorytetu.
- [ ] **Stawka nie zna dni tygodnia**: wycena tej samej doby w poniedziałek i w sobotę przy jednej
      stawce daje tę samą kwotę, a formularz stawki nie ma pola dni tygodnia ani obsady.
- [ ] **Różnicowanie ceny dniami robi się dopłatą**: „70 zł bezterminowo" plus dopłata „+20 zł,
      pt–sb" daje piątek 90,00 zł i poniedziałek 70,00 zł — to jest zamiennik wycofanej osi dni na
      stawce i ma własny test.
- [ ] **Warunek dopłaty liczony per doba** (K3/P2): pobyt śr–pt przy dopłacie „czw–nd" nalicza ją za
      czwartek i piątek, nie za środę i nie za cały pobyt.
- [ ] **Nachodzące stawki rozstrzygają się na korzyść wędkarza**: „70 zł od 01.01.2026 bezterminowo"
      i „50 zł na 01.05–31.05.2026" dają w maju **50,00 zł**, w czerwcu **70,00 zł**, a zapis
      **przechodzi bez błędu**. Rozbicie wskazuje, która stawka wygrała.
- [ ] ⚠️ **Stawka-okno NIE domyka stawki bezterminowej** — najważniejsze kryterium tej redakcji.
      Po dodaniu „90 zł na 01.07–31.08.2026" stawka „70 zł od 01.01.2026" ma nadal
      `last_day_on = null`, lipiec kosztuje **70,00 zł** (wygrywa tańsza), a **wrzesień nadal
      kosztuje 70,00 zł** zamiast być dziurą w cenniku. Pierwsza redakcja zadania dawała tu dziurę
      na resztę sezonu.
- [ ] **Stawka-okno droższa niż obowiązująca zapisuje się bez błędu i bez ostrzeżenia**: zapis
      „90 zł na 01.07–31.08" przy bezterminowych 70 zł przechodzi, lipiec kosztuje 70,00 zł, a panel
      nie dokłada żadnego komunikatu — uwidocznienie tego należy do kalendarza (019).
- [ ] **Przy równych kwotach wynik jest deterministyczny** — dwie stawki 70,00 zł pasujące do tej
      samej doby dają zawsze tę samą regułę w rozbiciu.
- [ ] **Wynik rozstrzygania niesie komplet kandydatów, nie sam zwycięzcę**: przy stawkach 70,00 zł
      i 90,00 zł pasujących do tej samej doby wynik zawiera obie, ze wskazaniem, która wygrała.
      Przy jednej pasującej stawce lista ma jedną pozycję.
- [ ] **Identyczne kwoty też są w komplecie**: dwie stawki po 70,00 zł na tę samą dobę dają dwie
      pozycje, a nie jedną — to jest dane, z których 019 rysuje licznik nachodzenia.
- [ ] **Komplet kandydatów nie zależy od stanowiska ani składu uczestników** — ta sama doba pytana
      dla różnych stanowisk i różnej liczby osób daje tę samą listę stawek.
- [ ] **Martwa stawka jest wskazywana**: przy „70,00 zł bezterminowo" stawka „90,00 zł na
      01.07–31.08" jest zgłoszona jako niewygrywająca w żadnej dobie swojego okresu, a „50,00 zł na
      01.05–31.05" **nie** jest — bo w maju wygrywa.
- [ ] **Diagnostyka niczego nie zmienia w cenie** — wycena tej samej doby daje tę samą kwotę
      niezależnie od tego, czy wołający sięgnął po komplet kandydatów.
- [ ] **Dopłaty sumują się**: dwie pasujące dopłaty po 20,00 zł dają +40,00 zł, a rozbicie pokazuje
      obie pozycje osobno.
- [ ] **Automatyczne domykanie**: stawka 70,00 zł bezterminowo plus nowa 80,00 zł od 01.01.2027 →
      pierwsza dostaje `last_day_on = 31.12.2026`, doba z 2027 kosztuje **80,00 zł**, a operator
      dostaje powiadomienie o domknięciu. ⚠️ Test ma pokazać, że **bez domknięcia wyszłoby 70,00 zł**
      — to dowód, że mechanizm jest potrzebny mimo wyboru na korzyść wędkarza.
- [ ] **Stawka z ustawioną datą końca nie jest domykana**: 130,00 zł na 01.03–30.06 i druga
      130,00 zł na 01.09–31.12 współistnieją bez zmiany dat.
- [ ] **Domykanie nie dotyka dopłat** — dodanie dopłaty z datą nie ucina innej dopłaty.
- [ ] **Domykanie NIE odpala się przy edycji**: zmiana `first_day_on` istniejącej stawki nie zmienia
      dat żadnej innej reguły.
- [ ] **Kilka nowych stawek w jednym zapisie domyka się po kolei**: dodanie w jednym zapisie stawek
      od 01.01.2027 i od 01.01.2028 daje łańcuch `…–31.12.2026`, `2027-01-01–31.12.2027`,
      `2028-01-01–…` **niezależnie od kolejności wierszy w repeaterze**.
- [ ] **Domknięcie idzie w tej samej transakcji i trafia do dziennika zmian** — nieudany zapis
      formularza nie zostawia domkniętej stawki.
- [ ] **Miękko usunięta reguła nie wchodzi do wyceny** — skasowana stawka 50,00 zł nie wygrywa
      z obowiązującą 70,00 zł.
- [ ] **`amount_companion = null` to odmowa, nie darmowa towarzysząca**: zapytanie z osobą
      towarzyszącą o dobę, której wygrana stawka nie ma ceny towarzyszącej, zwraca
      **`NoCompanionPrice`** — a **nie** `NoPriceDefined`; to samo zapytanie bez osoby towarzyszącej
      wycenia się normalnie.
- [ ] **Dwa powody odmowy są rozróżnialne**: doba bez żadnej pasującej stawki daje
      `NoPriceDefined`, doba ze stawką bez ceny towarzyszącej — `NoCompanionPrice`, a `label()`
      zwraca dla nich **różne teksty** z `lang/pl.json`.
- [ ] **Stawka bez ceny towarzyszącej przegrywa remis**: przy dwóch stawkach 70,00 zł, z których
      jedna ma `amount_companion = 0,00`, a druga `null`, wygrywa ta z wypełnioną kwotą i zapytanie
      z osobą towarzyszącą wycenia się normalnie.
- [ ] **Formularz nie pozwala zapisać stawki bez ceny towarzyszącej** — pole jest wymagane
      z domyślnym `0,00`.
- [ ] **Dziura w cenniku**: doba w otwartym sezonie bez pasującej stawki zwraca odmowę
      `NoPriceDefined`, a nie cenę 0,00 zł; ostrzeżenie przy zapisie nazywa dobę wraz z dniem
      tygodnia, **nie wspomina o obsadzie ani o roli** i nie wspomina o regułach wygasających.
- [ ] **Sprawdzenie dziury nie iteruje po obsadach** — wynik dla stanowiska na 1 i na 4 łowiących
      jest identyczny, bo stawka zależy wyłącznie od daty.
- [ ] **Puste pola warunku znaczą „bez warunku"**: puste daty dają stawkę obowiązującą zawsze;
      pusty wybór dni tygodnia i pusta obsada dają dopłatę bez tego warunku; pusty `Select`
      przysyłający **pusty łańcuch** (nie `null`) nie wywraca zapisu — regres z 2026-09-22 ma
      własny test.
- [ ] **Zapis stawki zeruje pola dopłaty**: `weekdays`, `anglers_count` **i `applies_to`** lądują
      w bazie jako `null`, nawet jeśli formularz przyśle tam cokolwiek.
- [ ] **Obniżka przedsprzedażowa** liczy się per doba, od stawki wraz z dopłatami, zaokrąglana raz
      na dobę: dwóch łowiących po 70,05 zł przy 10% → podstawa 140,10 zł, obniżka **14,01 zł**
      (a nie 14,02 zł).
- [ ] **Warstwa oferty działa bez zmian**: pobyt niesprzedawalny wg 017 dostaje odmowę bez
      wyceniania; `shortestOffer()` zwraca najkrótszy kupowalny pobyt wraz z rozbiciem; zwolnienie
      świąteczne z minimum nadal działa.
- [ ] Wycena **nie woła `StaySellability`** — zmiana reguł pobytu z 017 nie zmienia wyniku wyceny.
- [ ] Kwoty zapisują się z przecinkiem i kropką tak samo, a asercje idą przez
      `DB::table(...)->value(...)` ([`panel-wlasciciela.md`](../../conventions/panel-wlasciciela.md) §4).
- [ ] Właściciel nie widzi ani nie edytuje cennika cudzego łowiska.
- [ ] `ShieldPermissionNamesTest` zielony, w `app/Policies/` **nie przybywa** żaden plik.
- [ ] Trwałe usunięcie łowiska zabiera ze sobą jego reguły cenowe.
- [ ] **Klasy usunięte**: `PriceRuleOverlap`, `PriceRulesDoNotTie` i ich testy nie istnieją;
      `PriceRuleResolver` nie zna pojęcia priorytetu ani szczegółowości.
- [ ] Zielony zakres T2 zadeklarowany niżej.
- [ ] **Pełny pakiet testów ODROCZONY** do punktu kontrolnego B (po zadaniach 017–019) — odroczony,
      nie pominięty.

---

## Zakres testów

- **Tier:** T2
- **Uruchamiamy:** `docker compose exec app php artisan test --filter="StayPricingTest|PriceRuleTest|PriceRuleResolutionTest|PricingDiagnosticsTest|StayOfferTest|PricingPageTest|StaySellabilityTest|PositionAvailabilityTest|FishingDayTest|SalePeriodTest|SaleSettingsPageTest|SaleRulesPageTest|AdditionalServicePriceTest|ActivityLoggingTest|ShieldPermissionNamesTest|AdminPanelTest|OwnerPanelTest|FisheryAccessTest"`
- **Uzasadnienie:** bez zmian wobec pierwszej wersji — jeden wyzwalacz T3 (migracje), odstępstwo
  **pakietowe** z rozdziału 14.2 wymagań, punkt kontrolny **B** po zadaniach 017–019.
  ⚠️ Przebudowa migracji **nie podnosi** tieru: to ta sama migracja, tylko o innej treści.
  ⚠️ `PriceRuleResolutionTest` zostaje w filtrze, ale jego zawartość jest do **przepisania** —
  przypadki priorytetu, szczegółowości i remisu znikają, wchodzą wybór na korzyść wędkarza,
  domykanie okresów i różnicowanie ceny dniami przez dopłatę.
  ⚠️ Z filtra **nie usuwamy** niczego: klasy usunięte (`PriceRuleOverlap`, `PriceRulesDoNotTie`)
  nie miały własnych pozycji.
  ⚠️ Zadanie nie trafia do `docs/tasks/implemented/`, dopóki punkt kontrolny B nie jest zielony.

---

## Zakres wyłączeń

- **Snapshot oferty w transakcji (G1)** — poza iteracją; 018 wnosi wyłącznie strukturę rozbicia.
- **Rezerwacje, koszyk i płatności** — poza iteracją.
- **Rysowanie nachodzenia** — zadanie 019. 018 wystawia **diagnostykę** (komplet kandydatów na dobę,
  martwe stawki); 019 pokazuje z niej licznik, zwycięzcę, kwoty przegranych i oznaczenie martwej
  reguły. Podział jest taki sam jak przy `shortestOffer()`: dane liczy warstwa, która je zna, rysuje
  widok.
- **Wycena usług dodatkowych** — zadanie 020; usługi nie wchodzą do podstawy obniżki.
- **Dopłaty procentowe w `price_rules`** — odrzucone w M3.
- **Priorytety i ręczne rozstrzyganie kolizji** — **wycofane w tym zadaniu**; wracają wyłącznie
  wtedy, gdy pojawi się łowisko, któremu „najkorzystniej dla wędkarza" nie wystarcza.
- **Dni tygodnia na stawce** — **wycofane w tym zadaniu**; zamiennikiem jest dopłata. Powrót
  wymagałby łowiska, które ma inną cenę **bazową** w weekend i któremu dopłata nie wystarcza — dziś
  takiego nie ma (O4).
- **Aktywowanie cennika z wyprzedzeniem** (drugi wymiar czasu) — świadomie poza zakresem; wraca
  dopiero razem ze snapshotem G1, bo dopiero wtedy jest obserwowalne.
- **Cykliczny zakres dat** („co roku od kwietnia do listopada") — zakres jest **datowany**, więc
  Klasztorne przepisze go co sezon. Tak samo działał model poprzedni, więc to nie regres.
- **Progi za kolejne osoby, dopłata za wędkę, kody rabatowe, cena per stanowisko** — „nie teraz"
  w M3.
- **Bramka udostępnienia stawek warunkowych** — nie powstaje; warunek spełnia harmonogram wdrożenia.

---

## Zmiany dokumentacji

- [ ] [`ADR-014`](../../adr/ADR-014-cennik-jako-lista-regul-z-warunkami.md) — **sekcja „Aktualizacja"
      z odwróceniem decyzji**: cztery osie, priorytet i szczegółowość wycofane; zostaje lista reguł,
      w której **stawka ma tylko oś czasu**, dopłata trzy osie, a pierwszeństwo rozstrzyga się na
      korzyść wędkarza. Treść pierwotna **zostaje** jako zapis tego, co rozważano i dlaczego odpadło
- [ ] [`ADR-015`](../../adr/ADR-015-warstwa-oferty-pobytu.md) — **bez zmian**; warstwa oferty nie zależy
      od sposobu rozstrzygania cennika
- [ ] `docs/conventions/cennik.md` — **przepisanie** rozdziałów o rozstrzyganiu, dwóch wymiarach
      czasu, osiach warunku i osobie towarzyszącej (reguła unieważniona jest **przepisywana, nie
      dopisywana obok korekty** — `CLAUDE.md`); rozdziały o wycenie, obniżce, dziurze i warstwie
      oferty zostają
- [ ] `MANUAL.md` — rozdział „Cennik" do przepisania: znikają priorytety i pułapka osoby
      towarzyszącej, dochodzi domykanie okresów, „wygrywa korzystniejsza dla wędkarza" oraz zdanie,
      że **cenę weekendową robi się dopłatą**
- [ ] `docs/project/etapy/00-konfiguracja-sprzedazy/WYMAGANIA-SPRZEDAZ-KROTKOTERMINOWA.md` — §M3: odnotować, że rozstrzyganie po
      priorytecie i szczegółowości zostało wycofane, warunek roli zastąpiony kolumną, a dni
      tygodnia są wyłącznie warunkiem dopłaty
- [ ] `CHANGELOG.md` — wpis do **poprawienia, nie dopisania**: dzisiejszy opis 018 mówi
      o priorytetach, o remisie blokującym zapis, o warunkach na stawce i o regule dla osoby
      towarzyszącej, czyli o rzeczach, których nie będzie
- [x] `docs/project/mockups/makieta-018-cennik-regulowy-v2.html` — **utworzona 2026-09-22**; v1
      zostaje na dysku jako zapis historyczny i nie służy już implementacji
- [ ] `README.md` — bez zmian

---

## Ograniczenia techniczne

- Laravel 13, Filament 5, PHP 8.4 — stan po zadaniu 009.
- **Przepisanie istniejących plików migracji** plus rollback bazy roboczej — patrz „Przebudowa
  migracji i rollback bazy".
- Kwoty: `decimal(8,2)`; waluta z `fisheries.currency_id`. Asercje o zapisanej kwocie przez
  `DB::table(...)->value(...)`.
- Wszystkie porównania dat w **strefie czasowej łowiska**.
- **Nie liczy dób po swojemu** — robi to `FishingDayCalendar`.
- Testy biegną na MySQL-u w schemacie `lowiska_test`.

---

## Rozstrzygnięcia

Ustalenia wiążą implementację tak samo jak decyzje z ADR-ów. Punkty 1–24 z pierwszej wersji zadania
zostały **zastąpione** poniższymi; te, które przetrwały bez zmian, są wymienione na końcu.

1. **Cennik zostaje listą reguł, ale osie warunku rozkładają się asymetrycznie:** stawka ma
   **wyłącznie zakres dat**, dopłata — zakres dat, dni tygodnia i obsadę. Cztery osie na obu
   rodzajach, priorytet i szczegółowość — wycofane.
2. **Dni tygodnia znikają ze stawki.** Kto chce różnicować cenę konkretnymi dniami, robi to
   **dopłatą** — O4 mówi, że cena bazowa u obu łowisk jest identyczna przez cały tydzień, a dopłata
   pokazuje różnicę jako różnicę, nie jako drugą cenę bazową.
3. **Osoba towarzysząca to KOLUMNA na stawce** (`amount_companion`), nie osobna reguła. Znika zasada
   „stawka towarzyszącej ma najwyższy priorytet" i ostrzeżenie, które jej broniło.
4. **Jeden wymiar czasu.** `first_day_on`/`last_day_on` mówią, **których dób** reguła dotyczy.
   Aktywowania cennika z wyprzedzeniem nie ma i nie potrzebujemy go na tym etapie.
5. **Nachodzenie stawek nie jest błędem.** Wygrywa **najniższa kwota za łowiącego**; kalendarz (019)
   ma nachodzenie pokazać i oznaczyć. Po punkcie 2 nachodzenie może być już tylko datowe.
6. **Automatyczne domykanie okresów stawki zostaje** mimo punktu 5 — bez niego podwyżka przegrywa
   z tańszą stawką bezterminową i cicho nie działa. Domyka **wyłącznie po datach**, **wyłącznie gdy
   nowa stawka sama jest bezterminowa**, i **wyłącznie przy utworzeniu**. Operator dostaje
   powiadomienie o domknięciu.
   ⚠️ Dwa pierwsze warunki nie są ostrożnością, tylko poprawką błędu: pierwsza redakcja tego
   zadania domykała także przy dodaniu stawki-okna, czym otwierała dziurę w cenniku po końcu okna.
7. **Warunek obsady zostaje na dopłacie** („tylko gdy łowi dokładnie N osób") — bez niego nie da się
   odwzorować ani Łopienna, ani Klasztornego.
8. **Dopłata ma pole „dla kogo"** (`applies_to`: dla łowiącego / dla każdego / dla osoby
   towarzyszącej) i nalicza się za każdą dobę, mnożona przez liczbę osób wskazanych tym polem.
   Zamyka to pytanie, czy dopłata ma obciążać darmową osobę towarzyszącą: **rozstrzyga operator
   per dopłata**, a oba dzisiejsze łowiska ustawiają „dla łowiącego".
   ⚠️ **Domyślną wartością jest „dla łowiącego".** Domyślne „dla każdego" przywracałoby przez
   wartość domyślną tę samą pułapkę, którą całe przedefiniowanie usunęło — dopłata dodana bez
   zastanowienia obciążałaby darmową osobę towarzyszącą. Kolumna jest **nullable, bez domyślnej
   wartości w bazie**; domyślna wartość żyje wyłącznie w formularzu, bo na stawce to pole nie
   istnieje.
9. **Wycena wymaga co najmniej jednego łowiącego** — skład bez wędkarza odrzuca obiekt zapytania,
   nie warstwa odmów. Bez tej bramki „0 łowiących, 1 osoba towarzysząca" dawałoby ofertę za 0,00 zł.
10. **Stawką z datą końca ceny się nie podnosi** — wygra tańsza bezterminowa. To skutek punktu 5
    przyjęty świadomie: podwyżkę robi się nową stawką bezterminową albo dopłatą.
11. **`amount_companion = null` na stawce znaczy BRAK CENY, nie cenę zerową** — zapytanie z osobą
    towarzyszącą dostaje **własny** powód odmowy `NoCompanionPrice`, a nie zbiorczy
    `NoPriceDefined`: pierwszy każe dopisać stawkę, drugi poprawić pole w istniejącej, więc jedna
    wartość na oba przypadki posyłałaby operatora w złą stronę. Taka stawka **przegrywa też każdy
    remis kwotowy**. Pole jest wymagane w formularzu z domyślnym `0,00`; darmowa towarzysząca to
    świadome zero, nie puste pole.
12. **Sprawdzanie dziury w cenniku iteruje wyłącznie po dobach.** Pętle po obsadach i po rolach
    znikają, bo dopasowanie stawki zależy już tylko od daty, a dopłaty dziur nie tworzą.
13. **Obsadę liczą sami łowiący** — osoba towarzysząca nie podnosi `anglers_count`. Bez tego dopłata
    za wyłączność znikałaby przy przyjeździe z osobą towarzyszącą, choć stanowisko nadal zajmuje
    jeden wędkarz.
14. **Migracje 018 przepisujemy w miejscu, po rollbacku bazy** i po zapytaniu autora o zgodę — 018
   nie jest wydane, a migracja poprawiająca utrwaliłaby w historii schematu cztery kolumny bez
   uzasadnienia.
15. **Commit `08c8d38` zostaje w historii.** Cofnięcie go wyrzuciłoby warstwę oferty, wycenę,
    rozbicie i obniżkę przedsprzedażową, których nowy model nie rusza.
16. **Opisy pod polami warunku są częścią zadania, nie kosmetyką.** Zgłoszenie dotyczyło
    nieczytelności; samo usunięcie osi go nie zamyka.
17. **Cennik wystawia diagnostykę dla podglądu: komplet kandydatów na dobę i wskazanie martwych
    stawek.** Bez niej nachodzenie stawek nie byłoby widoczne **nigdzie**: przestało być błędem
    zapisu, ostrzeżenie w formularzu zostało usunięte, a kalendarz nie ma prawa dopasowywać stawek
    sam (ADR-014, jeden dom dla logiki cennika). Resolver już materializuje kandydatów, więc to
    zmiana kształtu wyniku, nie nowa logika — bez kolumn, bez migracji i bez pola w formularzu.
    Kandydaci zależą wyłącznie od daty, więc 019 pobiera diagnostykę raz na okno, nie raz na komórkę.

**Przetrwały bez zmian z pierwszej wersji:** warunek liczony per doba (K3/P2); obniżka
przedsprzedażowa liczona per doba z jednym zaokrągleniem, połówki w górę, od stawki wraz
z dopłatami; **dziura w cenniku jako odmowa, sprawdzana pomocniczo przy zapisie** (sam zakres
sprawdzenia się upraszcza — rozstrzygnięcie 11); odmowa „brak ceny" niosąca dobę;
nazwy `first_day_on`/`last_day_on`
i zakaz `starts_on`/`ends_on`; porównanie obsady przez równość, nigdy z `positions.max_anglers`;
warstwa oferty jako jedyne wejście dla 019, koszyka i portalu; brak zasobu, polityki i uprawnień
Shielda dla `PriceRule`.

---

## Powiązane ADR-y

- [ADR-014](../../adr/ADR-014-cennik-jako-lista-regul-z-warunkami.md) — ⚠️ **decyzja częściowo
  odwrócona** przez to przedefiniowanie; wymaga sekcji „Aktualizacja" **przed** implementacją.
- [ADR-015](../../adr/ADR-015-warstwa-oferty-pobytu.md) — **w mocy bez zmian**.
- [ADR-010](../../adr/ADR-010-doba-wedkarska-jako-przedzial-czasu.md),
  [ADR-012](../../adr/ADR-012-jedno-zrodlo-prawdy-o-dostepnosci.md),
  [ADR-013](../../adr/ADR-013-warstwa-regul-pobytu-i-spoiwo-dob.md) — w mocy.

---

## Otwarte kwestie

**Brak — wszystkie zamknięte przed implementacją.** Przebieg zamykania, dla porządku:

- ~~Czy domykanie ma patrzeć na dni tygodnia~~ — pytanie zniknęło razem z dniami tygodnia na
  stawce (rozstrzygnięcie 2). Domykanie patrzy wyłącznie na daty.
- ~~Czy staging ma wykonane migracje 018~~ — **nie ma**, commit nie jest wypchnięty na `origin/dev`.
  Rollback dotyczy tylko bazy roboczej.
- ~~Czy dopłata ma obciążać darmową osobę towarzyszącą~~ — **rozstrzyga operator per dopłata**
  polem „dla kogo" (rozstrzygnięcie 8). Wymagania zostawiały to jako otwarte **P4**; zamiast
  wybierać za klienta, model dostaje trzecie pole i pytanie znika.
- ~~Co znaczy `amount_companion = null`~~ — **brak ceny, czyli odmowa** (rozstrzygnięcie 10).
- ~~Czy domykanie odpala się przy edycji i jak przy kilku nowych stawkach naraz~~ — **tylko przy
  utworzeniu**, w kolejności rosnącego `first_day_on`, w jednej transakcji (rozstrzygnięcie 6).

⚠️ **P4 wymagań pozostaje otwarte po stronie biznesu** („czy osoba towarzysząca wpływa na obsadę").
To zadanie odpowiada na nie **nie wpływa** (rozstrzygnięcie 12) i tyle wystarcza do wyceny — ale
pytanie wraca przy rezerwacjach, gdzie obsada zaczyna znaczyć także „ile osób fizycznie wchodzi na
stanowisko".
