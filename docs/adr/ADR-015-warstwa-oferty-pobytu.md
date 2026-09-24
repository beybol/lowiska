# ADR-015 — Warstwa oferty pobytu jako jedyne wejście dla pytających o sprzedaż

- **Status:** accepted
- **Data:** 2026-09-22
- **Zadanie:** [018 — Cennik regułowy](../tasks/implemented/018-cennik-regulowy.md)

## Kontekst

Do zadania 017 na pytanie „czy wolno to sprzedać" odpowiadał **jeden** dostawca. Warstwy składały
się w łańcuch, w którym każda kolejna komponowała poprzednią i była jedynym wejściem dla
pytających: `FishingDayCalendar` → `PositionAvailability` ([ADR-012](ADR-012-jedno-zrodlo-prawdy-o-dostepnosci.md))
→ `StaySellability` ([ADR-013](ADR-013-warstwa-regul-pobytu-i-spoiwo-dob.md)).

Zadanie 018 łamie ten łańcuch w jednym punkcie: **dziura w cenniku jest odmową, nie ceną zerową.**
Doba w otwartym sezonie, do której nie pasuje żadna stawka, jest niesprzedawalna. Od tej chwili na
pytanie „czy wolno to sprzedać" odpowiadają **dwaj niezależni dostawcy** — reguły pobytu (017)
i cennik (018) — a żaden z nich nie zna drugiego i **nie ma znać**: wycena nie pyta
o sprzedawalność, sprzedawalność nie pyta o cenę.

⚠️ **To jest dokładnie ten układ, przed którym ostrzegały oba poprzednie ADR-y.** ADR-012 i ADR-013
zakazują drugiego miejsca składającego odpowiedź — nie dlatego, że składanie jest trudne, ale
dlatego, że **każde kolejne miejsce składa je trochę inaczej** i nic tego nie sygnalizuje. Różnica
polega na tym, że wcześniej zakaz dotyczył dublowania **jednego** źródła, a teraz źródeł jest dwa
i ktoś **musi** je złożyć.

⚠️ **Rzecz, której nie widać z samego zadania: decyzja NARUSZA literę ADR-013.** Jego Decyzja A mówi,
że `StaySellability` „jest jedynym wejściem dla panelu, portalu, cennika (018) i kalendarza (019)".
Po tym zadaniu wejściem dla cennika, kalendarza i portalu jest **warstwa oferty**, a
`StaySellability` zostaje jedynym źródłem prawdy o **sprzedawalności pobytu** — co nie jest tym
samym zdaniem. Nie jest to odwrócenie decyzji A: kompozycja i kolejność warunków zostają w mocy.
Zmienia się **lista wołających**, i właśnie dlatego ADR-013 dostaje sekcję „Aktualizacja"
z odsyłaczem tutaj, a nie cichą poprawkę w treści.

Do rozstrzygnięcia są trzy rzeczy naraz: **gdzie mieszka składanie**, **w jakiej kolejności** oraz
**co niesie odmowa**.

## Alternatywy

### Opcja A — cienka warstwa oferty w `app/Services/`, jedyne wejście dla 019, koszyka i portalu

Powstaje osobna klasa odpowiadająca na jedno pytanie: **czy ten pobyt jest sprzedawalny i wyceniony.**
Zwraca albo ofertę z rozbiciem, albo odmowę z **jednym** powodem, niezależnie od tego, która warstwa
niżej ją zgłosiła.

```
FishingDayCalendar        czas: doby, sezony                      (ADR-010)
        ↑
PositionAvailability      doba na stanowisku                      (ADR-012)
        ↑
StaySellability           ciąg dób: spoiwo, długość, horyzont     (ADR-013)
        ↑                          StayPricing   ile kosztuje     (ADR-014)
        └──────────────┬──────────────────┘
                 warstwa oferty            czy w ofercie          (ten ADR)
                        ↑
        kalendarz (019) · przyszły koszyk · portal
```

- Warstwy niżej pozostają **niezależne**; tylko warstwa oferty zna obie.
- Kolejność jest ta sama co wszędzie: **najpierw sprzedawalność, potem cena.** Pobyt niesprzedawalny
  nie jest wyceniany, więc odmowa niesie przyczynę **trwalszą** („stanowisko wycofane" przed „brak
  ceny").
- **Powód „brak ceny" nazywa warstwa oferty, nie wycena** — wycena zgłasza tylko, że nie umie
  wycenić doby, i dzięki temu zostaje wolna od słownika odmów sprzedaży.
- Odmowa „brak ceny" **wskazuje dobę, rolę i obsadę**, dla których zabrakło stawki — ta sama zasada,
  którą ADR-013 przyjął dla odmów przepuszczanych z poziomu doby.
- **Panel konfiguracyjny nadal woła to, co odpowiada na jego pytanie** — sam cennik albo samą
  sprzedawalność. Warstwa oferty jest wejściem dla pytających o sprzedaż, nie obowiązkową bramą
  do wszystkiego.

**Zalety:**
- **Jedno miejsce składania, tak jak wymagają ADR-012 i ADR-013.** Zakaz zostaje utrzymany w duchu,
  a nie tylko w literze: nie ma dwóch klas dublujących źródło, ale też nie ma trzech wołających,
  z których każdy składa po swojemu.
- **Kolejność „sprzedawalność przed ceną" jest wyrażona RAZ i nie da się jej pomylić.** U każdego
  wołającego z osobna byłaby konwencją, którą trzeba pamiętać — i którą pierwszy nowy ekran złamie,
  bo odwrotna kolejność też „działa", tylko zwraca gorszy komunikat.
- **Warstwy niżej zostają testowalne osobno**, a granica z ADR-013 nie rusza się ani o krok:
  `StaySellability` nadal nie wie o cenach.
- **Jest miejscem, w którym kiedyś powstanie snapshot G1.** Oferta z rozbiciem to dokładnie to, co
  transakcja ma utrwalić — warstwa daje temu jeden adres, zamiast kazać koszykowi zbierać składniki
  z dwóch usług.
- Cienka: nie liczy niczego własnego, więc nie wnosi trzeciego miejsca z regułami.

**Wady:**
- **Czwarta klasa w łańcuchu**; ktoś musi wiedzieć, którą wołać. Odpowiedź jest jednoznaczna
  (pytasz o sprzedaż → warstwa oferty; konfigurujesz → warstwa, której dotyczy ekran), ale nie
  wynika z nazw.
- **Zmienia zapis obowiązujący dziś w trzech miejscach** — ADR-013, `dostepnosc.md` §2 i docblock
  `StaySellability`. Koszt jest jednorazowy, ale pominięcie którejkolwiek z trzech poprawek zostawia
  w repozytorium zdanie, które kłamie.
- **Ryzyko rozrostu.** „Cienka warstwa składająca" jest kusząca jako miejsce na wszystko, co dotyczy
  sprzedaży; pierwsza reguła własna, która się tu pojawi, zamieni ją w czwarte źródło prawdy.

### Opcja B — każdy pytający składa sam

Nie ma warstwy. Kalendarz (019), koszyk i portal wołają `StaySellability`, a potem `StayPricing`,
i same decydują, co zrobić z dwiema odpowiedziami.

**Zalety:**
- Zero nowych klas i zero nowej granicy do pilnowania.
- Każdy wołający bierze dokładnie to, czego potrzebuje — kalendarz może chcieć pokazać cenę także
  dla doby niesprzedawalnej („byłoby 90 zł, gdyby nie blokada"), czego warstwa składająca mu nie da
  bez dodatkowego trybu.
- Nie trzeba poprawiać ADR-013 ani `dostepnosc.md`.

**Wady:**
- ⚠️ **To jest wprost to, czego zabraniają ADR-012 i ADR-013.** Trzech wołających to trzy
  implementacje składania; rozjazd między nimi nie zapala się nigdzie, bo każda osobno „działa".
- **Kolejność przyczyn przestaje być umową.** ADR-012 i ADR-013 ustaliły, że odmowa niesie przyczynę
  trwalszą; przy składaniu u wołającego pierwszy ekran, który zapyta najpierw o cenę, pokaże „brak
  ceny" tam, gdzie prawdziwym powodem jest wycofane stanowisko.
- **Koszyk i portal to przyszły kod**, więc rozjazd powstanie najpóźniej i najdrożej — dokładnie
  w miejscu, w którym wynik widzi wędkarz.

### Opcja C — `StaySellability` wchłania wycenę

Jedna klasa odpowiada na oba pytania: sprzedawalność i cenę. Dziura w cenniku staje się piątym
warunkiem w jej kolejności.

**Zalety:**
- Jedno wejście i jedna kolejność, bez nowej klasy.
- Odmowa „brak ceny" naturalnie wpada w istniejący mechanizm powodów, bez tłumaczenia między
  warstwami.

**Wady:**
- ⚠️ **Odwraca granicę, którą ADR-013 świadomie postawił.** Sprzedawalność zaczęłaby zależeć od
  ceny, więc klasa nazywana „jedynym źródłem prawdy o sprzedawalności pobytu" musiałaby znać cennik,
  jego priorytety i jego remisy.
- **Wycena przestaje być testowalna osobno**, a jej koszt (iloczyn dób, obsad i ról) wchodzi do
  każdego pytania o sprzedawalność — także tam, gdzie cena jest nieistotna, jak przy walidacji
  reguł pobytu w panelu.
- **Kryterium akceptacji 018 „wycena nie woła `StaySellability`" przestaje mieć sens**, a wraz z nim
  możliwość sprawdzenia, że obie warstwy są od siebie niezależne.
- Klasa z pięcioma warunkami z dwóch różnych dziedzin przestaje mieć jedno zdanie opisu — a to jest
  najwcześniejszy sygnał, że granica jest w złym miejscu.

## Rekomendacja

**Opcja A**: cienka warstwa oferty w `app/Services/`, jedyne wejście dla kalendarza (019), przyszłego
koszyka i portalu; kolejność „sprzedawalność przed ceną"; odmowa z jednym powodem wskazującym dobę,
rolę i obsadę. ADR-013 dostaje sekcję „Aktualizacja" z odsyłaczem tutaj; jego Decyzja A **zostaje
w mocy**.

Rozstrzyga to, czego pozostałe opcje nie potrafią:

1. **Opcja B jest wprost zakazana przez dwa obowiązujące ADR-y** i przenosi koszt rozjazdu na kod,
   którego jeszcze nie ma — czyli tam, gdzie będzie najdroższy i najmniej widoczny.
2. **Opcja C odwraca granicę ADR-013**, a nie doprecyzowuje jej. Odwrócenie decyzji jest dozwolone,
   ale wymaga uzasadnienia mocniejszego niż „byłoby o jedną klasę mniej" — a korzyść jest właśnie
   taka, podczas gdy koszt to utrata niezależnego testowania obu warstw.
3. **Jedno miejsce składania jest jedynym wariantem, w którym kolejność przyczyn pozostaje umową**,
   a nie nawykiem powtarzanym u każdego wołającego.

⚠️ **Rekomendacja zostawia jedno pytanie świadomie otwarte:** czy kalendarz (019) będzie chciał
zobaczyć cenę doby **niesprzedawalnej** („byłoby 90 zł, gdyby nie blokada"). Dziś nikt tego nie
potrzebuje, a warstwa oferty takiej odpowiedzi nie daje, bo pobytu niesprzedawalnego nie wycenia.
Gdy się okaże potrzebne, jest to **osobna decyzja** — dodatkowy tryb warstwy albo bezpośrednie
wołanie wyceny przez 019 — a nie powód, żeby dziś budować oba warianty.

⚠️ **Granica, której pilnuje ten ADR na przyszłość:** warstwa oferty **nie liczy niczego własnego.**
Pierwsza reguła sprzedażowa zapisana w niej, a nie w `StaySellability` albo w cenniku, czyni z niej
czwarte źródło prawdy — i wtedy ten ADR trzeba będzie odwrócić, a nie rozszerzyć.

## Decyzja
Decyzja: A
