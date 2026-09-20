# ADR-012 — Jedno źródło prawdy o dostępności: skład warunków i kształt odmowy

- **Status:** accepted
- **Data:** 2026-09-20
- **Zadanie:** [016 — Blokady, ograniczenia i wyliczanie dostępności](../tasks/implemented/016-blokady-ograniczenia-i-dostepnosc.md)

## Kontekst

Po zadaniach 014 i 015 istnieją trzy niezależne składniki odpowiedzi na pytanie „czy tę dobę można
sprzedać na tym stanowisku":

- **stan własny stanowiska** (`available` / `withdrawn`) — zadanie 014;
- **okres sprzedaży łowiska** wraz z definicją doby — zadanie 015, reguły granic w
  [ADR-010](ADR-010-doba-wedkarska-jako-przedzial-czasu.md);
- **blokady i ograniczenia czasowe** — to zadanie.

Żadne miejsce nie składa ich razem. Dopóki go nie ma, poprzednie zadania da się sprawdzić wyłącznie
po zawartości tabel, a każde kolejne — cennik (018), kalendarz podglądowy (019), portal wędkarza —
będzie kuszone, żeby dorobić własne wyliczenie. Rozdział 14.3
[wymagań](../project/WYMAGANIA-SPRZEDAZ-KROTKOTERMINOWA.md) wskazuje to jako kandydata na ADR:
zasięg obejmuje portal, panel, sprzedaż offline, cennik i kalendarz, a odwrócenie oznacza
przepisanie każdego miejsca, które pyta o dostępność.

⚠️ **Rzecz, której nie widać z samego zadania:** zadanie 015 zostawiło już klasę
`FishingDayCalendar` z metodą `availability()`, która odpowiada na to samo pytanie **dla łowiska**
(doba mieści się w okresie sprzedaży, albo nie — i dlaczego). Bez rozstrzygnięcia powstałyby **dwa**
byty, z których każdy mógłby nazywać siebie jedynym źródłem prawdy, a różnica między nimi
(„dla łowiska" kontra „dla stanowiska") jest niewidoczna w nazwie.

Do rozstrzygnięcia są więc trzy rzeczy naraz, bo każda osobno nie ma sensu: **gdzie to mieszka**,
**w jakiej kolejności składa warunki** i **co zwraca przy odmowie**.

## Alternatywy

### Opcja A — osobna usługa komponująca kalendarz; pierwszy powód w kolejności od najtrwalszej przyczyny

`FishingDayCalendar` zostaje przy tym, co ustala ADR-010: wyznacza doby i stosuje reguły granic.
Nowa usługa (`PositionAvailability`) **woła go** i dokłada stan stanowiska oraz blokady. Jest jedynym
wejściem dla panelu, portalu, cennika i kalendarza. Enum `SaleUnavailabilityReason` zostaje
**rozszerzony** o nowe powody, nie duplikowany.

Warunki składane są w stałej kolejności, od przyczyny **najtrwalszej** do najbardziej czasowej:

1. stan stanowiska (`withdrawn` → odmowa niezależnie od dat),
2. konfiguracja doby i okres sprzedaży (przez kalendarz),
3. blokada `sale_blocked` przecinająca dobę,
4. zawieszenia cech (`attribute_suspended`) — **nie wpływają na sprzedawalność**, wracają osobno.

Odmowa niesie **pierwszy napotkany powód**.

**Zalety:**
- Granica między usługami czytelna i zgodna z istniejącymi ADR-ami: kalendarz wie o **czasie**,
  nowa usługa o **stanowisku**. ADR-010 nie musi być przepisywany.
- Kolejność odpowiada temu, co jest użyteczne dla pytającego: „to stanowisko jest wycofane" jest
  ważniejsze niż „poza sezonem", bo pierwsze nie zmieni się jutro. Wędkarz dostaje powód, z którym
  może coś zrobić (zmienić stanowisko), zanim dostanie ten, z którym nie może nic.
- Deterministyczna kolejność jest testowalna wprost i daje kalendarzowi z zadania 019 stabilny,
  powtarzalny wynik dla każdej doby.
- Jeden powód to jedno zdanie w interfejsie — nie trzeba rozstrzygać, jak wyświetlić cztery naraz.
- Rozszerzanie wspólnego enuma powodów utrzymuje jeden słownik komunikatów dla całej sprzedaży.

**Wady:**
- Dwie klasy zamiast jednej; ktoś musi wiedzieć, którą wołać (odpowiedź: **zawsze tę nową**, chyba
  że pyta wyłącznie o doby).
- Pierwszy powód gubi informację, że przyczyn było kilka — operator diagnozujący „dlaczego nic się
  nie sprzedaje" zobaczy tylko jedną warstwę naraz.
- Kolejność jest umową, której baza nie wymusza; złamanie jej nie zapali się nigdzie poza testem.

### Opcja B — osobna usługa, ale odmowa zwraca KOMPLET powodów

Jak opcja A, z jedną różnicą: usługa sprawdza wszystkie cztery warunki i zwraca listę wszystkich
naruszonych.

**Zalety:**
- Pełny obraz przy diagnozie: operator od razu widzi, że stanowisko jest i wycofane, i zablokowane.
- Nie trzeba ustalać ani bronić kolejności — nie ma czego złamać.

**Wady:**
- Każde pytanie o dostępność wykonuje **komplet** sprawdzeń, także gdy pierwsze już przesądziło.
  Kalendarz z zadania 019 pyta o dobę razy stanowisko, więc koszt mnoży się przez dwa wymiary.
- Interfejs musi zdecydować, co pokazać z listy — czyli kolejność wraca, tylko przeniesiona
  do warstwy prezentacji, gdzie będzie powtórzona w każdym miejscu osobno.
- Lista powodów kusi, żeby traktować je jak równorzędne, a nie są: „wycofane" i „poza sezonem" to
  komunikaty o zupełnie innej trwałości.

### Opcja C — jedna usługa na wszystko; kolejność sprawdzeń wg kosztu zapytania

`FishingDayCalendar` rozrasta się o stan stanowiska i blokady. Warunki sprawdzane są od
najtańszego (kolumna na rekordzie) do najdroższego (zapytanie o blokady przecinające dobę).

**Zalety:**
- Jedno wejście bez pytań o granicę; najszybsza ścieżka odmowy.
- Brak kosztu komponowania dwóch obiektów.

**Wady:**
- **Kolejność powodów staje się pochodną wydajności, nie sensu** — komunikat dla wędkarza zależy
  wtedy od tego, które zapytanie akurat było tańsze, i zmieni się przy pierwszej optymalizacji.
- Klasa, której ADR-010 nadał zakres „wyliczanie dób i reguły granic", zaczyna trzymać reguły
  sprzedaży stanowiska — jej zakres rozjeżdża się z własnym uzasadnieniem, a ADR-010 trzeba
  przepisać.
- Kalendarz przestaje być używalny samodzielnie tam, gdzie stanowisko nie ma znaczenia
  (np. lista dób sezonu w panelu).

## Rekomendacja

**Opcja A.** Rozstrzyga ją pytanie, czego dotyczy **kolejność**. W opcji C jest pochodną kosztu
zapytania, więc komunikat widziany przez wędkarza zmieni się przy pierwszej optymalizacji — a to
jest zmiana zachowania produktu wprowadzona przy okazji zmiany technicznej. W opcji A kolejność
wyraża **trwałość przyczyny** i jest sama w sobie decyzją produktową, którą da się obronić i
przetestować.

Między A i B decyduje to, gdzie ląduje kolejność, a nie to, ile powodów się zwraca. Opcja B nie
usuwa kolejności — przenosi ją do warstwy prezentacji, gdzie zostanie powtórzona w panelu, w portalu
i w kalendarzu osobno. To jest dokładnie ten „drugi literał tej samej reguły", przed którym ostrzega
`CLAUDE.md`.

⚠️ **Warunek, bez którego rekomendacja przestaje obowiązywać:** panel, portal, cennik i kalendarz
wołają tę usługę, zamiast powtarzać którykolwiek z czterech warunków u siebie. Zapytanie
z `where('status', 'available')` napisane w zasobie Filamenta obok usługi jest defektem, nawet gdy
zwraca dziś to samo — bo przestanie, gdy dojdzie piąty warunek.

⚠️ **Zawieszenie cechy nie jest odmową sprzedaży** i nie wchodzi do łańcucha powodów. Stanowisko
z zawieszonym pomostem nadal się sprzedaje — tylko bez pomostu. Usługa zwraca zawieszone cechy
osobno; wrzucenie ich do tego samego wyniku co odmowa sprzedaży to zlanie dwóch różnych pytań.

⚠️ **Decyzja nie obejmuje buforowania.** Dostępność wylicza się z rekordów przy każdym pytaniu
i nie ma kolumny, która by ją trzymała. Gdyby bufor kiedyś był potrzebny, jest to nowa decyzja
z własnym uzasadnieniem **pomiarowym** — nie rozwinięcie tej.

## Decyzja
Decyzja: A
