# ADR-014 — Cennik jako lista reguł z warunkami: rozstrzyganie i kształt nierozstrzygalności

- **Status:** ⚠️ **częściowo odwrócona** (accepted → superseded-in-part, 2026-09-22) —
  patrz [Aktualizacja](#aktualizacja-2026-09-22--odwrócenie-decyzji-o-rozstrzyganiu)
- **Data:** 2026-09-22
- **Zadanie:** [018 — Cennik regułowy](../tasks/018-cennik-regulowy.md)

> ⚠️ **Czytasz ADR, którego decyzja została częściowo odwrócona tego samego dnia, po pierwszej
> implementacji.** Treść poniżej **zostaje nietknięta** jako zapis tego, co rozważano i dlaczego
> Opcja A wtedy wygrała. **Obowiązujący stan jest w sekcji „Aktualizacja" na końcu pliku** —
> zacznij od niej, jeśli szukasz reguły na dziś.

## Kontekst

Po zadaniach 015, 014, 016 i 017 system wie, **czym** handluje, **czym** dysponuje, **kiedy jest
wyłączony** i **co wolno kupić**. Nie wie, **ile to kosztuje**: ceny istnieją dziś wyłącznie tam,
gdzie nie rozstrzygają o sprzedaży doby (`additional_services.price`, `long_term_permits`).

Rozdział 11 [wymagań](../project/WYMAGANIA-SPRZEDAZ-KROTKOTERMINOWA.md) rozstrzygnął już **biznesowo**,
że stawki mają być warunkowe i samoobsługowe. Nierozstrzygnięte zostaje to, co przesądza o kształcie
schematu i o każdym miejscu pytającym o cenę: **jak silnik wybiera stawkę i co robi, gdy nie umie
wybrać.**

⚠️ **Rzecz, której nie widać z samego wymagania: po dopuszczeniu stawek warunkowych nachodzenie się
reguł jest stanem ZAMIERZONYM, nie błędem.** „70 zł zawsze" koliduje z „90 zł w piątki" w każdy
piątek — i tak właśnie operator chce to zapisać. Silnik musi więc mieć regułę wyboru, a nie zakaz
nachodzenia; a skoro tak, musi też mieć odpowiedź na pytanie, co zrobić, gdy dwie reguły są
**nierozróżnialne**.

⚠️ **Drugą rzeczą niewidoczną z wymagań jest to, że „cena" przestaje być tylko ceną.** Doba
w otwartym sezonie, do której nie pasuje żadna stawka, nie kosztuje zero — **nie da się jej
sprzedać**. Cennik zaczyna więc współdecydować o sprzedawalności, co ma osobne skutki opisane
w [ADR-015](ADR-015-warstwa-oferty-pobytu.md).

Do rozstrzygnięcia są trzy rzeczy naraz, bo każda osobno nie ma sensu — tak samo jak w ADR-012:
**czym jest cennik** (model), **jak wybiera zwycięzcę** (kolejność) i **co zwraca, gdy wybrać nie
umie** (kształt nierozstrzygalności).

## Alternatywy

### Opcja A — lista reguł z warunkami; priorytet → szczegółowość osiami → remis jako BŁĄD KONFIGURACJI zwracany wynikiem

Cennik jest **listą reguł** (`price_rules`), nie tabelą stawek po wymiarach. Reguła ma kwotę,
priorytet, okres obowiązywania zapisu i zestaw warunków; dwa rodzaje odróżnia flaga: `rate`
**zastępuje** stawkę, `surcharge` **dodaje się**. Dodanie wymiaru cennika jest dodaniem warunku,
czasowa obniżka — zawieszeniem reguły, zmiana ceny wyjściowej — zmianą jednej liczby.

Rozstrzyganie dla jednej doby i jednej roli uczestnika:

1. odrzuć reguły zawieszone i spoza okresu obowiązywania;
2. odrzuć reguły, których którykolwiek warunek nie jest spełniony **dla tej doby**;
3. spośród `rate` wybierz **najwyższy priorytet**, przy remisie **wyższą szczegółowość**,
   a przy remisie nierozstrzygalnym **zwróć informację o błędzie konfiguracji**;
4. **wszystkie** pasujące `surcharge` sumują się — przy kwotach wynik nie zależy od kolejności.

**Szczegółowość liczy się OSIAMI, nie polami.** Osie są cztery: dni tygodnia, zakres dat, liczba
łowiących, rola uczestnika. Zakres z jednym otwartym końcem to **jedna** oś, nie pół ani dwie.

**Remis jest błędem zapisu**, a wykrywa się go dwustopniowo: osie niezależne od doby muszą się
przecinać każda z osobna (**oś pusta przecina się ze wszystkim**), a **dni tygodnia i zakres dat
sprawdza się RAZEM** — kolizja wymaga istnienia doby w przecięciu zakresów, której dzień rozpoczęcia
należy do przecięcia zbiorów dni.

**Nierozstrzygalność wraca WYNIKIEM, nie wyjątkiem.**

**Zalety:**
- **Dodanie wymiaru nie jest migracją.** Nowy wymiar cennika to nowy warunek na regule; tabela
  stawek wymagałaby kolumny albo drugiego wymiaru klucza za każdym razem.
- **Zawieszenie i planowana zmiana ceny wyrażają się wprost.** Klasztorne zawiesza dziś dopłatę za
  wyłączność, a „od 1 czerwca podnoszę cenę wakacji" to nowa reguła z własnym okresem obowiązywania
  — jedno i drugie bez kasowania czegokolwiek i bez drugiej kopii cennika.
- **Remis jako błąd realizuje G2 w obu połowach: deterministycznie I wyjaśnialnie.** Cena
  rozstrzygnięta po cichu jakąkolwiek regułą techniczną („wyższa kwota", „nowszy wpis") jest ceną,
  której operator nie zamierzał i której nie widzi — a przy stawkach samoobsługowych to znaczy, że
  dowolna nowa reguła może cicho zmienić cenę w zupełnie innym miejscu cennika.
- **Szczegółowość osiami, nie polami, jest jedyną wersją odporną na otwarte końce.** Licząc pola,
  zakres `first_day_on`–`last_day_on` ważyłby dwa razy tyle co zbiór dni tygodnia, a ten sam zakres
  z otwartym końcem — raz tyle; szczegółowość zależałaby więc od tego, czy operator domknął przedział.
- ⚠️ **Sprawdzanie dni tygodnia i zakresu dat RAZEM nie jest optymalizacją — bez tego walidacja
  odrzuca poprawne cenniki.** Stawka „30.04–02.05" i stawka „piątki" (oba priorytet 0, szczegółowość
  1) sprawdzane oś po osi kolidują **zawsze**: oś dni przecina się, bo pierwsza reguła jej nie ma,
  a oś dat — bo druga jej nie ma. Naprawdę kolidują tylko wtedy, gdy w tym zakresie wypada piątek.
  Ponieważ remis jest błędem zapisu, wersja oś-po-osi **nie pozwoliłaby zapisać** cennika, w którym
  nie ma żadnej kolizji.
- **Nierozstrzygalność zwracana wynikiem nie wywraca widoku.** Kalendarz podglądowy (019) jest tym
  miejscem, w którym operator ma taki błąd zobaczyć; wyjątek przerwałby cały kalendarz przez jedną
  złą parę reguł, czyli ukryłby błąd dokładnie tam, gdzie miał być widoczny.
- **Osoba towarzysząca nie jest gałęzią w kodzie** — to reguła `rate` z warunkiem roli i kwotą 0,00.

**Wady:**
- **Rozstrzyganie trzeba zaimplementować i utrzymać.** Tabela stawek nie ma czego rozstrzygać.
- ⚠️ **Zasada „stawka osoby towarzyszącej ma najwyższy priorytet" jest konwencją konfiguracji, nie
  własnością modelu.** Każda stawka warunkowa bez warunku roli pasuje także do towarzyszącej;
  walidacja remisu łapie priorytet **równy**, ale przed **wyższym** broni wyłącznie ostrzeżenie.
  Model nie umie tego wymusić i nie należy go do tego zmuszać — ale operator musi o tym wiedzieć.
- **Wykrywanie remisu porównuje pary, nie analizuje przesłaniania.** Remis dwóch reguł jest błędem
  także wtedy, gdy trzecia, szczegółowsza i tak wygrywa w całym przecięciu. Pełna analiza pokrycia
  jest nieproporcjonalnie droga wobec obejścia, którym jest podniesienie priorytetu o jeden.
- **Dziura w cenniku jest wykrywalna tylko pomocniczo.** Ostrzeżenie przy zapisie sprawdza dzisiejszy
  stan reguł i nie widzi dziury otwartej później — wydłużeniem sezonu, podniesieniem pojemności
  stanowiska albo wygaśnięciem reguły w połowie sezonu. Gwarancją jest odmowa przy sprzedaży.

### Opcja B — tabela stawek po wymiarach

Cena stoi w tabeli kluczowanej wymiarami: dzień tygodnia × zakres dat × obsada × rola.

**Zalety:**
- Zero rozstrzygania: dla danej kombinacji istnieje co najwyżej jeden wiersz, więc nie ma remisów,
  nie ma priorytetów i nie ma czego walidować.
- Formularz jest tabelą, którą operator widzi w całości — bez pytania „która reguła wygra".

**Wady:**
- ⚠️ **Każdy nowy wymiar cennika jest migracją**, a M3 wymienia wymiary, których dziś nie budujemy
  (progi za kolejne osoby, dopłata za wędkę, cena per stanowisko). Model, w którym „nie teraz" znaczy
  „migracja za rok", zamyka drzwi, które wymagania każą trzymać uchylone.
- **Nie wyraża zawieszenia ani planowanej zmiany.** Zawieszenie dopłaty to usunięcie wiersza
  i wpisanie go z powrotem; „od czerwca inna cena wakacji" wymaga drugiego wymiaru czasu **w kluczu**,
  czyli podwojenia tabeli.
- **Kombinatoryka rośnie z iloczynu wymiarów.** Cztery osie to już dziesiątki wierszy dla łowiska,
  które realnie potrzebuje **jednej** stawki i **jednej** dopłaty — oba łowiska klienta właśnie tyle
  potrzebują.
- Nie spełnia kluczowego wymagania architektonicznego M3, które wprost mówi „lista reguł z warunkami,
  nie tabela stawek po wymiarach".

### Opcja C — lista reguł, ale remis rozstrzygany deterministycznie bez błędu

Jak opcja A, z jedną różnicą: przy równym priorytecie i równej szczegółowości wygrywa reguła wybrana
regułą techniczną — na przykład o **najwyższej kwocie** albo **najnowsza** (największe `id`).

**Zalety:**
- Wycena **nigdy** nie odmawia z powodu konfiguracji; zawsze jest jakaś cena.
- Zapis cennika nigdy nie jest blokowany, więc operator nie trafia na błąd, którego może nie rozumieć.
- Wynik jest formalnie deterministyczny, więc litera G2 („deterministyczne rozstrzyganie") jest
  spełniona.

**Wady:**
- ⚠️ **Spełnia literę G2, ale łamie jego sens.** Determinizm bez wyjaśnialności znaczy, że cena jest
  powtarzalna, ale nikt nie umie powiedzieć **dlaczego taka** — a stawki mają być samoobsługowe.
- ⚠️ **Dowolna nowa reguła może cicho zmienić cenę w innym miejscu cennika.** „Najwyższa kwota
  wygrywa" zamienia pomyłkę w podwyżkę, „najnowsza wygrywa" — w zależność ceny od kolejności
  wpisywania. Operator nie ma jak tego zauważyć, bo nic się nie zapala.
- **Odbiera powód do istnienia priorytetowi.** Jeśli remis rozstrzyga się sam, jawny priorytet staje
  się ozdobą — a jest jedynym narzędziem, którym operator wyraża własną intencję.
- **Ukrywa jedyny przypadek, w którym cennik naprawdę jest sprzeczny.** Remis nierozstrzygalny to nie
  sytuacja brzegowa, a zapis, w którym operator powiedział dwie rzeczy naraz; jedyną użyteczną
  odpowiedzią jest „rozstrzygnij to sam".

## Rekomendacja

**Opcja A**, w całości: lista reguł z warunkami, dwa rodzaje odróżnione flagą, rozstrzyganie
„priorytet → szczegółowość liczona osiami → błąd konfiguracji", sumowanie dopłat oraz
nierozstrzygalność i brak stawki zwracane **wynikiem, nie wyjątkiem**.

Rozstrzyga to, czego pozostałe opcje nie potrafią:

1. **Opcja B nie spełnia wymagania architektonicznego M3** i zamienia każde przyszłe „nie teraz"
   w migrację. To nie kwestia gustu — wymagania wprost wymieniają wymiary do dołożenia później.
2. **Opcja C spełnia literę G2 i łamie jego sens.** Cena rozstrzygnięta po cichu regułą techniczną
   jest ceną, której operator nie zamierzał; przy samoobsłudze to znaczy, że dowolna nowa reguła może
   zmienić cenę gdzie indziej bez śladu.
3. **Zwracanie nierozstrzygalności wynikiem, a nie wyjątkiem**, jest tym, co pozwala kalendarzowi
   z 019 pokazać taki błąd zamiast się na nim wywrócić. Wyjątek ukryłby błąd w jedynym miejscu,
   w którym miał być widoczny.

⚠️ **Rekomendacja świadomie przyjmuje trzy ograniczenia jako zamierzone, nie jako luki:** remis
dwóch reguł jest błędem także przy istnieniu trzeciej, szczegółowszej; ostrzeżenie o dziurze w cenniku
widzi tylko dzisiejszy stan reguł; a zasada „stawka towarzyszącej ma najwyższy priorytet" jest
konwencją konfiguracji, której model nie wymusza i przed której złamaniem w górę broni wyłącznie
ostrzeżenie.

⚠️ **Czego rekomendacja NIE rozstrzyga:** kosztu wyceny, gdy kalendarz (019) zapyta o iloczyn dób,
stanowisk i obsad. To ten sam nieznany, który ADR-013 zostawił przy liczeniu instancji pakietów,
i jeśli okaże się realny, będzie osobną decyzją z własnymi **pomiarami** — nie rozwinięciem tej.

## Decyzja
Decyzja: A

---

## Aktualizacja (2026-09-22) — odwrócenie decyzji o rozstrzyganiu

**Co się stało:** Opcja A została zaimplementowana (commit `08c8d38`), a następnie **odrzucona
w części dotyczącej rozstrzygania i osi warunku**. Decyzję odwrócił autor projektu po zetknięciu
z gotowym formularzem. Pełne streszczenie rozmowy jest w
[zadaniu 018](../tasks/018-cennik-regulowy.md), sekcja „Historia kształtu".

### Co pozostaje w mocy

**Fundament Opcji A wobec Opcji B (tabela stawek po wymiarach) nie jest kwestionowany.** Cennik
nadal jest **listą reguł z warunkami**, nadal ma dwa rodzaje reguł na jednej tabeli (`rate`
zastępuje, `surcharge` dodaje), nadal zawiesza reguły zamiast je kasować, nadal zwraca brak ceny
**wynikiem, a nie wyjątkiem**, i nadal traktuje dziurę w cenniku jako odmowę sprzedaży. Argument
z Rekomendacji, że Opcja B zamienia każde przyszłe „nie teraz" w migrację, **stoi**.

### Co zostaje odwrócone

| Element decyzji A | Nowy stan |
|---|---|
| **cztery osie warunku** na każdej regule (dni tygodnia, zakres dat, obsada, rola) | **stawka ma wyłącznie zakres dat**; dopłata ma zakres dat, dni tygodnia, obsadę oraz pole **„dla kogo"** (`applies_to`) |
| **jawny `priority`**, wyższa liczba wygrywa | **usunięty**; kolumna znika z tabeli |
| przy remisie priorytetu — **szczegółowość** (liczba wypełnionych osi) | **usunięta** wraz z `specificity()` |
| **nierozstrzygalny remis = błąd konfiguracji** blokujący zapis | **nie istnieje**; nachodzenie jest dozwolone, wygrywa **najniższa kwota za łowiącego**, a nierozstrzygalność jest niemożliwa z konstrukcji |
| **rola jako oś warunku** (`participant_role`) | na **stawce** — zastąpiona kolumną `amount_companion`; na **dopłacie** — zastąpiona polem `applies_to` (`everyone`/`angler`/`companion`, nowy enum `SurchargeAudience`), **domyślnie `angler`**, bo domyślne „dla każdego" przywracałoby przez wartość domyślną tę samą pułapkę z darmową osobą towarzyszącą. `ParticipantRole` zostaje wyłącznie etykietą roli w rozbiciu |
| **dwa wymiary czasu** (`effective_*` obok `first_day_on`/`last_day_on`) | **jeden**: `first_day_on`/`last_day_on` mówią, których dób reguła dotyczy |
| — | **nowość: automatyczne domykanie** — utworzenie stawki **bezterminowej** od daty `D` domyka poprzednią bezterminową na `D − 1`. ⚠️ Stawka z datą **końca** nie domyka niczego; to okno nakładkowe, a nie nowy cennik |

⚠️ **Skutek uboczny przyjęty świadomie: stawką z datą końca można cenę tylko OBNIŻYĆ.** Skoro
wygrywa tańsza, promocja „50 zł w maju" działa, a „90 zł w lipcu" przegra z bezterminowymi 70 zł.
Podwyżkę robi się **nową stawką bezterminową** (która domyka poprzednią) albo **dopłatą**. Panel
takiego zapisu ani nie blokuje, ani nie komentuje: skutki nachodzenia stawek uwidacznia **kalendarz
podglądowy** (019), bo tylko on pokazuje, co naprawdę wychodzi w cenie doby — formularz mógłby
najwyżej zgadywać intencję.

⚠️ **Konsekwencja, której ta aktualizacja nie może zostawić bez odpowiedzi: skoro nachodzenie
przestało być błędem zapisu, a formularz go nie komentuje, to cennik musi je komuś pokazać.**
Dlatego cennik wystawia **diagnostykę** — dwie odpowiedzi ponad to, czego potrzebuje sama wycena:
**komplet kandydatów na dobę** (zamiast samego zwycięzcy, z `PriceRuleResolver`) oraz **wskazanie
stawek martwych**, czyli niewygrywających w żadnej dobie swojego okresu (`PricingConfigurationAudit`).
Rysuje to kalendarz (019), ale **liczy cennik**: dopasowanie stawki, choć po uproszczeniu jest samym
porównaniem dat, pozostaje logiką cennika, a ta ma jeden dom — i to jest ta sama zasada, która
w [ADR-015](ADR-015-warstwa-oferty-pobytu.md) kazała wystawić `shortestOffer()` zamiast pozwolić
widokowi zgadywać. Resolver i tak materializuje zbiór kandydatów, więc jest to zmiana kształtu
wyniku, nie nowa logika; kandydaci zależą wyłącznie od daty, więc podgląd pobiera diagnostykę raz na
okno, nie raz na komórkę siatki.

### Dlaczego

Trzy powody, w kolejności wagi:

1. **Zerowe pokrycie realnymi przypadkami.** Cennik obu klientów (O3) to jedna stawka bazowa plus
   warunkowa dopłata — nigdy zestaw konkurujących stawek. Cała maszyneria priorytetu, szczegółowości
   i remisu obsługiwała sytuację, której nie ma u nikogo. Makieta wskazała adresata wprost:
   „trzecie łowisko z ceną »pt–sb drożej«" — klient hipotetyczny.
2. **Nieprzekazywalność.** Żeby cztery osie dały się zrozumieć, makieta musiała je numerować
   („oś 1 z 4"), wyświetlać licznik szczegółowości i oznaczyć dwa pola słowem **„pułapka"**.
   Konfiguracja, której nie da się wytłumaczyć bez licznika osi, jest nie do utrzymania
   w samoobsłudze — a samoobsługa jest założeniem produktu.
3. **Jedno z trzech „zamierzonych ograniczeń" z Rekomendacji okazało się defektem.** Zasada
   „stawka towarzyszącej ma najwyższy priorytet", której model nie wymuszał i której broniło
   wyłącznie ostrzeżenie, znika w całości, gdy cena towarzyszącej jest **kolumną** wygranej stawki.
   Ograniczenie nie zostało obejściem — zostało usunięte razem z przyczyną.

⚠️ **Czego to NIE unieważnia w Rekomendacji:** ostrzeżenie o dziurze w cenniku nadal widzi wyłącznie
dzisiejszy stan reguł, a gwarancją pozostaje odmowa przy sprzedaży. To ograniczenie było i jest
zamierzone.

### Czy Opcja C wygrała pośrednio?

Nie — i warto to zapisać, bo z daleka tak wygląda. **Opcja C rozstrzygała remis regułą techniczną**
(„najmłodsza reguła", „najwyższe id"), czyli po cichu i w sposób, o którym operator nie myślał; ten
zarzut z Rekomendacji **zostaje aktualny**. Nowy model rozstrzyga regułą **biznesową i jawną** —
*wygrywa cena korzystniejsza dla wędkarza* — i dodatkowo **uwidacznia** nachodzenie na kalendarzu
podglądowym (019). Różnica jest istotna: reguła techniczna jest nieprzewidywalna dla operatora,
reguła „tańsza wygrywa" jest przewidywalna nawet bez czytania dokumentacji.

### Warunek powrotu

Priorytety, szczegółowość albo dni tygodnia na stawce wracają **wtedy i tylko wtedy**, gdy pojawi
się łowisko, które ma inną cenę **bazową** zależną od dnia tygodnia lub od czegoś poza datą, i
któremu **dopłata nie wystarcza**. Do tego czasu różnicowanie ceny dniami robi się dopłatą —
i widać wtedy, że to dopłata, a nie druga cena bazowa.

⚠️ **Ten ADR nie wraca do stanu „accepted" przez samo dopisanie tu czegokolwiek.** Powrót do
którejkolwiek z odwróconych reguł jest nową decyzją i nowym ADR-em.
