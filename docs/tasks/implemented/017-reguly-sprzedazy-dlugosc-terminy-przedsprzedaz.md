# 017 — Reguły sprzedaży: długość pobytu, weekend i święta w całości, przedsprzedaż i horyzont

## Opis problemu

Po zadaniach 015, 014 i 016 system wie, **czym** handluje (doba), **czym** dysponuje (stanowiska
z cechami i stanem) i **kiedy jest wyłączony** (okresy sprzedaży, blokady). Nie wie natomiast, **co
wolno kupić**: każdy zbiór sprzedawalnych dób jest dziś tak samo dobry, więc wędkarz mógłby wziąć
samą sobotę z weekendu sprzedawanego wyłącznie w całości albo kupić pobyt na dwa lata naprzód.

Brakuje też samego **pojęcia pobytu**. Wszystko, co powstało dotąd, odpowiada na pytania o pojedynczą
dobę; reguły z tego zadania dotyczą **ciągu dób kupowanego razem** i nie dają się wyrazić jako
własność żadnej z nich osobno.

Klasztorne ma cztery takie reguły spisane wprost na stronie i **bez żadnej z nich nie odwzoruje
sezonu 2026**: weekendy sprzedawane wyłącznie w całości („zasiadka jednodobowa tylko nd–czw"), dwa
terminy świąteczne sprzedawane wyłącznie w całości, przedsprzedaż kolejnego sezonu z warunkiem długości
oraz horyzont sprzedaży.

Zadanie realizuje moduł **M4** wraz z punktem elastyczności **F7** oraz mechanizmami **G3**
(walidacja konfiguracji przy zapisie) i **G11** (wyjaśnialna odmowa) z
[Wymagań konfiguracji sprzedaży krótkoterminowej](../../project/etapy/00-konfiguracja-sprzedazy/WYMAGANIA-SPRZEDAZ-KROTKOTERMINOWA.md).
Jest pierwszym zadaniem **punktu kontrolnego B**.

### Historia kształtu — dlaczego to jest wariant 2

Pierwsza wersja tego zadania modelowała długość pobytu jako **regułę zależną od dnia rozpoczęcia**
(`stay_length_rules`: dzień tygodnia → min/max dób) i przedsprzedaż jako osobną tabelę z własnym
zakresem dób. Przegląd z 2026-09-20 wykazał, że ten model **nie wyraża** reguły „weekendy tylko
w całości": „przyjazd w piątek → min. 2 doby" nie zabrania przyjazdu w sobotę — sama sobota
przechodzi przy bazowym minimum 1, a przy podniesionym do 2 przechodzi sobota–poniedziałek. Dwie
łatki odrzucono: jawny **zakaz dnia przyjazdu** (niezrozumiały dla operatora, miesza limit
z zakazem) i **cykliczny generator terminów** (masa przypadków brzegowych przy blokadach, świętach
i zmianach w sezonie).

Brakującym pojęciem okazało się **spoiwo dób**: doby, które idą wyłącznie razem — cyklicznie
(weekend) albo jednorazowo (święto datowane). To jedna mechanika o dwóch regułach powtarzania, nie
dwa mechanizmy. Reguły długości per dzień tygodnia wypadają w całości (R5 — po wprowadzeniu spoiwa
żadne z dwóch łowisk ich nie potrzebuje).

Makiety:
- **wiążąca** — [`makieta-017-reguly-sprzedazy-v2-spoiwo.html`](../../project/mockups/makieta-017-reguly-sprzedazy-v2-spoiwo.html);
- archiwalna (odrzucony wariant 1, zachowana dla historii decyzji) —
  [`makieta-017-reguly-sprzedazy.html`](../../project/mockups/makieta-017-reguly-sprzedazy.html).

⚠️ **Zadanie nie czeka na odpowiedzi łowiska.** P17 (czy Łopienno ma minimalną długość pobytu)
i P18 (niepisane zasady Łopienna) są wartościami wpisywanymi do gotowego mechanizmu, nie jego
kształtem. P15 (konflikt K4) ma przypisane założenie — reguła 9. Pytanie 7a (doba niedzielna) ma
założenie robocze zapisane w „Rozstrzygnięciach" — odpowiedź przeciwna zmienia kształt, więc jest
jedynym pytaniem, którego odpowiedź trzeba znać **przed** `/implement-task`.

## Wymagania

### Dwa ekrany, dwa pytania

Reguły rozkładają się na dwa istniejące miejsca w sub-nawigacji łowiska:

| Ekran | Pytanie | Zawartość po zadaniu |
|---|---|---|
| **„Sprzedaż i sezony"** (istnieje, `ManageSaleSettings`) | KIEDY sprzedajesz | doba i strefa (bez zmian) · okresy sprzedaży **z przedsprzedażą na okresie** · **horyzont sprzedaży** |
| **„Reguły sprzedaży"** (nowa strona, za „Sprzedaż i sezony") | JAKI POBYT wolno kupić | długość pobytu (min/max) · **weekend sprzedawany wyłącznie w całości** · **święta sprzedawane wyłącznie w całości** |

### Zmiany w tabeli `fisheries`

| Zmiana | Kolumna | Typ | Uwagi |
|---|---|---|---|
| dodać | `min_nights` | `unsignedSmallInteger` nullable | minimalna liczba dób pobytu; `null` = bez dolnej granicy |
| dodać | `max_nights` | `unsignedSmallInteger` nullable | maksymalna liczba dób; `null` = bez górnej granicy |
| dodać | `weekend_days` | `json` nullable, cast `array` | zbiór dni ISO-8601 (1 = poniedziałek … 7 = niedziela) **rozpoczęcia dób**, które składają się na weekend sprzedawany w całości; `null`/pusty = brak reguły |
| dodać | `sale_horizon_days` | `unsignedSmallInteger` nullable | jak daleko w przód wolno kupować; `null` = bez horyzontu |

⚠️ `weekend_days` to **kolumna JSON na łowisku, nie tabela** — zbiór co najwyżej siedmiu małych liczb,
zapisywany w całości jednym polem formularza. Osobny model, relacja i fabryka dla tego zbioru
byłyby abstrakcją bez pokrycia (`CLAUDE.md`: nie projektujemy pod hipotetyczne wymagania). Gdyby
kiedyś pojawiło się łowisko z **drugim** cyklicznym pakietem albo weekendem różnym per sezon,
to będzie moment na tabelę — migracja siedmiu liczb jest trywialna.

### Zmiany w tabeli `sale_periods` — przedsprzedaż jako właściwość okresu

| Zmiana | Kolumna | Typ | Uwagi |
|---|---|---|---|
| dodać | `presale_opens_on` | `date` nullable | od kiedy **wolno kupować** doby tego okresu przed horyzontem |
| dodać | `presale_closes_on` | `date` nullable | do kiedy |
| dodać | `presale_min_nights` | `unsignedSmallInteger` nullable | minimalna liczba dób **każdego** zakupu dób tego okresu dokonanego w oknie; `null` = bez warunku |
| dodać | `presale_whole_terms_bypass_min_nights` | `boolean`, domyślnie **`true`** | czy święto sprzedawane w całości pomija warunek długości — reguła 9 |

Przedsprzedaż jest **włączona**, gdy obie daty są wypełnione. Zakres objętych dób to z definicji
zakres okresu — nie ma osobnych kolumn `covers_from`/`covers_to` i nie da się skonfigurować okna
obejmującego doby poza sezonem. Tabela `presale_windows` z pierwszej wersji zadania **nie powstaje**.

### Nowa tabela `whole_term_periods` — święta sprzedawane wyłącznie w całości

| Kolumna | Typ | Uwagi |
|---|---|---|
| `id` | `id()` | |
| `fishery_id` | `foreignId`, **wymagany**, `cascadeOnDelete()` | jak `sale_periods`, `position_groups`, `availability_blocks` |
| `name` | `string` nullable | nazwa własna, dla orientacji operatora („Majówka"); wędkarz jej nie widzi |
| `first_day_on` | `date` | dzień rozpoczęcia **pierwszej doby** święta |
| `last_day_on` | `date` | dzień rozpoczęcia **ostatniej doby** święta |
| | `softDeletes()`, `timestamps()` | |
| — | `index(['fishery_id','first_day_on'])` | zwykły indeks |

⚠️ **Kolumny nazywają się inaczej niż w `sale_periods`, bo znaczą co innego — i to jest cały powód
tej nazwy.** W okresie sprzedaży `ends_on` to **ostatni dzień kalendarzowy okna**, a doba musi się
w oknie zawrzeć w całości, więc ostatnia sprzedawalna doba zaczyna się **dzień wcześniej**
([`dostepnosc.md`](../../conventions/dostepnosc.md) §1: „ostatnie pozwolenie jednodobowe kupuje się na
PRZEDOSTATNI dzień okresu"). W święcie `last_day_on` to **dzień rozpoczęcia ostatniej doby**, która
jest objęta.

Ta sama para dat daje więc dwie różne liczby dób:

| Zapis | 30.04 – 02.05 | Dlaczego |
|---|---|---|
| okres sprzedaży (`starts_on`/`ends_on`) | **2 doby** (30.04, 01.05) | doba z 02.05 kończy się 03.05, poza oknem |
| święto (`first_day_on`/`last_day_on`) | **3 doby** (30.04, 01.05, 02.05) | `last_day_on` wskazuje dobę objętą |

⚠️ **Nie kopiuj logiki granic z `SalePeriod`** — różne nazwy są tu zabezpieczeniem, nie kosmetyką.
Formularz dodatkowo pokazuje wyliczony pobyt („czw 30.04 15:00 → nd 3.05 15:00 · 3 doby"), żeby
operator nie mylił ostatniej doby z dniem wyjazdu.

⚠️ **Święta mogą na siebie zachodzić i mogą zachodzić na weekend** — nie ma indeksu unikalnego ani
reguły nienachodzenia. Nachodzące spoiwa **zlewają się** w jeden pakiet (reguła 6), więc nachodzenie
jest stanem poprawnym, nie błędem zapisu. Tabele `stay_length_rules` i `presale_windows` z pierwszej
wersji zadania **nie powstają**.

### Pojęcie pobytu i miejsce, w którym reguły są stosowane

**Pobyt** to ciągły zakres dób na **jednym** stanowisku, opisany dobą rozpoczęcia i liczbą dób.
Dotąd żadna klasa nie znała tego pojęcia.

Reguły z tego zadania stosuje **jedna klasa w `app/Services/`** — `StaySellability`, zwracająca
`StaySellabilityVerdict` (patrz „Rozstrzygnięcia", pkt 24) — zgodnie z regułą „logika obliczeniowa
ma jeden dom" z `CLAUDE.md`. Odpowiada na jedno pytanie: **czy ten pobyt wolno kupić w tej chwili,
a jeśli nie, to dlaczego.** Wołają ją panel, kalendarz podglądowy (019), cennik (018) i przyszły
portal — żadne z nich nie powtarza tych warunków u siebie.

⚠️ **Klasa KOMPONUJE [`PositionAvailability`](../../../app/Services/PositionAvailability.php), nie
zastępuje jej ani nie powtarza jej warunków.** `PositionAvailability` pozostaje jedynym miejscem
odpowiadającym na pytanie o **dobę** ([`dostepnosc.md`](../../conventions/dostepnosc.md) §2, ADR-012);
to zadanie dokłada warstwę o **pobycie**. Granica jest ta sama co między `FishingDayCalendar`
a `PositionAvailability`: pytasz o dobę → dotychczasowa klasa, pytasz o pobyt → nowa.

**Spoiwo i instancja pakietu.** *Instancja pakietu* to maksymalny ciąg **kolejnych** dób spiętych
spoiwem: dób, których dzień rozpoczęcia należy do `weekend_days` (cyklicznie — nd→pon i pon→wt to
ciąg), albo dób święta datowanego. Instancje buduje się **wyłącznie z dób sprzedawalnych** według
`PositionAvailability` (reguła 5) i **zlewa**, gdy na siebie zachodzą (reguła 6). Nic nie jest
zmaterializowane — instancje liczą się w chwili pytania.

**Kolejność sprawdzania** (potwierdzona — wciągnięta do
[ADR-013](../../adr/ADR-013-warstwa-regul-pobytu-i-spoiwo-dob.md)):
dostępność **każdej doby** pobytu → spoiwo (objęcie instancji) → długość (min/max) → horyzont
i przedsprzedaż. Uzasadnienie jest to samo, które ADR-012 przyjął dla kolejności wewnątrz
dostępności: odmowa niesie przyczynę **trwalszą** — „stanowisko jest wycofane" jest trwalsze niż
„przerwany weekend", a to trwalsze niż „za krótki" czy „poza horyzontem".

### Reguły zachowania

1. **Brak reguł = brak ograniczeń.** Łowisko bez minimum, bez weekendu i bez świąt sprzedaje pobyty
   dowolnej długości. ⚠️ Odwrotnie niż przy okresach sprzedaży, gdzie brak wpisu jest odmową (015)
   — asymetria zamierzona: okres sprzedaży mówi, co **wolno**, a reguła pobytu, czego **nie wolno**.
   Trzeba to zapisać w konwencjach, bo intuicja podpowiada spójność tam, gdzie jej nie ma.
2. **Długość pobytu to jedna para `min_nights`/`max_nights` na łowisko**, bez zależności od dnia
   rozpoczęcia. `min_nights` nie może być większe niż `max_nights`.
3. **Pobyt przecinający instancję pakietu musi ją objąć W CAŁOŚCI.** Kupno samej soboty z weekendu
   pt–sob jest odmową, tak samo kupno trzech dób z czterodobowego święta; kupno pakietu wraz
   z dobami przed nim i po nim jest dozwolone, o ile te doby są sprzedawalne.
4. **Weekend to zbiór dób, nie dni.** `weekend_days` wskazuje **dni rozpoczęcia dób** — dla doby
   15:00→15:00 weekend Klasztornego „od piątku 15:00 do niedzieli 15:00" to `{5, 6}` (doby pt→sob
   i sob→nd), **nie** `{5, 6, 7}`. Formularz pokazuje doby jako przedziały liczone z godzin doby
   łowiska, żeby operator nie zaznaczał „trzech dni". Walidacja zbioru: doby następują po sobie
   (cyklicznie), co najmniej dwie (pakiet jednodobowy nic nie znaczy), nie wszystkie siedem (pakiet
   nieskończony). Doba `nd 15:00 → pon 15:00` leży **poza** weekendem `{5, 6}` i sprzedaje się jak
   zwykły dzień — patrz „Rozstrzygnięcia", pkt 13.
5. **Instancję pakietu PRZYCINA się do dób sprzedawalnych.** Blokada w sobotę wycina z weekendu dobę
   sob→nd, więc piątek sprzedaje się sam — tej soboty i tak nie ma. Święto, którego jedna doba jest
   w blokadzie, wymaga objęcia pozostałych. Alternatywa „pakiet z niesprzedawalną dobą jest cały
   niesprzedawalny" **odrzucona**: blokada soboty wyłączałaby cicho piątek, a wędkarz widziałby odmowę
   bez czytelnej przyczyny. Decyzja obowiązuje **wspólnie** dla weekendów i świąt.
6. **Nachodzące pakiety ZLEWAJĄ się** — spoiwo jest przechodnie. Święto śr–pt nachodzące na weekend
   pt–sob daje jeden pakiet śr–sob (4 doby). Nie ma walidacji nienachodzenia świąt między sobą ani
   z weekendem. Skutek jest zamierzony, ale niewidoczny w formularzu — pokazuje go kalendarz
   podglądowy (019).
7. **Pobyt obejmujący pakiet ze świętem NIE PODLEGA minimum długości.** Zwolnienie jest
   zero-jedynkowe i należy do **pakietu**, nie do samego święta: wystarczy, że pobyt obejmuje choć
   jeden pakiet, w którym jest doba święta, a `min_nights` — oraz `presale_min_nights` przy włączonej
   fladze (reguła 9) — przestaje go dotyczyć. `max_nights` obowiązuje dalej.
   - **Pakiet czysto weekendowy nie zwalnia z niczego** — przy `min_nights = 3` weekend dwudobowy
     trzeba wydłużyć do trzech dób. To jest treść zasady „spoiwo tylko zaostrza".
   - **Dlaczego zwolnienie, a nie „obniżenie minimum do długości pakietu":** oba zapisy dają ten sam
     wynik, bo pobyt dochodzący do sprawdzenia długości **już** obejmuje każdy dotknięty pakiet
     w całości (reguła 3, sprawdzana wcześniej), więc nigdy nie jest od niego krótszy. Zapis przez
     obniżanie sugerowałby obliczenia, których nie ma — i te obliczenia trafiłyby do kodu oraz do
     konwencji.
   - **Wynik jest monotoniczny** i o to chodziło: przy `min_nights = 5` i trzydobowym święcie
     przechodzą pobyty 3-, 4-, 5- i 6-dobowe. ⚠️ Zwolnienie „tylko dla pobytu **równego** świętu"
     dałoby ciąg „3 przechodzi, 4 odmowa, 5 przechodzi", którego wędkarz nie zrozumie.
   - ⚠️ **Weekend zlany ze świętem jest zwolniony razem z pakietem** — świadoma konsekwencja, nie
     luka. Święto śr–pt zlane z weekendem pt–sob daje pakiet śr–sob (4 doby), więc przy
     `min_nights = 5` czterodobowy pobyt przechodzi. Alternatywa („zlanie kasuje zwolnienie")
     znaczyłaby, że **samo dołożenie reguły weekendu czyni święto niekupowalnym** — a pakiet jest
     niepodzielny, więc jego długość **jest** najmniejszą kupowalną jednostką.
   - ⚠️ **Przycięty pakiet zachowuje zwolnienie.** Blokada obejmująca dwie doby trzydobowego święta
     zostawia pakiet jednodobowy, który **nadal zwalnia z minimum** — tę jedną dobę wolno wtedy kupić
     mimo `min_nights = 5`. Wyjątek od tego byłby przypadkiem szczególnym bez właściciela.
8. **Przedsprzedaż jest właściwością okresu sprzedaży i wyjątkiem od horyzontu — nie od okresu ani
   od dostępności.** W oknie `presale_opens_on`–`presale_closes_on` wolno kupić doby **tego okresu**,
   nawet jeśli leżą dalej niż `sale_horizon_days`. Doba musi nadal być sprzedawalna w rozumieniu
   `PositionAvailability` — okno nie otwiera sprzedaży w blokadzie ani na stanowisku wycofanym.
   Po zamknięciu okna doby poza horyzontem wracają do odmowy „poza horyzontem".
9. **Minimum przedsprzedaży obowiązuje KAŻDY zakup dób okresu dokonany w oknie** — także dób, które
   leżą w horyzoncie. Przedsprzedaż jest ofertą hurtową, nie furtką na pojedyncze doby: w oknie nie
   da się kupić jednej doby sezonu objętego przedsprzedażą. **Gdy
   `presale_whole_terms_bypass_min_nights` jest włączona — a jest domyślnie (P15/K4) — pobyt
   obejmujący pakiet ze świętem nie podlega minimum okna** (zwolnienie z reguły 7): trzydobowa
   Majówka przechodzi w oknie wymagającym pięciu dób, a pobyt dłuższy tym bardziej. Przy fladze
   wyłączonej minimum okna obowiązuje bez wyjątku. Weekend sam z siebie nigdy z niego nie zwalnia.
10. **Horyzont liczy się od dnia dzisiejszego w strefie czasowej łowiska do dnia rozpoczęcia doby**
    i obejmuje wszystkie doby pobytu, nie tylko pierwszą. **Granica jest domknięta**: doba
    rozpoczynająca się dokładnie `sale_horizon_days` dni po dzisiejszym dniu **jest** w horyzoncie
    (`dzień rozpoczęcia doby ≤ dzisiaj + sale_horizon_days`). Porównanie idzie na **datach** w strefie
    łowiska, nie na momentach — inaczej zmiana czasu przesuwałaby granicę o godzinę.
11. **Okno przedsprzedaży obejmuje CAŁE dni brzegowe** — od `presale_opens_on` od północy do
    `presale_closes_on` do końca dnia w strefie łowiska, tak samo jak okno blokady
    ([`dostepnosc.md`](../../conventions/dostepnosc.md) §3). Porównywanym momentem jest chwila zakupu.
    **Błędy zapisu:** `presale_closes_on` wcześniejsze niż `presale_opens_on`; święto krótsze niż
    **dwie doby** (`last_day_on` nie późniejsze niż `first_day_on`); **święto, którego choć jedna
    doba nie mieści się w okresie sprzedaży** — sprawdzane `FishingDayCalendar`-em, tą samą regułą
    zawierania co przy sprzedaży doby (015), więc doba na styku dwóch sąsiadujących okresów też jest
    błędem.
    ⚠️ **Na świeżym łowisku ta reguła musi mówić prawdę o przyczynie.** `FishingDayCalendar` odmawia
    każdej doby z `FishingDayNotConfigured`, gdy nie ma godzin doby, i z `NoSalePeriodDefined`, gdy
    nie ma ani jednego okresu — bez rozróżnienia operator zobaczyłby „święto poza sezonem" tam, gdzie
    problemem jest brak konfiguracji. Dlatego: **bez godzin doby sekcja świąt jest wyłączona**
    (jak przełącznik weekendu), a **brak okresów sprzedaży daje własny komunikat walidacji**, nie
    komunikat o wyjściu poza sezon.
    **Ostrzeżenia (zapis przechodzi, operator może tego chcieć):**
    - okno przedsprzedaży otwierające się **po** `starts_on` okresu — to już nie jest „przed"-sprzedaż;
    - zmiana okresu sprzedaży (skrócenie, usunięcie), która zostawia istniejące święto poza sezonem —
      blokowanie porządkowania sezonów kosztowałoby więcej, niż daje, a w czasie działania ratuje to
      przycinanie (reguła 5);
    - **przedsprzedaż na łowisku bez `sale_horizon_days`** — okno wtedy **niczego nie otwiera** (bez
      horyzontu doby okresu i tak są kupowalne), a jedynie **ogranicza** sprzedaż przez
      `presale_min_nights` na czas swojego trwania; to prawie na pewno pomyłka w konfiguracji;
    - `min_nights` łowiska większe niż długość weekendu;
    - **`max_nights` mniejsze niż długość pakietu** — zlany pakiet śr–sob (4 doby) przy
      `max_nights = 3` jest niekupowalny, bo reguła 3 każe objąć cztery doby, a reguła 2 pozwala na
      trzy. Ostrzeżenie, nie błąd: długość weekendu zależy od godzin doby ustawianych na **innym**
      ekranie (patrz „Rozstrzygnięcia", pkt 23).
12. **Każda odmowa niesie powód** (G11), a nie samo „nie" — z rozróżnieniem **sześciu** przyczyn:
    pobyt za krótki, pobyt za długi, przerwany weekend, przerwane święto sprzedawane w całości, poza
    horyzontem, poniżej minimum przedsprzedaży. Weekend i święto to **dwa** komunikaty mimo jednej
    mechaniki — wędkarz musi wiedzieć, czy ma dodać piątek, czy kupić całą Majówkę.
    - **Zlany pakiet niesie „przerwane święto"**, gdy jest w nim choć jedna doba święta — patrz
      „Rozstrzygnięcia", pkt 22. Siódmy powód dla zlanych pakietów nie powstaje.
    - **Sześć wartości dokłada się do `SaleUnavailabilityReason`**, zgodnie z
      [`dostepnosc.md`](../../conventions/dostepnosc.md) §2 („enum powodów jest JEDEN dla całej
      sprzedaży") — pobyt nie dostaje własnego słownika.
    - ⚠️ Każda nowa wartość wymaga **dwóch** zmian w
      [`SaleUnavailabilityReason`](../../../app/Enums/SaleUnavailabilityReason.php): gałęzi
      w wyczerpującym `match` w `label()` — brak którejkolwiek to `UnhandledMatchError` dopiero
      w chwili odmowy (Larastan powinien złapać wcześniej) — oraz **docblocka enuma**, który dziś
      mówi „powód, dla którego **doby** nie da się sprzedać", a po tym zadaniu dotyczy także pobytu.
    - **Odmowa wskazuje, czego dotyczy.** Powód z poziomu doby, przepuszczony z
      `PositionAvailability`, niesie **dobę**, która zawiodła — „stanowisko wycofane" bez daty nic nie
      mówi przy pobycie ośmiodobowym. Powód ze spoiwa niesie **pełny zakres pakietu**, który trzeba
      objąć, a nie sam zakres święta czy weekendu. To pole **nowego** typu wyniku pobytu;
      `FishingDayAvailability` zostaje nietknięte.
13. **Zmiana reguł obowiązuje od daty zapisu i nie rusza tego, co sprzedane** (G6). Model rezerwacji
    jeszcze nie istnieje; reguła powstaje razem z pojęciem, tak jak przy godzinie doby (015).
    Ponieważ nic nie jest zmaterializowane, nie ma „otwartych tygodni" do migrowania.

### Panel — „Sprzedaż i sezony" (zmiana istniejącej strony)

| Element | Kształt |
|---|---|
| przedsprzedaż | w wierszu `Repeater`-a okresów: `Toggle` „Włącz przedsprzedaż tego okresu" `->live()`, pod nim `->visible()` pola: otwarta od, do, najmniej dób przy zakupie w przedsprzedaży, flaga „święta sprzedawane w całości pomijają ten warunek" |
| przełącznik | **pole formularza, nie kolumna** — stan początkowy wynika z wypełnienia dat; klucz zdejmuje `mutateFormDataBeforeSave`, a wyłączenie czyści cztery kolumny (⚠️ nie `->dehydrated(false)` — [`panel-wlasciciela.md`](../../conventions/panel-wlasciciela.md) §6) |
| horyzont | nowa `Section` „Horyzont sprzedaży" z jednym `TextInput` liczbowym, pod okresami |
| nagłówek repeatera | pokazuje zakres i „przedsprzedaż 4–14.11.2026" albo „bez przedsprzedaży" |
| ostrzeżenia przy zapisie | okno otwarte po starcie okresu; **przedsprzedaż bez ustawionego horyzontu** („okno niczego nie otworzy, a ograniczy sprzedaż"); **skrócenie albo usunięcie okresu, które zostawia istniejące święto poza sezonem** — wszystkie trzy z reguły 11 |

### Panel — nowa pozycja sub-nawigacji „Reguły sprzedaży"

| Element | Kształt |
|---|---|
| strona | strona zasobu `FisheryResource` (`getPages()`), wzorem `ManageSaleSettings`; **osobna pozycja** w `getRecordSubNavigation()`, za „Sprzedaż i sezony", przed „Stanowiska" |
| długość pobytu | `Section` z dwoma `TextInput` (min/max) |
| weekend | `Section` z `Toggle` „Weekend sprzedawany wyłącznie w całości" `->live()`; pod nim `CheckboxList`/`ToggleButtons` z **siedmioma dobami jako przedziałami** liczonymi z `day_start_time`/`day_end_time` łowiska (`pt → sob · 15:00 → 15:00`), plus podsumowanie „od piątku 15:00 do niedzieli 15:00 (2 doby)". Przełącznik jest polem formularza; wyłączenie czyści `weekend_days` |
| święta | `Repeater` `->relationship('wholeTermPeriods')`: nazwa, pierwsza doba (`first_day_on`), ostatnia doba (`last_day_on`), `Placeholder` „czyli pobyt" liczony z `FishingDayCalendar` |
| sekcje bez godzin doby | gdy łowisko nie ma jeszcze ustawionych godzin doby, **przełącznik weekendu ORAZ sekcja świąt są wyłączone** z podpowiedzią „najpierw ustaw godziny doby w Sprzedaż i sezony". Bez nich nie ma z czego policzyć ani przedziałów dób, ani podpowiedzi „czyli pobyt", a walidacja mieszczenia się w sezonie zwracałaby mylący powód (reguła 11) |
| ostrzeżenia przy zapisie | `min_nights` większe niż długość weekendu **oraz `max_nights` krótsze niż długość pakietu** — oba z reguły 11 |
| okruszki i powrót po zapisie | `FisheryNavigation::fisheryBreadcrumbs()` i powrót do huba, jak `ManageSaleSettings` |
| autoryzacja | przez `FisheryPolicy` (`EditRecord::authorizeAccess()`), jawnie — jak strona sezonów |

⚠️ **`WholeTermPeriod` NIE dostaje zasobu Filamenta, polityki ani uprawnień Shielda.** To nie jest
oszczędność, tylko niezmiennik: `shield:generate` wyprowadza uprawnienia z **zarejestrowanych
zasobów**, więc polityka pytająca o `'view_any:whole_term_period'` nazwałaby uprawnienie, którego
generator nigdy nie utworzy, i wywróciłaby `ShieldPermissionNamesTest`. Dostępu pilnuje
`FisheryPolicy` — święto istnieje wyłącznie przez łowisko. Niezmiennik i droga wyjścia:
[`autoryzacja.md`](../../conventions/autoryzacja.md) §5.

⚠️ Strona zasobu, a nie strona panelu, ma konkretny skutek: `FisheryResource` jest zarejestrowany
w obu panelach, więc **to zadanie nie dotyka `OwnerPanelProvider` ani `AdminPanelProvider`** i nie
wymaga dopisania uprawnień do listy w `tests/TestCase.php` (inaczej niż 016). Wzorzec:
[ADR-006, aktualizacja z zadania 016](../../adr/ADR-006-natywne-komponenty-filamenta-zamiast-recznych-przeplywow.md).

Model `WholeTermPeriod` z `SoftDeletes`, `LogsActivity` i `HasFactory`, relacja `belongsTo(Fishery)`;
`Fishery` dostaje relację `hasMany` z generykami (`@return HasMany<WholeTermPeriod, $this>` — bez
nich Larastan nie rozwinie typu), cztery nowe kolumny w `$fillable` i cast `weekend_days => 'array'`.
`SalePeriod` dostaje cztery kolumny przedsprzedaży w `$fillable` z castami dat i boolean.

### Walidacja — dom w `app/Rules/`

Reguły spójności zbioru dób weekendu (kolejność, liczność), `min ≤ max`, porządku dat przedsprzedaży
oraz **długości święta (≥ 2 doby) i jego mieszczenia się w okresie sprzedaży** idą do `app/Rules/`,
nie do formularza strony — inaczej druga ścieżka zapisu (import, seed, przyszłe API) obejdzie regułę.
Sprawdzenie dotyczące **zbioru** (doby weekendu) siedzi na całym polu zbioru, nie na pojedynczej
opcji. Reguła mieszczenia się w sezonie **woła `FishingDayCalendar`**, zamiast porównywać daty po
swojemu — inaczej powstałby drugi kod liczący doby ([`dostepnosc.md`](../../conventions/dostepnosc.md) §1).
Nie ma reguły nienachodzenia świąt — nachodzenie jest stanem poprawnym (reguła 6).

### Tłumaczenia

Etykiety pól, tytuł strony, etykieta pozycji w sub-nawigacji, etykiety dób-przedziałów, ostrzeżenia
przy zapisie i **komunikaty odmowy** w `lang/pl.json`. Komunikat odmowy widzi wędkarz, więc jest
częścią interfejsu, a nie treścią operatora.

## Kryteria akceptacji

- [x] Migracje przechodzą w obie strony na bazie zawierającej dane; istniejące okresy sprzedaży
      dostają puste kolumny przedsprzedaży i nic się nie zmienia w ich zachowaniu.
- [x] Łowisko bez minimum, weekendu i świąt sprzedaje pobyt dowolnej długości.
- [x] Pobyt krótszy niż `min_nights` i dłuższy niż `max_nights` jest odrzucany z właściwym powodem.

⚠️ Notacja przypadków niżej: **„od X, N dób (dni)"** — nawias wymienia **dni rozpoczęcia** kolejnych
dób, nigdy dnia wyjazdu.

- [x] **Weekend `{5, 6}`** (doba 15:00→15:00): od soboty, 1 doba (sob) — odmowa „przerwany weekend";
      od soboty, 2 doby (sob–nd) — odmowa; od piątku, 1 doba (pt) — odmowa; od piątku, 2 doby
      (pt–sob) — przechodzi; od czwartku, 2 doby (czw–pt) — odmowa; od czwartku, 3 doby (czw–sob) —
      przechodzi; **od niedzieli, 1 doba (nd) — przechodzi** (doba nd→pon leży poza weekendem).
- [x] Przy `min_nights = 3` i weekendzie `{5, 6}`: od piątku, 2 doby — odmowa **za krótki** (pakiet
      czysto weekendowy nie zwalnia z minimum); od piątku, 3 doby — przechodzi.
- [x] **Zwolnienie i jego monotoniczność (reguła 7):** przy `min_nights = 5` i trzydobowym święcie
      pobyty 3-, 4-, 5- i 6-dobowe obejmujące to święto **wszystkie** przechodzą; pobyt tej samej
      długości niedotykający święta i krótszy niż 5 dób — odmowa.
- [x] **Przycięty pakiet zachowuje zwolnienie:** przy `min_nights = 5` i trzydobowym święcie, którego
      dwie doby są w blokadzie, pobyt jednodobowy na ocalałą dobę przechodzi.
- [x] Pobyt obejmujący część święta jest odrzucany; obejmujący całe święto przechodzi; obejmujący
      święto **wraz z dobami przed i po** przechodzi.
- [x] **Ta sama para dat znaczy co innego w dwóch tabelach:** okres sprzedaży `starts_on = 30.04`,
      `ends_on = 02.05` sprzedaje **dwie** doby (30.04 i 01.05), a święto `first_day_on = 30.04`,
      `last_day_on = 02.05` obejmuje **trzy** (30.04, 01.05, 02.05). Test pinuje obie liczby obok
      siebie.
- [x] **Przycinanie:** blokada sprzedaży w sobotę na całe łowisko → od piątku, 1 doba przechodzi;
      święto z jedną dobą w blokadzie → pobyt obejmujący pozostałe doby przechodzi, obejmujący ich
      część — odmowa.
- [x] **Zlewanie:** święto śr–pt + weekend `{5, 6}` → od środy, 3 doby (śr–pt) — odmowa, a wynik
      wskazuje **pełny zakres pakietu śr–sob**, nie sam zakres święta; od środy, 4 doby (śr–sob) —
      przechodzi. Odmowa niesie powód **„przerwane święto sprzedawane w całości"** (nie „przerwany
      weekend"), bo w pakiecie jest doba święta — rozstrzygnięcie 22.
- [x] **Zlanie a zwolnienie:** święto śr–pt + weekend `{5, 6}` przy `min_nights = 5` → od środy,
      4 doby (śr–sob) przechodzi (pobyt obejmuje pakiet ze świętem, więc nie podlega minimum),
      a od środy, 3 doby jest odrzucane jako **przerwany pakiet**, nie „za krótki" — spoiwo sprawdza
      się przed długością.
- [x] **Przedsprzedaż okresu** (okno otwarte, `presale_min_nights = 5`, doby poza horyzontem):
      1 doba — odmowa „poniżej minimum przedsprzedaży"; weekend 2 doby — odmowa; święto 3 doby —
      przechodzi przy włączonej fladze, odmowa po jej wyłączeniu; 6 dób — przechodzi. Doba okresu
      leżąca **w horyzoncie** kupowana w oknie pojedynczo — również odmowa (reguła 9).
- [x] Po zamknięciu okna te same doby poza horyzontem są odrzucane „poza horyzontem"; doby
      w horyzoncie sprzedają się pojedynczo.
- [x] **Granica horyzontu:** przy `sale_horizon_days = 365` doba rozpoczynająca się dokładnie 365 dni
      po dzisiejszym dniu przechodzi, a 366 dni — odmowa „poza horyzontem" (czas zamrożony).
- [x] **Granice okna przedsprzedaży:** zakup w dniu `presale_opens_on` o 00:01 czasu łowiska
      przechodzi, w dniu `presale_closes_on` o 23:00 — przechodzi, nazajutrz o 00:01 — odmowa.
- [x] **Przedsprzedaż bez horyzontu** zapisuje się, ale daje ostrzeżenie, że okno niczego nie otwiera,
      a jedynie ogranicza sprzedaż przez `presale_min_nights` na czas swojego trwania.
- [x] Okno przedsprzedaży **nie** przepuszcza doby niesprzedawalnej według `PositionAvailability`
      (w blokadzie, na stanowisku wycofanym) ani doby **innego** okresu sprzedaży.
- [x] Horyzont sprawdzany jest dla **każdej** doby pobytu, nie tylko pierwszej.
- [x] Każda odmowa niesie powód rozróżniający **sześć** przyczyn z reguły 12, dołożonych jako wartości
      do `SaleUnavailabilityReason`; gdy zachodzi kilka naraz, wynik jest deterministyczny
      i udokumentowany.
- [x] Odmowa przepuszczona z `PositionAvailability` wskazuje **którą dobę** pobytu odrzucono: pobyt
      ośmiodobowy z blokadą na szóstej dobie zwraca „sprzedaż zablokowana" **wraz z tą dobą**, a nie
      sam powód.
- [x] Usługa **woła `PositionAvailability`** zamiast powtarzać jej warunki — zmiana okresu sprzedaży
      albo założenie blokady zmienia wynik bez dotykania kodu tego zadania.
- [x] Zapis `weekend_days` z dobami nienastępującymi po sobie, z jedną dobą albo z siedmioma jest
      odrzucany; `{7, 1}` (nd→pon + pon→wt) jest przyjmowany jako ciąg cykliczny.
- [x] `min_nights` większe niż `max_nights` jest odrzucane; `presale_closes_on < presale_opens_on`
      jest odrzucane.
- [x] **`max_nights` krótsze niż pakiet zapisuje się z ostrzeżeniem** (nie błędem): przy weekendzie
      `{5, 6}` i `max_nights = 1` konfiguracja przechodzi, a operator dostaje ostrzeżenie, że
      dwudobowego weekendu nie da się kupić. Pobyt dłuższy niż `max_nights` jest nadal odrzucany.
- [x] **Święto jednodobowe** (`last_day_on = first_day_on`) jest odrzucane, tak samo jak
      `last_day_on < first_day_on`.
- [x] **Święto poza sezonem jest odrzucane:** święto z choć jedną dobą poza okresem sprzedaży nie
      zapisuje się — także wtedy, gdy mieści się w sumie dwóch sąsiadujących okresów, ale jego doba
      przecina ich granicę.
- [x] **Odwrotna strona jest ostrzeżeniem, nie błędem:** skrócenie okresu sprzedaży, które zostawia
      istniejące święto poza sezonem, **zapisuje się** z ostrzeżeniem, a doby spoza sezonu wypadają
      z pakietu przez przycinanie (reguła 5).
- [x] Zapis dwóch nachodzących świąt **przechodzi**; święto usunięte miękko nie wpływa na nic.
- [x] Przełącznik weekendu **i sekcja świąt** są wyłączone na łowisku bez ustawionych godzin doby;
      po ich ustawieniu etykiety dób pokazują godziny łowiska.
- [x] **Świeże łowisko nie kłamie o przyczynie:** przy ustawionych godzinach doby, ale bez ani jednego
      okresu sprzedaży, zapis święta jest odrzucany komunikatem o **braku okresu sprzedaży**, a nie
      „święto wykracza poza sezon".
- [x] Wyłączenie przedsprzedaży na okresie czyści cztery kolumny; wyłączenie weekendu czyści
      `weekend_days`.
- [x] Właściciel nie widzi ani nie edytuje reguł cudzego łowiska.
- [x] `ShieldPermissionNamesTest` jest zielony, a w `app/Policies/` **nie przybywa** żaden plik —
      dostępu pilnuje `FisheryPolicy`.
- [x] Trwałe usunięcie łowiska zabiera ze sobą jego święta; w bazie nie zostaje święto bez łowiska.
- [x] Zielony zakres T2 zadeklarowany niżej.
- [ ] **Pełny pakiet testów ODROCZONY** do punktu kontrolnego B (po zadaniach 017–019) — odroczony,
      nie pominięty.

## Zakres testów

- **Tier:** T2
- **Uruchamiamy:** `docker compose exec app php artisan test --filter="StaySellabilityTest|WholeTermPeriodTest|WeekendBundleTest|SaleRulesPageTest|PositionAvailabilityTest|FishingDayTest|SalePeriodTest|SaleSettingsPageTest|AvailabilityBlockTest|ActivityLoggingTest|ShieldPermissionNamesTest|AdminPanelTest|OwnerPanelTest|FisheryAccessTest"`
- **Uzasadnienie:** zadanie trafia w **jeden** wyzwalacz T3 z `CLAUDE.md` — migracje. Polityki nie
  dokłada (autoryzacja idzie przez istniejącą `FisheryPolicy`), providerów paneli nie dotyka (strona
  należy do `FisheryResource`, zarejestrowanego w obu panelach) i nie rusza `tests/TestCase.php`,
  bo nie powstaje nowy zasób. Odstępstwo jest **pakietowe, nie punktowe**: rozdział 14.2
  [wymagań](../../project/etapy/00-konfiguracja-sprzedazy/WYMAGANIA-SPRZEDAZ-KROTKOTERMINOWA.md) ustala T2 dla zadań 014–021 i pełny
  pakiet na punktach kontrolnych; dla tego zadania jest to punkt **B**, po zadaniach 017–019 — tak
  samo jak 014, 015 i 016 rozliczyły się na punkcie A. Zakres T2 obejmuje klasy nowe w tym zadaniu
  oraz wszystko, na czym one stoją albo co dzieli z nimi mechanikę: dostępność doby, wyliczanie dób,
  okresy sprzedaży (zmienione — przedsprzedaż), blokady, ekran sezonów (zmieniony), dziennik zmian,
  nazwy uprawnień, granice obu paneli i zawężanie widoczności do własnych łowisk.
  ⚠️ Nazwy czterech pierwszych klas w filtrze są **propozycją** — jeśli implementacja nazwie je
  inaczej, poprawia filtr razem z nimi, zamiast zostawiać komendę, która cicho nic nie uruchamia.
  ⚠️ `SaleSettingsPageTest` musi dostać przypadki przedsprzedaży i horyzontu — to strona zmieniana,
  nie tylko zależna.
  ⚠️ `ActivityLoggingTest` sprawdza dziś **wyłącznie `Country`**, więc nie zobaczy nowych pól
  `Fishery` i `SalePeriod` — zostaje jako tani bezpiecznik traita `LogsActivity`, nie jako dowód na
  zawartość dziennika.
  ⚠️ Zadanie nie trafia do `docs/tasks/implemented/`, dopóki punkt kontrolny B nie jest zielony.

## Zakres wyłączeń

- **Cennik i wycena pobytu** — zadanie 018. To zadanie mówi, co **wolno** kupić, nie ile to kosztuje;
  odmowa z tego zadania nie zna cen.
- **Kalendarz podglądowy** pokazujący skutek reguł doba po dobie — zadanie 019. Powstaje usługa
  odpowiadająca na pytanie, nie ekran, który je zadaje w pętli. Kalendarz ma pokazać zlewanie
  i przycinanie pakietów (reguły 5 i 6), bo formularz ich nie pokazuje.
- **Rezerwacje, koszyk i płatności** — poza iteracją. Pobyt jest tu **parametrem pytania**, a nie
  zapisywanym rekordem; nic nie powstaje w schemacie, co trzymałoby sprzedany pobyt.
- **Reguły długości zależne od dnia tygodnia** (`stay_length_rules` z pierwszej wersji) — wycięte
  (R5). Wracają wyłącznie wtedy, gdy P17/P18 ujawni u Łopienna regułę typu „przyjazd w sobotę min.
  3 doby" **bez** nierozdzielności — jedyny przypadek, którego spoiwo nie zastąpi.
- **Zakaz dnia przyjazdu** jako pojęcie w formularzu — odrzucony (niezrozumiały dla operatora).
  Wraca tylko, jeśli łowisko odpowie na pytanie 7a „w niedzielę nie przyjmujemy przyjazdów".
- **Weekend różny per okres sprzedaży** (np. tylko w sezonie wysokim) — opcja na przyszłość; dziś
  jedno pole na łowisku, przeniesienie na `sale_periods` jest tanią migracją, gdy pojawi się potrzeba.
- **Godziny przyjmowania zamówień i godzina odcięcia sprzedaży na dziś** — rozstrzygnięte poza
  zakresem (P10, O20): portal pracuje 24/7.
- **Limity liczby rezerwacji na wędkarza i pierwszeństwo dla stałych klientów** — brak właściciela
  w tym wdrożeniu.
- **Reguły zależne od posiadania pozwolenia długookresowego** — obowiązuje Z4: nie budujemy, ale nie
  zamykamy drzwi.
- **Turnus jako samodzielny produkt** — święto sprzedawane w całości jest **regułą nałożoną na doby**
  (O2), a nie osobnym bytem sprzedażowym z własną ceną.
- **Dopisywanie okresów sprzedaży, reguł ani horyzontu istniejącym łowiskom** — migracja tego nie robi.
- **Procentowa obniżka ceny dla zakupów w przedsprzedaży** — opcja na przyszłość (ustalenie
  z 2026-09-20): kolejna kolumna obok minimum przedsprzedaży na okresie sprzedaży, konsumowana przez
  cennik (018+). Model ma jej nie blokować, ale to zadanie jej nie buduje.

## Zmiany dokumentacji

- [x] `docs/conventions/dostepnosc.md` — nowa sekcja: **pojęcie pobytu**, granica między usługą
      dostępności doby a usługą reguł pobytu, **spoiwo dób** (weekend i święto jako jedna mechanika),
      zasady **przycinania** i **zlewania**, **zwolnienie z minimum jako własność pakietu
      zawierającego święto** (zero-jedynkowe, bez arytmetyki — patrz „Rozstrzygnięcia", pkt 16),
      kolejność sprawdzania, kształt odmowy (sześć nowych wartości
      `SaleUnavailabilityReason` plus wskazanie doby albo zakresu) oraz asymetria „brak okresu
      sprzedaży = odmowa, brak reguły pobytu = brak ograniczenia".
      ⚠️ Dopisać też **różnicę znaczeń granic**: `sale_periods.ends_on` (ostatni dzień okna, ostatnia
      doba zaczyna się dzień wcześniej) kontra `whole_term_periods.last_day_on` (dzień rozpoczęcia
      ostatniej objętej doby) — to jest pułapka, którą łatwo skopiować w złą stronę
- [x] `docs/conventions/panel-wlasciciela.md` — §6: nowa strona ustawień „Reguły sprzedaży" i jej
      miejsce w kolejności; przedsprzedaż jako pola w wierszu repeatera okresów; **przełącznik, który
      nie jest kolumną** (weekend, przedsprzedaż) — stan z danych, czyszczenie przy wyłączeniu;
      **doby pokazywane jako przedziały z godzin doby**, nigdy jako same nazwy dni; przełącznik
      weekendu wyłączony bez godzin doby
- [x] `README.md` — bez zmian
- [x] `CLAUDE.md` — bez zmian; niezmienniki powierzchni idą do `docs/conventions/`
- [x] `MANUAL.md` — rozszerzenie sekcji „Sprzedaż i sezony" o przedsprzedaż okresu i horyzont;
      nowa sekcja „Reguły sprzedaży" (długość pobytu, weekend sprzedawany w całości — z wyjaśnieniem,
      że zaznacza się doby od godziny do godziny, święta sprzedawane w całości), opisana
      z perspektywy operatora; dopisać pozycję do listy „Kolejność wprowadzania danych" (§2),
      zaraz za „Sprzedaż i sezony".
      ⚠️ `MANUAL.md` jest podzielony na **Część I — Łowisko (panel właściciela)** i **Część II — Admin**.
      Cała ta zmiana należy do Części I; numeracja rozdziałów jest ciągła przez oba działy, więc nowa
      sekcja wchodzi między dzisiejsze §4 i §5 i **przenumerowuje wszystko dalej**, razem
      z odsyłaczami w §2 i w Części II
- [x] `docs/project/etapy/00-konfiguracja-sprzedazy/WYMAGANIA-SPRZEDAZ-KROTKOTERMINOWA.md` — §0.1: M4 z „w trakcie
      przeprojektowania" na **„Zrealizowane"** z odsyłaczem do konwencji, wzorem M1/M2/M5
- [x] `CHANGELOG.md` — wpis w changelogu

## Ograniczenia techniczne

- Laravel 13, Filament 5, PHP 8.4 — stan po zadaniu 009.
- **Osobny plik migracji na każdą tabelę**: `whole_term_periods` (nowa), zmiana w `fisheries`
  (cztery kolumny), zmiana w `sale_periods` (cztery kolumny przedsprzedaży).
- **Zadanie idzie po 016** i korzysta z: pojęcia doby i reguł granic
  ([ADR-010](../../adr/ADR-010-doba-wedkarska-jako-przedzial-czasu.md)), okresów sprzedaży,
  stanu stanowiska oraz `PositionAvailability`
  ([ADR-012](../../adr/ADR-012-jedno-zrodlo-prawdy-o-dostepnosci.md)). **Nie liczy dób po swojemu**
  i nie powtarza warunków dostępności; etykiety dób-przedziałów i „czyli pobyt" liczy
  `FishingDayCalendar`.
- Wszystkie porównania dat idą w **strefie czasowej łowiska**, nie aplikacji.
- Horyzont i okno przedsprzedaży zależą od „dzisiaj", więc testy muszą zamrażać czas — inaczej
  zzielenieją albo zaczerwienią się zależnie od dnia uruchomienia.
- Nazwy tabel, kolumn, klas i tras po angielsku; dokumentacja po polsku.
- Testy biegną na MySQL-u w schemacie `lowiska_test`.

## Rozstrzygnięcia

Ustalenia z przeglądu zadania 2026-09-20 (wywiad z autorem + analiza „Przemodelowanie obszaru co
wolno kupić jako pobyt"). Wiążą implementację tak samo jak decyzje z ADR-ów.

1. **Reguły pobytu mieszkają na OSOBNEJ pozycji sub-nawigacji „Reguły sprzedaży"**, za „Sprzedaż
   i sezony" — jedno pytanie na ekran: KIEDY vs JAKI POBYT.
2. **Przedsprzedaż jest właściwością okresu sprzedaży** (cztery kolumny na `sale_periods`, przełącznik
   w wierszu repeatera), nie osobną tabelą — zakres dób to zakres okresu, nie da się skonfigurować
   okna poza sezonem; kilka rozłącznych okresów to kilka włączeń, raz w roku, i bywa pożądane
   (styczniowy okres może przedsprzedaży nie mieć).
3. **Horyzont sprzedaży na „Sprzedaż i sezony"** — odpowiada na pytanie KIEDY. Na teraz; docelowe
   miejsce może się jeszcze przesunąć.
4. **`weekend_days` to kolumna JSON na `fisheries`, nie tabela** — zapis jest tak wąski jak UI;
   elastyczność zostaje w usłudze, która pracuje na abstrakcyjnym zbiorze dób.
5. **Reguły długości per dzień tygodnia wycięte** (R5) — po wprowadzeniu spoiwa żadne z dwóch łowisk
   ich nie potrzebuje; jedyny scenariusz powrotu opisany w „Zakresie wyłączeń".
6. **Doby weekendu pokazuje się jako przedziały z godzin doby łowiska** („pt → sob · 15:00 → 15:00"),
   nigdy jako same nazwy dni — operator zaznaczałby „trzy dni" zamiast dwóch dób. Ten sam zabieg
   („czyli pobyt") przy świętach.
7. **Przełączniki weekendu i przedsprzedaży są polami formularza, nie kolumnami** — stan wynika
   z danych, wyłączenie czyści dane.
8. **Przycinanie, nie unieważnianie** (reguła 5) — wspólnie dla weekendów i świąt.
9. **Zlewanie, nie zakaz nachodzenia** (reguła 6) — brak reguły nienachodzenia świąt.
10. **Flaga bypass domyślnie WŁĄCZONA i dotyczy wyłącznie świąt datowanych** (P15/K4); weekend sam
    z siebie nigdy nie zwalnia z minimum przedsprzedaży, ale pobyt obejmujący pakiet zlany ze świętem
    jest z niego zwolniony wraz z całym pakietem (reguła 7).
11. **Minimum przedsprzedaży obowiązuje każdy zakup dób okresu w oknie**, także dób w horyzoncie —
    przedsprzedaż jest ofertą hurtową. Świadoma konsekwencja: w oknie nie da się kupić pojedynczej
    doby sezonu objętego przedsprzedażą; po zamknięciu okna — da się, o ile leży w horyzoncie.
12. **Tier T2 utrzymany** mimo wyzwalacza „migracje", na podstawie decyzji pakietowej z rozdziału
    14.2 wymagań.
13. ⚠️ **Doba niedzielna (`nd 15:00 → pon 15:00`) leży poza weekendem i sprzedaje się jak zwykły
    dzień** — założenie robocze autora, **potwierdzenie z łowiska w toku** (pytanie 7a w
    [`PYTANIA-DO-WLASCICIELA.md`](../../project/PYTANIA-DO-WLASCICIELA.md)). Odpowiedź „weekend trwa do
    poniedziałku 15:00" to zmiana danych (`{5, 6, 7}`), nie modelu; odpowiedź „w niedzielę nie
    przyjmujemy przyjazdów" wymaga dołożenia pojęcia dnia przyjazdu i **wstrzymuje `/implement-task`**.
14. **Pobyt obejmujący święto datowane jest zwolniony z `min_nights`** (K5), pakiet czysto weekendowy
    nie zwalnia z niczego — „spoiwo tylko zaostrza, święto może łagodzić".
15. **Kolumny święta nazywają się `first_day_on`/`last_day_on`, nie `starts_on`/`ends_on`** — bo
    znaczą co innego niż w `sale_periods`, gdzie `ends_on` jest ostatnim dniem okna, a ostatnia
    sprzedawalna doba zaczyna się dzień wcześniej. Różne znaczenie dostaje różną nazwę, żeby nikt nie
    skopiował logiki granic; test pinuje obie interpretacje obok siebie.
16. **Pobyt obejmujący pakiet ze świętem jest ZWOLNIONY z minimum** — zero-jedynkowo, bez żadnej
    arytmetyki (reguła 7). Rozważany był zapis „minimum obniżone do długości pakietu"
    (`min(min_nights, Σ|P|)`), ale **daje dokładnie ten sam wynik**: pobyt dochodzący do sprawdzenia
    długości już obejmuje każdy dotknięty pakiet w całości (reguła 3), więc nigdy nie jest od niego
    krótszy. Wybrano prostszy zapis, żeby implementacja nie sumowała pakietów bez potrzeby, a
    konwencja nie utrwaliła obliczenia, które nigdy nie zmienia werdyktu. Uzasadnienie zostaje to
    samo: monotoniczność (inaczej „3 przechodzi, 4 odmowa, 5 przechodzi") i rozstrzygnięcie zlania
    w stronę, w której dołożenie weekendu nie czyni święta niekupowalnym.
17. **Sześć powodów odmowy pobytu to nowe wartości `SaleUnavailabilityReason`** — wiąże konwencja
    [`dostepnosc.md`](../../conventions/dostepnosc.md) §2 („enum powodów jest JEDEN dla całej
    sprzedaży"). Osobny słownik dla pobytu byłby **zmianą konwencji** do zapisania, a nie pytaniem
    otwartym, więc nie zostaje jako pytanie.
18. **Odmowa niesie wskazanie doby albo zakresu** (reguła 12) — powód z poziomu doby bez daty jest
    bezużyteczny przy pobycie wielodobowym, a powód ze spoiwa bez pełnego zakresu pakietu nie mówi
    wędkarzowi, ile ma dobrać.
19. **Granice są domknięte:** horyzont obejmuje dobę rozpoczynającą się dokładnie
    `sale_horizon_days` dni po dziś; okno przedsprzedaży obejmuje całe dni brzegowe, jak okno
    blokady. Wybór za domknięciem: etykiety mówią „365 dni w przód" i „sprzedaż otwarta od–do", więc
    czytelnik zakłada włącznie; różnica jednego dnia na krańcu nie ma skutku biznesowego.
20. **Święto obejmuje co najmniej dwie doby** (`last_day_on > first_day_on`), tak samo jak weekend.
    Święto jednodobowe przeszłoby walidację dat, ale niczego by nie ograniczało — „w całości" jest
    wtedy puste — a jedynym jego skutkiem byłoby **zwolnienie tej doby z minimum** (reguła 7), czyli
    działanie, którego nazwa pola nie opisuje. Żadne z dwóch łowisk takiego wpisu nie potrzebuje (R5),
    a gdyby potrzeba „wyjątku od minimum na konkretny dzień" kiedyś się pojawiła, należy jej się
    **własne pole z własną nazwą**, a nie obejście przez święto, którego operator musiałby się
    nauczyć z instrukcji.
    ⚠️ Walidacja nie usuwa pakietu jednodobowego **w czasie działania** — potrafi go zrobić
    przycinanie z wielodobowego święta, i taki pakiet zwolnienie zachowuje (reguła 7).
21. **Święto musi mieścić się w okresie sprzedaży** — dawne pytanie 3 („czy święto musi mieścić się
    w okresie sprzedaży") zamknięte na „zakaz przy zapisie".
    Zapis święta, którego choć jedna doba wypada poza sezon, jest **błędem**; sprawdza to
    `FishingDayCalendar` tą samą regułą zawierania co sprzedaż doby, więc doba na styku dwóch
    sąsiadujących okresów również nie przechodzi. Odwrotna strona — zmiana okresu zostawiająca
    istniejące święto poza sezonem — jest tylko **ostrzeżeniem**, bo blokowanie porządkowania sezonów
    kosztuje więcej, niż daje, a w czasie działania ratuje to przycinanie (reguła 5). Ryzyko
    „wybaczania kolejności wprowadzania danych" jest w praktyce małe: okresy sprzedaży stoją w
    sub-nawigacji i w instrukcji **przed** regułami, a łowisko powstaje niesprzedające.
    ⚠️ Skutek uboczny wart odnotowania: „granica sezonu cicho skraca pakiet" zostaje wyłącznie przy
    **weekendzie**, który nie ma dat i nie daje się sprawdzić przy zapisie.

22. **Zlany pakiet odmawia jako „przerwane święto sprzedawane w całości"**, gdy jest w nim choć jedna
    doba święta — mimo że przerwany został pakiet zawierający także weekend (`/review-task`,
    2026-09-21). Uzasadnienie: to święto nadaje pakietowi zwolnienie z minimum (reguła 7), więc jest
    jego cechą dominującą, a nazwa własna święta jest dla wędkarza rozpoznawalna. Siódmy powód
    („przerwany pakiet") **nie powstaje** — sześć wartości z reguły 12 zostaje bez zmian. Wskazanie
    pełnego zakresu pakietu (rozstrzygnięcie 18) niesie całą informację o tym, ile dobrać; etykieta
    mówi wyłącznie, czego dotyczy.
23. **`max_nights` ZOSTAJE w zakresie**, a konflikt „pakiet dłuższy niż maksimum" jest **ostrzeżeniem
    przy zapisie**, nie błędem (`/review-task`, 2026-09-21). Zostaje, bo wymieniają ją wymagania M4,
    a to jedna kolumna nullable w tabeli, która i tak się zmienia — test taniej elastyczności
    z rozdziału 11. Ostrzeżenie, a nie błąd, bo długość weekendu zależy od godzin doby ustawianych na
    **innym** ekranie: twarde odrzucenie dawałoby operatorowi błąd w miejscu, którego nie edytuje,
    i blokowałoby porządkowanie sezonu w dowolnej kolejności. Traktujemy to jak `min_nights` większe
    niż długość weekendu (reguła 11).
24. **Nazwy: `StaySellability` (usługa) i `StaySellabilityVerdict` (typ wyniku)**, reguła zbioru dób
    `WeekendDaysAreContiguous`, sześć wartości enuma `StayTooShort`, `StayTooLong`, `WeekendBroken`,
    `WholeTermBroken`, `BeyondSaleHorizon`, `BelowPresaleMinimum` (`/review-task`, 2026-09-21).
    „Sellability" zamiast „availability" jest celowe: [`dostepnosc.md`](../../conventions/dostepnosc.md)
    §2 rezerwuje „dostępność" dla warstwy doby, a klasa o nazwie `StayAvailability` czytałaby się jak
    **trzecie źródło prawdy o dostępności** — czyli dokładnie ta pułapka, przed którą ostrzega
    ADR-013. Filtr testów w „Zakresie testów" używa już tych nazw.

## Powiązane ADR-y

- [ADR-013 — Warstwa reguł pobytu: spoiwo dób, kolejność sprawdzania i kształt odmowy](../../adr/ADR-013-warstwa-regul-pobytu-i-spoiwo-dob.md)
  — ⚠️ sekcja **Decyzja** jest pusta; `/implement-task` zatrzyma się, dopóki autor jej nie wypełni.
  ADR rozstrzyga naraz: osobną warstwę pobytu komponującą `PositionAvailability`, spoiwo jako jedną
  mechanikę weekendu i święta, **kolejność sprawdzania** (dawne otwarte pytanie 2) oraz przynależność
  zwolnienia z minimum do pakietu.

## Otwarte pytania — rozstrzygnięte przez `/review-task` 2026-09-21

Żadne nie zostaje otwarte. Zapis dla historii przeglądu:

1. **Czy warstwa reguł pobytu ze spoiwem zasługuje na ADR-a?** → **TAK**, powstał
   [ADR-013](../../adr/ADR-013-warstwa-regul-pobytu-i-spoiwo-dob.md). Spełnione wszystkie trzy warunki
   kryterium z `CLAUDE.md`. Kontrargument („to tylko zastosowanie ADR-012") odrzucony: ADR-012 nie
   mówi nic o tym, czy weekend i święto są jedną mechaniką, ani komu należy się zwolnienie z minimum,
   a odwrócenie żadnej z tych decyzji nie jest zmianą w `PositionAvailability`.
2. **Kolejność sprawdzania** → wciągnięta do ADR-013 jako część tej samej decyzji, precedensem
   ADR-012, który z tego samego powodu połączył „gdzie mieszka", „w jakiej kolejności" i „co zwraca".
   Kryterium akceptacji czekające na odpowiedź zostało dopięte przez **rozstrzygnięcie 22**.
3. **Czy `max_nights` ma właściciela?** → **rozstrzygnięcie 23** (zostaje; konflikt z pakietem jest
   ostrzeżeniem). Anti-sygnał z `CLAUDE.md`: wartość progu i pojedyncze pole — nie ADR.
4. **Nazwy klas i wartości enuma** → **rozstrzygnięcie 24**. Anti-sygnał wprost z `CLAUDE.md`:
   „nazwa pola/trasy/kolumny" nie jest decyzją architektoniczną.
