# ADR-010 — Doba wędkarska jako przedział czasu i dwie reguły granic

- **Status:** accepted
- **Data:** 2026-09-17
- **Zadanie:** [015 — Doba wędkarska i kalendarz sezonów sprzedaży](../tasks/implemented/015-doba-wedkarska-i-sezony-sprzedazy.md)

> **Charakter dokumentu.** Rozstrzygnięcie zapadło przy projektowaniu zadania 015 i jest już zapisane
> w [Wymaganiach](../project/WYMAGANIA-SPRZEDAZ-KROTKOTERMINOWA.md) oraz w samym zadaniu. Ten ADR go
> **utrwala**, a nie otwiera od nowa: sekcja „Alternatywy" jest tu po to, żeby za rok było wiadomo,
> czego świadomie nie wybraliśmy i dlaczego. Sekcja „Decyzja" zostaje, bo `/implement-task` na pustej
> się zatrzymuje — wystarczy ją potwierdzić.

## Przykład, do którego wraca cały dokument

Jedno łowisko, trzy ustawienia:

- **doba** trwa od **15:00 do 15:00 dnia następnego** (tak jest w obu łowiskach z materiału źródłowego);
- **sezon sprzedaży**: od **1 lutego** do **30 września**;
- **blokada**: **5 lipca** malowane są pomosty, łowisko tego dnia nie przyjmuje nikogo.

Każdą z opcji niżej sprawdzamy na tym samym łowisku. Jeżeli w dalszej części pada „doba 29.09",
znaczy to **dobę rozpoczynającą się 29 września o 15:00 i kończącą się 30 września o 15:00** — dobę
nazywamy dniem, w którym się zaczyna.

## Kontekst

System sprzedaje czas na stanowisku, ale nie ma pojęcia jednostki, w której sprzedaje. Dostępność,
cena, długość pobytu i blokady liczą się „w dobach", a doba nigdzie nie jest zdefiniowana.

Doba zaczyna się o 15:00 i kończy o 15:00, więc **przechodzi przez północ** i nie jest żadnym jednym
dniem kalendarza. To wymusza odpowiedzi na trzy pytania, których dzisiejszy model nie unosi:

1. **Do której godziny obowiązuje ostatni dzień sezonu?** Sezon kończy się 30 września. Czy wolno
   sprzedać dobę 30.09, skoro wędkarz zszedłby z wody 1 października o 15:00 — już po sezonie?
2. **Ile trwa doba przy zmianie czasu?** Doba 28.03.2026 trwa **23 godziny**, doba 24.10.2026 —
   **25 godzin**. Obie są jedną dobą i kosztują tyle samo.
3. **Czy zakaz z jednego dnia obejmuje dobę, która ten dzień tylko zahacza?** Doba 04.07 kończy się
   5 lipca o 15:00, czyli wędkarz spędziłby poranek 5 lipca wśród malarzy.

Pytania 1 i 3 wyglądają na ten sam problem („czy zakres dat pasuje do doby"), a nie są nim. Sezon
**dopuszcza** sprzedaż, blokada ją **wyłącza**, więc pomyłka w każdym z nich kosztuje co innego.

**Dwa pytania, które model musi umieć rozdzielić:**

| Pytanie | Kto odpowiada | Co się stanie przy błędzie |
|---|---|---|
| *Czy wolno sprzedać tę dobę?* | sezon sprzedaży | za ostro → niepotrzebna odmowa; za luźno → sprzedana doba poza sezonem |
| *Czy ta doba wpada w zakaz?* | blokada, ograniczenie | za ostro → niepotrzebna odmowa; za luźno → wędkarz pod zamkniętą bramą |

Zasięg jest największy w całym pakiecie: z tej definicji korzystają zadania 016 (blokady), 017 (reguły
sprzedaży), 018 (cennik) i 019 (kalendarz podglądowy), a potem każde zapytanie o dostępność w portalu
wędkarza. Rozdział 14.3 [wymagań](../project/WYMAGANIA-SPRZEDAZ-KROTKOTERMINOWA.md) wskazuje to jako
kandydata na ADR z uzasadnieniem „odwrócenie oznacza przepisanie wszystkich zapytań o dostępność".

## Alternatywy

Wszystkie trzy opcje różnią się w dwóch miejscach: **czym jest doba** i **jak porównujemy ją
z zakresem dat**. Poniżej po ludzku, bez terminów.

### Opcja A — doba to przedział dwóch momentów; sezon musi ją zmieścić w całości, zakazowi wystarczy, że ją musnie

Doba to konkretne „od 29.09 15:00 do 30.09 15:00", liczone po zegarze łowiska (`fisheries.timezone`).

- **Sezon**: sprzedajemy dobę tylko wtedy, gdy **cała** mieści się w sezonie — początek i koniec.
- **Zakaz**: doba jest wyłączona, gdy **choćby minuta** z niej wypada w dniach zakazu.

Reguły są różne, bo pytania są różne: „czy wolno sprzedać **całą** tę dobę" kontra „czy **cokolwiek**
z tej doby wpada w zakaz".

**Zalety:**
- Odpowiada na pytanie o godzinę, nie tylko o dzień — a to zadaje wędkarz przy zakupie i obsługa przy
  bramie.
- Zmiana czasu przestaje być przypadkiem szczególnym: 23 i 25 godzin to po prostu ta sama doba.
- **Każda pomyłka idzie w stronę odmowy**, nigdy w stronę „sprzedaliśmy coś, czego nie wolno".
- Strefa czasowa łowiska jako źródło prawdy znosi klasę błędów, w której przeniesienie aplikacji na
  serwer w innej strefie przesuwa granice dób.

**Wady:**
- Dwie różne reguły na dwa podobnie wyglądające zakresy dat **wyglądają jak niekonsekwencja** i muszą
  być udokumentowane — stąd ten ADR.
- Operatorowi trzeba wytłumaczyć skutek: **ostatnie pozwolenie jednodobowe sprzedaje się na
  przedostatni dzień sezonu** (tu: 29.09).
- Wszystkie porównania muszą biec na momentach, nie na datach lokalnych; pomyłka ujawnia się dopiero
  przy zmianie czasu.

### Opcja B — doba to data w kalendarzu, godziny doklejamy przy wyświetlaniu

Doba to „30 września". Godziny 15:00–15:00 są własnością łowiska i pojawiają się dopiero na ekranie
i na dokumencie pozwolenia.

**Zalety:**
- Najprostsze zapytania: wszystko porównuje daty, bez momentów i bez stref czasowych.
- Zgodne z intuicją operatora, który mówi „kupuję piątek".

**Wady:**
- **Nie odpowiada na pytanie, które wymusiło decyzję.** „30.09" jest w sezonie, więc doba 30.09 się
  sprzeda — a skończy się 1 października o 15:00, poza sezonem. Rozstrzygnięcie tego wymaga wyjścia
  poza model.
- Doba przez zmianę czasu nie ma jak być 23- ani 25-godzinna; różnica wraca jako błąd przy pierwszym
  liczeniu długości pobytu.
- **Zakaz z 5 lipca nie zatrzyma doby 04.07**, bo „04.07" to nie „05.07" — a wędkarz łowi 5 lipca do
  15:00. Błąd cichy i po stronie sprzedaży.

### Opcja C — doba to przedział (jak w A), ale sezon i zakaz traktujemy jedną regułą: wystarczy, że doba je musnie

Różnica wobec A jest **jedna**: sezonowi też wystarczy dotknięcie, zamiast zmieszczenia całości.
Zakazy zachowują się identycznie jak w A.

**Zalety:**
- Jedna reguła zamiast dwóch — nie ma asymetrii do tłumaczenia ani do pilnowania w kodzie.
- Dla zakazów działa poprawnie.

**Wady:**
- **Otwiera i zamyka sezon o dobę za szeroko w obie strony.** Doba 31.01 dotyka 1 lutego, więc się
  sprzeda, choć zaczyna się dzień przed otwarciem. Doba 30.09 dotyka 30 września, więc się sprzeda,
  choć kończy się 1 października.
- Pomyłka idzie w stronę najdroższą: skutkiem jest zwrot pieniędzy albo wędkarz pod zamkniętą bramą,
  a nie komunikat o niedostępności.

### Jak trzy opcje odpowiadają na to samo

Łowisko z przykładu: doba 15:00→15:00, sezon 01.02–30.09, blokada 05.07.

| Sytuacja | Co to znaczy w godzinach | A | B | C |
|---|---|---|---|---|
| Doba **31.01** | 31.01 15:00 → 01.02 15:00 | **nie** — zaczyna się przed sezonem | nie | ⚠️ **tak** — dotyka 1 lutego |
| Doba **01.02** | 01.02 15:00 → 02.02 15:00 | **tak** — pierwsza doba sezonu | tak | tak |
| Doba **29.09** | 29.09 15:00 → 30.09 15:00 | **tak** — ostatnia doba sezonu | tak | tak |
| Doba **30.09** | 30.09 15:00 → 01.10 15:00 | **nie** — kończy się po sezonie | ⚠️ **tak** | ⚠️ **tak** |
| Doba **04.07** przy blokadzie 05.07 | 04.07 15:00 → 05.07 15:00 | **zablokowana** | ⚠️ **sprzedana** | zablokowana |
| Doba **24.10.2026** | 24.10 15:00 → 25.10 15:00 | jedna doba, 25 h, jedna cena | ⚠️ brak reprezentacji | jedna doba, 25 h |

Czytelny wniosek: **A i C różnią się wyłącznie w wierszach sezonowych** (31.01 i 30.09). Przy zakazach
robią dokładnie to samo. Cała decyzja sprowadza się do pytania, czy sezon ma **mieścić** dobę, czy
tylko jej **dotykać**.

## Rekomendacja

**Opcja A.** Rozstrzyga ją kierunek, w którym każda z opcji myli się na granicy. B i C mylą się
w stronę sprzedania czegoś, czego sprzedać nie wolno — a produktem jest zezwolenie na konkretną dobę
nad wodą, więc taki błąd kończy się zwrotem albo wędkarzem pod zamkniętą bramą. A myli się wyłącznie
w stronę odmowy: widocznej od razu i nie kosztującej pieniędzy.

Asymetria z opcji A nie jest ceną decyzji, tylko jej treścią: **zakres dat, który dopuszcza, i zakres,
który wyłącza, nie są tym samym pojęciem** i nie mogą mieć wspólnej reguły. Sezon odpowiada na „czy
całą tę dobę wolno sprzedać" — musi ją zmieścić. Zakaz odpowiada na „czy cokolwiek z tej doby wpada
w zakaz" — wystarczy dotknięcie.

## Skutki, o których trzeba pamiętać

⚠️ **Ostatnie pozwolenie jednodobowe sprzedaje się na przedostatni dzień sezonu.** Przy sezonie do
30 września jest to doba 29.09, obowiązująca do 30 września do 15:00. Sprzedaż nigdy nie wystawia
doby wystającej poza sezon — i to jest zachowanie zamierzone, nie błąd zaokrąglenia.

⚠️ **Wyliczanie dób i obie reguły granic mają jedno miejsce w kodzie**, a zadania 016–019 je wołają,
zamiast liczyć po swojemu. Drugi kod liczący doby jest defektem, nie optymalizacją — to ten sam
„drugi literał tej samej stałej", przed którym ostrzega `CLAUDE.md`. Bez tego warunku rekomendacja
przestaje obowiązywać.

⚠️ **Sprzedawalność doby nie jest materializowana w kolumnie** — liczy się przy każdym odczycie
z definicji doby i okresów. Nie ma stanu do uspójniania ani zadania cyklicznego, które mogłoby
zawieść i wystawić dobę poza sezonem.

⚠️ **Decyzja nie przesądza trybu sprzedaży innego niż doba.** Sprzedaż godzinowa i turnus jako
produkt są poza zakresem; enum `SaleMode` istnieje po to, żeby ich dołożenie było dopisaniem
wartości, a nie przepisaniem tego ADR-a. Gdyby jednak doszedł tryb, w którym jednostka **nie**
przechodzi przez północ, jest to nowa decyzja — reguły granic zakładają przedział przechodzący przez
dobę kalendarzową.

## Decyzja
Decyzja: A
