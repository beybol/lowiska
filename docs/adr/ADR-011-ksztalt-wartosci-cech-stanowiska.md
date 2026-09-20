# ADR-011 — Kształt przechowywania wartości cech stanowiska

- **Status:** accepted
- **Data:** 2026-09-20
- **Zadanie:** [014 — Stanowiska, grupy i cechy](../tasks/implemented/014-stanowiska-grupy-i-cechy.md)

## Kontekst

Moduł M5 wprowadza **cechy niekupowalne**, po których wędkarz wybiera stanowisko: wjazd pojazdem
(tak/nie), miejsce na namiot (ile), pomost (tak/nie), odległość do parkingu i do WC (metry), rodzaj
brzegu (wybór z listy). Słownik cech jest **wspólny dla całego portalu** i leży w rękach
administratora — na tym polega jego sens: cecha znaczy to samo na każdym łowisku.

Wartość cechy ma **jednego właściciela** — stanowisko. (Pierwotna wersja zadania dawała cechy także
grupom, co wymagałoby relacji polimorficznej; po przebudowie grupa jest etykietą bez cech, więc to
pytanie zniknęło. Wycofany ADR-008 łączył oba wątki; ten ADR niesie wyłącznie ten, który przetrwał.)

Do rozstrzygnięcia zostaje jedno: **jak przechowywać samą wartość**, skoro cecha ma trzy typy
(`flag`, `number`, `choice`). Znacznik `is_filterable` powstaje w tym zadaniu, a filtr w portalu
wędkarza — później. Kształt kolumn przesądza, czy ten filtr będzie zwykłym warunkiem `WHERE`, czy
rzutowaniem tekstu przy każdym wierszu. Odwrócenie po fakcie oznacza migrację danych wprowadzonych
już przez operatorów.

Dochodzi ograniczenie, którego nie da się wyrazić w schemacie przy żadnym wariancie: **dokładnie
jedna wartość jest właściwa dla danego typu cechy**. Reguła musi mieć jeden dom w `app/Rules/`
niezależnie od wybranej opcji.

## Alternatywy

### Opcja A — trzy kolumny typowane

`value_flag` (boolean), `value_number` (decimal), `position_attribute_option_id` (klucz obcy).
Wypełniona jest dokładnie jedna, a decyduje o tym `type` cechy.

**Zalety:**
- Filtrowanie i sortowanie po liczbie jest zwykłym porównaniem w bazie i korzysta z indeksu —
  `is_filterable` nie zostaje obietnicą bez pokrycia.
- Wybór z listy jest **kluczem obcym**, więc opcja usunięta ze słownika nie zostawia osieroconego
  napisu, a `onDelete('set null')` jest widoczne w schemacie.
- Baza pilnuje typu: w `value_number` nie wyląduje „tak", a w `value_flag` nie wyląduje `12.5`.
- Indeks unikalny `(position_id, position_attribute_id)` wymusza „jedna wartość cechy na stanowisko"
  na poziomie bazy, niezależnie od kształtu wartości.

**Wady:**
- Trzy kolumny, z których zawsze dwie są puste — tabela jest rzadka.
- Reguła „dokładnie jedna, zgodnie z `type`" żyje w kodzie, nie w schemacie.
- Czwarty typ cechy (data? zakres?) wymaga migracji, a nie samego wpisu do słownika.

### Opcja B — jedna kolumna tekstowa

`value` typu `string`, interpretowana według `type` cechy.

**Zalety:**
- Najprostszy schemat; dodanie czwartego typu nie wymaga migracji.
- Brak pustych kolumn i brak reguły „dokładnie jedna z trzech".

**Wady:**
- Filtrowanie i sortowanie po liczbie wymaga rzutowania w zapytaniu, co **wyklucza użycie indeksu** —
  a filtr po cechach przez wszystkie łowiska jest dokładnie tym, po co cechy powstają.
  Reguła graniczna M5 mówi wprost: atrybut powstaje wyłącznie wtedy, gdy potrzebne jest filtrowanie
  przez wszystkie łowiska albo negacja z datą i przyczyną.
- Odwołanie do opcji ze słownika przestaje być kluczem obcym; skasowanie opcji zostawia napis,
  którego nic nie unieważnia.
- Porównania leksykalne kłamią na liczbach: `"100" < "20"`. Błąd jest cichy — filtr zwraca wyniki,
  tylko niewłaściwe.
- Konwersja na kolumny typowane po tym, jak operatorzy wprowadzą dane, to **migracja z parsowaniem
  tekstu** — najdroższy z rozważanych scenariuszy odwrócenia.

### Opcja C — jedna kolumna JSON

`value` typu `json`, np. `{"number": 12.5}`.

**Zalety:**
- Jeden schemat na dowolny typ, także złożony (zakres, lista).
- MySQL 8 indeksuje ścieżki JSON przez kolumny generowane, więc filtrowanie **da się** odzyskać.

**Wady:**
- Odzyskanie filtrowania wymaga kolumny generowanej na każdą filtrowalną ścieżkę — czyli wraca
  opcja A, tylko okrężną drogą i bez integralności referencyjnej dla `choice`.
- Kształt dokumentu jest umową w kodzie, niewidoczną w schemacie; literówka w kluczu nie zgłasza się
  nigdzie.
- W projekcie nie ma dziś ani jednej kolumny JSON o roli strukturalnej (`gallery_images` to lista
  ścieżek), więc byłby to nowy wzorzec wprowadzony przy okazji.

## Rekomendacja

**Opcja A.** Rozstrzyga ją punkt, w którym alternatywy różnią się nieodwracalnie: **filtrowanie**.
Cechy istnieją po to, żeby po nich wybierać — inaczej wystarczyłby opis stanowiska, który już jest.
Opcja B oddaje tę zdolność za prostotę schematu i odkupuje ją migracją z parsowaniem tekstu; opcja C
odzyskuje ją, ale odtwarzając opcję A pod spodem.

Argument „trzy kolumny, dwie zawsze puste" jest realny i **nieistotny w tej skali**: wiersz powstaje
tylko dla cechy faktycznie wypełnionej na stanowisku, a łowisko ma kilkadziesiąt stanowisk i kilkanaście
cech. Rzadka tabela o przewidywalnym rozmiarze kosztuje mniej niż zapytanie, które nie umie użyć indeksu.

⚠️ **Cena, którą opcja A świadomie płaci:** reguła „dokładnie jedna z trzech kolumn wypełniona,
zgodnie z `type` cechy" **nie ma odpowiednika w schemacie**. Musi mieć jeden dom w `app/Rules/`
i być wołana z każdego miejsca zapisu — także z akcji zbiorczej, nie tylko z formularza stanowiska.
Druga kopia tego warunku jest defektem, nie zabezpieczeniem.

⚠️ **Czego ten ADR nie przesądza:** czwartego typu cechy. Gdyby kiedyś doszedł typ, którego nie da się
zapisać w jednej kolumnie skalarnej (zakres, lista wielokrotna), jest to nowa decyzja — nie
rozwinięcie tej. Dzisiejsze trzy typy są skalarne i to jest założenie, na którym opcja A stoi.

## Decyzja
Decyzja: A

Uzasadnienie: najłatwiejsze do filtrowania w przyszłości, łatwe do rozwinięcia.
