# ADR-001 — Silnik bazy danych w pakiecie testów

- **Status:** accepted
- **Data:** 2026-08-15
- **Zadanie:** [004 — Przebudowa lokalnego środowiska deweloperskiego na kontenery](../tasks/implemented/004-przebudowa-lokalnego-srodowiska-w-kontenerach.md)

## Kontekst

Zadanie 004 przenosi bazę danych do kontenera i buduje pięciowarstwową izolację pakietu testów.
Zanim powstanie choćby pierwsza z tych warstw, trzeba rozstrzygnąć, **na jakim silniku mają biec
testy** — bo od tego zależy, czy warstwy 2, 4 i 5 (usunięcie `DB_*` z Compose'a, test-strażnik
`force="true"`, uprawnienia w MySQL-u) w ogóle mają przedmiot.

**Stan faktyczny na dziś jest niejednoznaczny i to jest sedno problemu.** `phpunit.xml` deklaruje
`DB_CONNECTION=sqlite` i `DB_DATABASE=:memory:`, ale **żaden z tych wpisów nie ma `force="true"`**.
Skutek:

- **W kontenerze** `docker-compose.yml` wstrzykuje `DB_CONNECTION=mysql` przez listę `environment:`,
  czyli do `$_SERVER` — deklaracja z `phpunit.xml` przegrywa. Testy biegną **na MySQL-u**, w bazie
  `test`, i tylko dlatego, że `test.sh` robi `export DB_DATABASE=test`. Gdyby nie ten jeden `export`,
  `RefreshDatabase` czyściłby bazę roboczą — dokładnie ten scenariusz zdarzył się w bliźniaczym
  projekcie PunktySzczepień i został wyśledzony dopiero w dzienniku binarnym MySQL-a.
- **Na hoście**, bez zmiennych z Compose'a, wygrywa deklaracja z `phpunit.xml` i te same testy biegną
  **na SQLite w pamięci**.

Silnik zależy więc dziś od miejsca uruchomienia, a nie od decyzji. Dwa środowiska wykonawcze,
dwie różne semantyki bazy, ten sam zielony wynik — to jest defekt sam w sobie, niezależnie od tego,
którą opcję poniżej wybierzemy.

**Skala ryzyka regresji** (istotna, bo przesądza koszt migracji na MySQL): pakiet liczy dziś
**13 plików testowych** — 11 funkcjonalnych (Breeze: uwierzytelnianie, rejestracja, weryfikacja
adresu, reset i zmiana hasła, profil; oraz granice paneli `AdminPanelTest` / `OwnerPanelTest`)
i 2 jednostkowe (`CSOServiceTest`, `IbanValidationTest`, oba bez bazy). Migracji jest 25. Nawet
gdyby przejście na MySQL zaczerwieniło część z nich, to kilkanaście plików, nie kilkaset.

Kontekst dodatkowy: aplikacja jest w fazie tworzenia, ale **model domenowy jest już rozbudowany**
(czternaście modeli, dwa panele Filamenta), a kierunek wdrożenia to Cloud Run z MySQL-em
(zadania 001–003). Różnice, które SQLite przepuszcza, ujawniałyby się więc dopiero na wdrożeniu.

## Alternatywy

### Opcja A — MySQL, dedykowany schemat `lowiska_test` w tym samym kontenerze

Ten sam kontener `mysql:8.4`, co środowisko robocze, ale osobny schemat; dostęp nadaje skrypt
z `docker/mysql/initdb/`. `phpunit.xml` wymusza komplet `DB_*` przez `force="true"` (łącznie
z pustym `DB_URL`), a bramka w `TestCase::createApplication()` sprawdza **rozwiązane** połączenie
i przerywa cały pakiet przez `exit(1)` przy niezgodności.

**Zalety:**
- **Zgodność z produkcją.** Testy przechodzą przez ten sam silnik, te same typy kolumn i tę samą
  semantykę zapytań, co wdrożenie — łapią różnice w sortowaniu, w porównaniach tekstowych
  (`utf8mb4_*_ci`), w trybie ścisłym, w zachowaniu kluczy obcych i w typach `json` / `enum`.
- **Migracje są testowane naprawdę.** 25 migracji pisanych pod MySQL-a wykonuje się na MySQL-u;
  konstrukcja działająca na SQLite, ale nie na produkcji, czerwienieje od razu.
- **Usankcjonowanie stanu faktycznego.** Testy w kontenerze i tak już biegną na MySQL-u — ta opcja
  domyka rozjazd host/kontener zamiast go pogłębiać.
- **Warstwy izolacji z zadania 004 mają sens jako całość** i są testowalne (test-strażnik, bramka).
- Otwiera drogę do zrównoleglenia (`paratest` na schematach `lowiska_test_%`) bez zmiany silnika.

**Wady:**
- **Wolniej.** Każdy test funkcjonalny to `RefreshDatabase` na 25 migracjach po sieci kontenerowej,
  zamiast bazy w pamięci procesu.
- **Testy przestają działać bez wstającego kontenera bazy** — pakietu nie da się uruchomić „na sucho".
- **Wymaga całej pięciowarstwowej izolacji**, żeby był bezpieczny; to jest kod, który trzeba napisać
  i utrzymywać. Bez niego opcja jest wprost niebezpieczna.
- Przejście prawdopodobnie **zaczerwieni część z 13 istniejących plików** — trzeba to naprawić
  w ramach zadania 004, a nie odłożyć.

### Opcja B — SQLite `:memory:`, wymuszony przez `force="true"`

Zostajemy przy dzisiejszej deklaracji z `phpunit.xml`, ale dopisujemy `force="true"`, żeby
przestała przegrywać ze zmiennymi z Compose'a.

**Zalety:**
- **Najszybszy przebieg** — baza w pamięci procesu, bez ruchu sieciowego.
- **Izolacja z natury.** Baza `:memory:` fizycznie nie może dosięgnąć schematu roboczego, więc
  warstwy 2 i 5 z zadania 004 stają się zbędne, a bramka z warstwy 3 upraszcza się do jednego
  sprawdzenia sterownika.
- Pakiet działa wszędzie, także na hoście bez Dockera i w środowisku CI bez usługi bazy.
- Zerowy koszt migracji — testy dziś przechodzą na tej konfiguracji na hoście.

**Wady:**
- **Przepuszcza defekty ujawniające się dopiero na wdrożeniu**: różnice typów, tryb ścisły MySQL-a,
  sortowanie i porównania tekstowe (SQLite nie ma odpowiednika `utf8mb4_*_ci`), zachowanie `json`,
  `enum`, `ALTER TABLE` w migracjach, blokady i transakcje.
- **Aplikacja jest wielojęzyczna** (polski + angielski od startu), a porównania i sortowanie tekstu
  z diakrytykami to dokładnie ten obszar, w którym SQLite i MySQL różnią się w sposób niewidoczny
  w teście, a widoczny dla użytkownika.
- Migracji nikt nie sprawdza na docelowym silniku — pierwsza weryfikacja to `migrate` na wdrożeniu.
- Utrwala rozjazd „testy na innym silniku niż aplikacja", którego koszt rośnie z każdym kolejnym
  modelem i zapytaniem.

### Opcja C — SQLite domyślnie, MySQL w osobnej pętli (na żądanie / przed wdrożeniem)

Codzienny przebieg na SQLite; dodatkowe połączenie i osobna komenda uruchamiają ten sam pakiet
na MySQL-u przed wdrożeniem albo w CI.

**Zalety:**
- Łączy szybkość pętli deweloperskiej z weryfikacją na docelowym silniku.
- Rozjazd wychodzi przed wdrożeniem, a nie na nim.

**Wady:**
- **Podwójna konfiguracja izolacji** — obie ścieżki wymagają własnego zabezpieczenia przed trafieniem
  w bazę roboczą, więc pięć warstw z zadania 004 i tak trzeba zbudować, plus druga ścieżka obok.
- **Wariant MySQL-owy jest uruchamiany rzadko, więc gnije** — testy zależne od semantyki silnika
  czerwienieją zbiorczo po tygodniach, w najgorszym momencie.
- Realnie działa dopiero z potokiem CI, a ten jest **poza zakresem zadania 004** (i zadań 001–003).
- Największy koszt utrzymania z trzech opcji przy najmniej wyraźnej korzyści na dziś.

## Rekomendacja

**Opcja A — MySQL, schemat `lowiska_test`.**

Trzy argumenty przesądzają:

1. **To nie jest zmiana silnika, tylko domknięcie stanu faktycznego.** Testy w kontenerze — czyli
   tam, gdzie realnie się je uruchamia — już dziś biegną na MySQL-u. Opcja B nie jest „zostaniem
   przy tym, co jest": jest **zmianą** silnika dla wszystkich uruchomień kontenerowych, tyle że
   w stronę mniejszej zgodności z produkcją.
2. **Koszt migracji jest teraz najniższy, jaki będzie.** 13 plików testowych i 25 migracji to
   moment, w którym ewentualne czerwone testy da się naprawić w ramach jednego zadania. Przy
   czternastu modelach domenowych i dwóch panelach Filamenta pakiet będzie już tylko rósł, a wraz
   z nim koszt tej samej decyzji podjętej później.
3. **Zadanie 004 i tak buduje komplet warstw izolacji.** Główny koszt opcji A — pięć warstw
   zabezpieczeń — jest już w zakresie zadania i uzasadniony niezależnie (dzisiejszy układ jest
   niebezpieczny bez względu na wybrany silnik). Opcja A korzysta z pracy, którą i tak trzeba wykonać.

Kontrargument z szybkości jest realny, ale adresowalny osobno: `RefreshDatabase` na MySQL-u przyspiesza
się zrównolegleniem (`paratest` na schematach `lowiska_test_%`, do czego rozstrzygnięcie zadania 004
przygotowuje uprawnienia), a nie zmianą silnika na niezgodny z produkcją.

**Warunek wykonania rekomendacji:** przejście musi objąć naprawę testów, które zaczerwienią się po
zmianie silnika — w ramach zadania 004, a nie jako dług. Jeśli w trakcie implementacji okaże się,
że czerwonych jest istotnie więcej niż kilka i wymagają zmian w kodzie aplikacji (a nie tylko
w testach), należy to zgłosić — sekcja „Zakres wyłączeń" zadania 004 wyłącza kod aplikacji z zakresu.

## Decyzja

**Opcja A — testy biegną na MySQL-u, w schemacie `lowiska_test` tego samego kontenera `mysql:8.4`,
co środowisko robocze.** Decyzja podjęta przez autora zadania 2026-08-15.

Konsekwencje wiążące implementację zadania 004:

- **SQLite znika z pakietu testów w całości.** `phpunit.xml` wymusza przez `force="true"` komplet
  `DB_*` na połączenie MySQL-owe wskazujące schemat `lowiska_test`, w tym **`DB_URL` wymuszony
  pusty** — bez tego zmienna `DATABASE_URL` ze środowiska przesłania host i schemat.
- **Wszystkie pięć warstw izolacji z zadania 004 jest obowiązkowe**, łącznie z warstwą 5 (uprawnienia
  nadawane skryptem z `docker/mysql/initdb/`) i warstwą 2 (usunięcie zmiennych `DB_*` z listy
  `environment:` usług `app`, `queue`, `scheduler`). Nie są opcjonalne — to one czynią tę decyzję
  bezpieczną.
- **Bramka wykonawcza w `TestCase::createApplication()` sprawdza rozwiązane połączenie**, nie same
  zmienne środowiskowe, i przerywa **cały** pakiet przez `exit(1)`. Nieudana asercja przerwałaby
  tylko jeden test i wpuściła następny na złą bazę.
- **`test.sh` wolno usunąć dopiero po postawieniu kompletu warstw** — dziś `export DB_DATABASE=test`
  jest jedyną realnie działającą ochroną.
- **Naprawa testów zaczerwienionych przez zmianę silnika należy do zadania 004.** Jeśli okaże się,
  że wymagają zmian w kodzie aplikacji (a nie w samych testach), implementacja ma to zgłosić —
  kod aplikacji jest w sekcji „Zakres wyłączeń" zadania.
- **Zrównoleglenie pozostaje poza zakresem**, ale uprawnienia do schematów `lowiska\_test\_%` nadaje
  skrypt startowy od razu (rozstrzygnięcie zadania 004) — na istniejącym wolumenie danych nie da się
  tego zrobić bez ręcznej interwencji.
