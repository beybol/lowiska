# ADR-014 — Cennik jako lista reguł z warunkami: rozstrzyganie i kształt nierozstrzygalności

- **Status:** accepted
- **Data:** 2026-09-22
- **Zadanie:** [018 — Cennik regułowy](../tasks/018-cennik-regulowy.md)

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
