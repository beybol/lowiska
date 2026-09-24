# 015 — Doba wędkarska i kalendarz sezonów sprzedaży

## Opis problemu

System nie ma pojęcia jednostki, w której sprzedaje. Dostępność, cena, długość pobytu i blokady liczą
się w dobach, a doba nie jest nigdzie zdefiniowana — nie wiadomo, o której się zaczyna, o której
kończy ani w jakiej strefie czasowej jest liczona.

**Doba nie jest datą kalendarzową.** Zaczyna się o ustalonej godzinie i kończy o ustalonej godzinie
dnia następnego, więc przechodzi przez północ, a przy zmianie czasu trwa dwadzieścia trzy albo
dwadzieścia pięć godzin. Traktowanie jej jako daty działa, dopóki nikt nie zapyta, do której godziny
obowiązuje ostatni dzień sezonu — a od tego zależy, co wolno sprzedać.

Drugi brak: nie da się powiedzieć „w tym okresie nie sprzedajemy". Dziś łowisko sprzedaje zawsze albo
wcale, a zamknięcie sprzedaży na część roku jest stanem normalnym, nie awarią.

Zadanie jest **pierwszym w pakiecie 014–021** i realizuje moduł **M1** oraz sezonową część **M2**,
wraz z punktami elastyczności **F1** i **F2** z [Wymagań konfiguracji sprzedaży krótkoterminowej](../../project/etapy/00-konfiguracja-sprzedazy/WYMAGANIA-SPRZEDAZ-KROTKOTERMINOWA.md).
Uzasadnienia biznesowe i granice zakresu są tam.

## Wymagania

### Zmiany w tabeli `fisheries`

| Zmiana | Kolumna | Typ | Uwagi |
|---|---|---|---|
| dodać | `sale_mode` | `string(20)`, domyślnie `daily_period` | rzutowane na enum `SaleMode`; dziś jedna wartość |
| dodać | `day_start_time` | `time` nullable | godzina rozpoczęcia doby |
| dodać | `day_end_time` | `time` nullable | godzina zakończenia doby, dnia następnego |
| dodać | `timezone` | `string(64)`, domyślnie `Europe/Warsaw` | strefa, w której liczona jest doba |

Kolumny godzinowe są `nullable` ze względu na istniejące wiersze; formularz wymaga obu przy trybie
`daily_period`. `timezone` jest wypełniona zawsze — migracja ustawia wartość domyślną wszystkim
istniejącym łowiskom.

### Nowa tabela `sale_periods`

| Kolumna | Typ | Uwagi |
|---|---|---|
| `id` | `id()` | |
| `fishery_id` | `foreignId`, **wymagany**, `cascadeOnDelete()` | ⚠️ świadome odstępstwo od `positions` — patrz „Rozstrzygnięcia" |
| `name` | `string` nullable | nazwa własna okresu, dla orientacji operatora |
| `starts_on` | `date` | |
| `ends_on` | `date` | |
| | `softDeletes()`, `timestamps()` | |
| — | `index(['fishery_id','starts_on'])` | |

### Enum — `app/Enums/`

`SaleMode`: dziś jedna wartość `daily_period` (doba od stałej godziny). Enum, a nie stała, żeby
dołożenie trybu godzinowego było dopisaniem wartości. Etykiety w `lang/pl.json`.

⚠️ To zadanie **zakłada katalog `app/Enums/`**, którego dziś nie ma. Kolejne zadania pakietu z niego korzystają.

### Pojęcie doby — jedno miejsce w kodzie

Klasa wyliczająca doby dla łowiska. Odpowiada na trzy pytania i **jest jedynym miejscem, które to
robi** — ani zasoby Filamenta, ani przyszłe zapytania o dostępność nie liczą dób po swojemu:

- podaj dobę rozpoczynającą się danego dnia → przedział dwóch momentów;
- podaj zakres dat → lista dób w nim zawartych;
- podaj dobę i łowisko → czy jest sprzedawalna, a jeśli nie, to z jakiego powodu.

Wyliczenia biegną w strefie czasowej łowiska (`fisheries.timezone`), nie w strefie aplikacji.

### Reguły granic — rdzeń tego zadania

**Doba** trwa od `day_start_time` dnia D do `day_end_time` dnia D+1, w strefie czasowej łowiska.
Identyfikuje ją **dzień rozpoczęcia**. Biegnie po zegarze lokalnym, więc przy zmianie czasu trwa 23
albo 25 godzin; cena za dobę od jej długości nie zależy.

**Okres sprzedaży** wyznaczają daty kalendarzowe: okno to `starts_on` od północy do `ends_on` do
końca dnia, w strefie łowiska.

**Doba jest sprzedawalna wtedy i tylko wtedy, gdy mieści się w oknie okresu w całości.** Nie
wystarczy, że się z nim styka — musi się zaczynać i kończyć wewnątrz.

Przykład dla doby 15:00 → 15:00 i okresu od 1 lutego do 30 września:

| Doba | Przedział | Sprzedawalna | Dlaczego |
|---|---|---|---|
| rozpoczynająca się 31.01 | 31.01 15:00 → 01.02 15:00 | **nie** | zaczyna się przed oknem |
| rozpoczynająca się 01.02 | 01.02 15:00 → 02.02 15:00 | **tak** | pierwsza sprzedawalna doba sezonu |
| rozpoczynająca się 29.09 | 29.09 15:00 → 30.09 15:00 | **tak** | ostatnia sprzedawalna doba sezonu |
| rozpoczynająca się 30.09 | 30.09 15:00 → 01.10 15:00 | **nie** | kończy się po oknie |

Skutek praktyczny, wart zapamiętania: **ostatnie pozwolenie jednodobowe kupuje się na przedostatni
dzień okresu i obowiązuje do godziny rozpoczęcia doby ostatniego dnia.** Sprzedaż nigdy nie wystawia
doby, która wystawałaby poza sezon.

**Reguła dla ograniczeń i blokad jest inna i celowo asymetryczna.** Okres sprzedaży mówi, co wolno
sprzedać, więc wymaga zawierania. Ograniczenie mówi, co jest wyłączone, więc wystarczy **dowolne
przecięcie**: doba jest objęta ograniczeniem, jeżeli jakakolwiek jej część wypada w okresie
ograniczenia. Dzięki temu nie da się sprzedać doby, która wpadałaby w zakaz choćby na godzinę.
Sprzedanych rezerwacji ta reguła nie rusza — te obsługuje osobna zasada z modułu M2.

Zadanie 015 tej drugiej reguły nie implementuje; **definiuje ją**, bo korzystają z niej blokady
i ograniczenia z zadania 016 — jedyne miejsce, w którym w tym pakiecie występują zakresy dat
wyłączające coś ze sprzedaży.

### Pozostałe reguły zachowania

- **Okresy sprzedaży w obrębie jednego łowiska nie mogą na siebie zachodzić** — sprzeczność wychodzi
  przy zapisie, nie u wędkarza.
- **`ends_on` nie może być wcześniejsze niż `starts_on`.**
- **Brak zdefiniowanego okresu sprzedaży oznacza, że łowisko nie sprzedaje nic.** Sprzedawalność
  wymaga otwartego okresu, nie jego braku. Migracja nie zakłada okresów istniejącym łowiskom.
- **Zmiana godziny doby obowiązuje od daty zapisu i nie zmienia dób już sprzedanych.** Sprzedaży
  jeszcze nie ma, ale reguła powstaje razem z pojęciem, a nie później.
- **Tryb sprzedaży `daily_period` wymaga, żeby doba kończyła się dnia następnego** — przedział zawsze
  przechodzi przez północ.

### Panel — jeden ekran „Sprzedaż i sezony"

Konfiguracja doby i okresów mieszka na **jednej stronie ustawień**, zgodnie z makietą
[`makiety-panelu-lowiska-v2.html`](../../project/mockups/makiety-panelu-lowiska-v2.html): tryb sprzedaży,
godziny doby i okresy sprzedaży jako `Repeater`, z jednym „Zapisz". Operator nie potrafi ustawić
jednego bez drugiego, więc rozbicie tego na formularz łowiska i osobną zakładkę listy byłoby
rozcięciem jednej decyzji na dwa ekrany.

| Element | Kształt |
|---|---|
| strona ustawień | strona zasobu `FisheryResource` (`getPages()`), wejście z huba; **nie** strona panelu i **nie** `RelationManager` |
| tryb sprzedaży, godziny doby, strefa czasowa | pola na tej stronie |
| okresy sprzedaży | `Repeater` na tej samej stronie; `SalePeriod` nie dostaje własnych stron CRUD |
| okruszki i powrót po zapisie | `Helper::fisheryBreadcrumbs()` i `Helper::fisheryHubUrl()`, jak strony zasobów podrzędnych |
| autoryzacja | jawna — `Gate::allows('update', $fishery)` + `Helper::scopeToOwnedFisheries()` |

⚠️ **Pola doby NIE wchodzą do `FisheryResource::fisheryDetailComponents()`.** Ta metoda jest
współdzielona z krokiem „Fishery" kreatora (`CreateFishery`), więc dopisanie tam pól wymaganych
wydłużyłoby onboarding o decyzję, której nowy właściciel jeszcze nie umie podjąć. Łowisko powstaje
niesprzedające i to jest stan zamierzony — spójny z regułą „brak okresu sprzedaży = brak sprzedaży".
`FisheryWizardTest` zostaje nietknięty.

⚠️ Strona zasobu, a nie strona panelu, ma konkretny skutek: `FisheryResource` jest już zarejestrowany
w obu panelach, więc **to zadanie nie dotyka `OwnerPanelProvider` ani `AdminPanelProvider`** — czyli
nie trafia w ten wyzwalacz T3. Wzorzec i jego uzasadnienie:
[ADR-006, aktualizacja z zadania 015](../../adr/ADR-006-natywne-komponenty-filamenta-zamiast-recznych-przeplywow.md).

Model `SalePeriod` z `SoftDeletes`, `LogsActivity` i `HasFactory`, relacja `belongsTo(Fishery)`;
`Fishery` dostaje `hasMany(SalePeriod)` oraz nowe kolumny w `$fillable` (co rozszerza listę pól
w dzienniku zmian — stąd `ActivityLoggingTest` w zakresie testów).

⚠️ **`SalePeriod` nie dostaje własnej polityki ani uprawnień Shielda.** `shield:generate` wyprowadza
uprawnienia z **zarejestrowanych zasobów**, a okres sprzedaży świadomie zasobu nie ma. Polityka
pytająca o `'view_any:sale_period'` nazwałaby uprawnienie, którego generator nigdy nie utworzy —
i wywróciłaby dokładnie ten `ShieldPermissionNamesTest`, na który to zadanie się powołuje. Dostępu
pilnuje istniejąca `FisheryPolicy`: okres sprzedaży istnieje wyłącznie przez łowisko, więc kto może
je edytować, ten edytuje jego sezony. **Niezmiennik: polityka w `app/Policies/` odpowiada zasobowi
Filamenta jeden do jednego**; model bez zasobu autoryzuje się przez rodzica.
Dziś czternaście polityk odpowiada trzynastu zasobom z `app/Filament/Resources/` **plus zasobowi
ról dostarczanemu przez Shielda** (stąd `RolePolicy` bez pliku w katalogu zasobów) — licznik zgadza
się dopiero z tym czternastym.

### Dom logiki wyliczanej

- **Klasa licząca doby żyje w `app/Services/`** — tak samo jak usługi stanu efektywnego i cech
  z zadania 014, zgodnie z regułą „logika obliczeniowa ma jeden dom" z `CLAUDE.md`. Nie `app/Helpers/`:
  `Helper` jest zbiorem funkcji pomocniczych panelu, a to jest pojęcie domenowe.
- **Walidacja nienachodzenia okresów idzie do `app/Rules/`**, nie do formularza strony ustawień —
  inaczej druga ścieżka zapisu (import, seed, przyszłe API) obejdzie regułę.
  ⚠️ Sprawdzenie **pomija okresy usunięte miękko**: wycofany sezon nie może blokować założenia nowego
  na te same daty.

### Tłumaczenia

Nowe etykiety pól, tytuł strony ustawień i etykiety wartości enumu `SaleMode` w `lang/pl.json`.
(Nazwy zasobu nie ma — `SalePeriod` zasobu nie dostaje.)

## Kryteria akceptacji

- [ ] Migracje przechodzą w obie strony na bazie zawierającej dane.
- [ ] Wszystkie istniejące łowiska mają po migracji wypełnioną strefę czasową.
- [ ] Doba wyliczana jest w strefie czasowej łowiska, a nie aplikacji — test przechodzi przy
      ustawieniu innej strefy aplikacji.
- [ ] Doba obejmująca zmianę czasu jest **jedną** dobą i trwa 23 albo 25 godzin, zależnie od kierunku
      zmiany.
- [ ] Dla doby 15:00 → 15:00 i okresu 01.02–30.09 sprzedawalne są doby rozpoczynające się od 01.02 do
      29.09 włącznie; doba rozpoczynająca się 31.01 i doba rozpoczynająca się 30.09 są odrzucane —
      każda z innego powodu.
- [ ] Odmowa sprzedaży niesie powód, a nie samo „nie".
- [ ] Zapis dwóch nachodzących na siebie okresów w jednym łowisku jest odrzucany.
- [ ] `ends_on` wcześniejsze niż `starts_on` jest odrzucane.
- [ ] Łowisko bez zdefiniowanego okresu nie ma ani jednej doby sprzedawalnej.
- [ ] Zmiana godziny doby obowiązuje od daty zapisu.
- [ ] Właściciel nie widzi ani nie edytuje okresów sprzedaży cudzego łowiska.
- [ ] `ShieldPermissionNamesTest` jest zielony, a w `app/Policies/` **nie przybywa** pliku dla
      `SalePeriod` — dostępu pilnuje `FisheryPolicy`.
- [ ] Trwałe usunięcie łowiska zabiera ze sobą jego okresy sprzedaży; w bazie nie zostaje okres bez
      łowiska.
- [ ] Zielony zakres T2 zadeklarowany niżej.
- [ ] **Pełny pakiet testów ODROCZONY** do punktu kontrolnego A (po zadaniach 014–016) — zgodnie
      z rozdziałem 14.2 wymagań; odroczony, nie pominięty.

## Zakres testów

- **Tier:** T2
- **Uruchamiamy:** `docker compose exec app php artisan test --filter="FishingDayTest|SalePeriodTest|SaleSettingsPageTest|FisheryWizardTest|FisheryWizardEntryPointsTest|HelperFisheryAccessTest|ActivityLoggingTest|ShieldPermissionNamesTest|AdminPanelTest|OwnerPanelTest"`
- **Uzasadnienie:** zadanie trafia w **jeden** wyzwalacz T3 z `CLAUDE.md` — migracje. Polityki nie
  dokłada (autoryzacja idzie przez istniejącą `FisheryPolicy`), a providerów paneli nie dotyka, bo
  strona ustawień jest stroną `FisheryResource`, zarejestrowanego w obu panelach już dziś. Odstępstwo jest **pakietowe, nie punktowe**:
  rozdział 14.2 [wymagań](../../project/etapy/00-konfiguracja-sprzedazy/WYMAGANIA-SPRZEDAZ-KROTKOTERMINOWA.md) ustala T2 dla zadań
  014–021 i pełny pakiet na trzech punktach kontrolnych; dla tego zadania jest to punkt **A**, po
  zadaniach 014–016. Zakres T2 obejmuje testy łowiska i jego formularza, zawężanie widoczności do
  własnych łowisk, granice obu paneli oraz klasy testowe nowe w tym zadaniu: wyliczanie dób, okresy
  sprzedaży i strona ustawień.
  ⚠️ `ActivityLoggingTest` sprawdza dziś **wyłącznie `Country`** i rozszerzonej listy pól `Fishery`
  nie dotknie — zostaje w filtrze jako tani bezpiecznik zmiany w `LogsActivity`, a nie jako dowód,
  że nowe pola trafiają do dziennika. Ten dowód, jeśli ma powstać, należy do nowych klas testowych.
  `ShieldPermissionNamesTest` zostaje w filtrze mimo braku nowej polityki — jest tani, a zadanie
  dokłada ekran pytający politykę wprost.
  ⚠️ Nazwy trzech pierwszych klas w filtrze są **propozycją** — jeśli implementacja nazwie je inaczej,
  poprawia filtr razem z nimi, zamiast zostawiać komendę, która cicho nic nie uruchamia.
  ⚠️ Zadanie nie trafia do `tasks/implemented/`, dopóki punkt kontrolny A nie jest zielony.

## Zakres wyłączeń

- **Blokady terminów** — zadanie 016. Tutaj powstaje wyłącznie reguła przecięcia, z której blokady
  skorzystają.
- **Reguły długości pobytu, terminy sprzedawane w całości, okna wcześniejszej sprzedaży, horyzont
  sprzedaży** — zadanie 017.
- **Cennik i wycena doby** — zadanie 018. Tutaj powstaje jednostka, nie jej cena.
- **Kalendarz podglądowy** pokazujący skutek ustawień — zadanie 019.
- **Sprzedaż w przedziałach godzinowych oraz turnus jako samodzielny produkt** — poza zakresem
  iteracji; enum trybu sprzedaży istnieje po to, żeby dołożenie było dopisaniem wartości.
- **Godziny przyjmowania zamówień** — portal sprzedaje całą dobę, operator tego nie ustawia.
- **Automatyczne zakładanie okresów sprzedaży istniejącym łowiskom** — migracja tego nie robi.

## Zmiany dokumentacji

- [x] `docs/conventions/panel-wlasciciela.md` — dwie rzeczy, nie jedna:
      (1) definicja doby i okresów sprzedaży, reguła zawierania dla sezonu i reguła przecięcia dla
      ograniczeń, strefa czasowa łowiska jako źródło prawdy — niezmiennik + odsyłacz do ADR-010;
      (2) **granica między dwoma rodzajami zakładki huba**: lista rekordów → RelationManager,
      konfiguracja zapisywana jednym „Zapisz" → strona ustawień zasobu `FisheryResource` —
      niezmiennik + odsyłacz do aktualizacji ADR-006. Uzasadnienia zostają w ADR-ach
- [x] `README.md` — bez zmian
- [x] `docs/conventions/autoryzacja.md` — niezmiennik **„polityka odpowiada zasobowi Filamenta;
      model bez zasobu autoryzuje się przez rodzica"** wraz z powodem technicznym (`shield:generate`
      wyprowadza uprawnienia z zarejestrowanych zasobów, a `ShieldPermissionNamesTest` porównuje
      z nimi literały z polityk **oraz drogą wyjścia**: `custom_permissions`
      w `config/filament-shield.php` pozwala wygenerować uprawnienie bez zasobu, więc różnicowanie
      dostępu później nie wymaga odwracania tej decyzji)
- [x] `CLAUDE.md` — **jedno zdanie do przepisania** w „Konwencjach kodu": „Nowy model dostaje własną
      politykę" → „Nowy model dostaje własną politykę **albo autoryzuje się przez rodzica, gdy nie ma
      własnego zasobu Filamenta** — patrz `docs/conventions/autoryzacja.md`".
      ⚠️ Przepisać, nie dopisać korekty obok: reguła w dzisiejszym brzmieniu każe zadaniom 016–021
      zakładać polityki, które wywrócą `ShieldPermissionNamesTest`
- [x] `CHANGELOG.md` — wpis w changelogu

## Ograniczenia techniczne

- Laravel 13, Filament 5, PHP 8.4 — stan po zadaniu 009.
- **Osobny plik migracji na każdą tabelę**: zmiany w `fisheries` i nowa tabela `sale_periods`.
- **Strefa czasowa łowiska jest źródłem prawdy** przy każdym wyliczeniu doby. Strefa aplikacji
  (`config/app.php`) nie bierze w tych wyliczeniach udziału.
- Momenty graniczne przechowywane i porównywane jako punkty w czasie, nie jako daty lokalne —
  inaczej porównanie przez zmianę czasu daje wynik zależny od kolejności rzutowania.
- **To zadanie idzie jako pierwsze w pakiecie**, przed 014. Pojęcie doby jest podstawą, na której
  stoją wszystkie kolejne moduły, a jego rozstrzygnięcia — definicja doby i obie reguły granic —
  wiążą zadania 016, 017 i 018.
- Zadanie dotyka `FisheryResource`, do którego zadanie 014 dokłada potem zakładkę grup stanowisk.
  Jedyna przewidywana kolizja plików w pakiecie — oba zadania ruszają `getPages()`/`getRelations()`
  tej samej klasy.
- Nazwy tabel, kolumn, klas i tras po angielsku; dokumentacja po polsku.
- Testy biegną na MySQL-u w schemacie `lowiska_test`.

## Rozstrzygnięcia

- **Konfiguracja doby i okresów to jeden ekran ustawień, zgodnie z makietą v2** — nie formularz
  łowiska plus `SalePeriodResource` z pełnym CRUD-em. Operator nie umie ustawić doby bez sezonu ani
  sezonu bez doby, a okres sprzedaży (dwie daty i nazwa) nie ma czego pokazać na własnej stronie.

- **Kreator zakładania łowiska nie pyta o godziny doby.** Pola doby żyją poza
  `fisheryDetailComponents()`, więc nie trafiają do kroku „Fishery". Łowisko powstaje niesprzedające
  i to jest spójne z regułą „brak okresu sprzedaży = brak sprzedaży"; onboarding nie wymusza decyzji,
  której nowy właściciel jeszcze nie umie podjąć.

- **Klasa licząca doby mieszka w `app/Services/`, walidacja nienachodzenia okresów w `app/Rules/`.**
  Zgodnie z regułą „logika obliczeniowa i walidacyjna ma jeden dom" z `CLAUDE.md` i spójnie
  z zadaniem 014. `app/Helpers/` odpada: `Helper` jest zbiorem funkcji pomocniczych panelu, nie
  miejscem na pojęcie domenowe. Sprawdzenie nachodzenia **pomija okresy usunięte miękko** — wycofany
  sezon nie może blokować założenia nowego na te same daty.

- **Brak okresu sprzedaży oznacza brak sprzedaży — potwierdzone świadomie.** Reguła jest odwrotna do
  intuicji „nic nie ustawiłem, więc sprzedaję normalnie", ale wynika wprost z F2 i myli się wyłącznie
  w stronę odmowy: łowisko bez konfiguracji nie sprzeda doby, której nie umie wycenić ani obsłużyć.

- **Model bez zasobu Filamenta autoryzuje się przez rodzica — niezmiennik, nie wyjątek dla tego
  zadania.** `SalePeriod` nie dostaje polityki ani uprawnień Shielda; dostępu pilnuje `FisheryPolicy`.
  Powód jest techniczny i sprawdzony: `shield:generate` wyprowadza uprawnienia z **zarejestrowanych
  zasobów**, a `ShieldPermissionNamesTest` skanuje `app/Policies/*.php` po literałach uprawnień
  i porównuje je z faktycznym wynikiem generatora — polityka pytająca o `'view_any:sale_period'`
  wywróciłaby go natychmiast.
  ⚠️ Reguła wiąże **cały pakiet**: 016–021 dokładają kolejne modele żyjące wyłącznie na ekranach
  ustawień. Ląduje w `docs/conventions/autoryzacja.md`, a zdanie w `CLAUDE.md` „Nowy model dostaje
  własną politykę" zostaje **przepisane** — nie zostawiamy w konstytucji reguły, która każe
  następnym zadaniom złamać ten test.

  **Jak to odwrócić, gdy uprawnienia trzeba będzie zróżnicować** (np. pracownik łowiska zarządza
  stanowiskami, ale nie rusza sezonów i cen) — sprawdzone w kodzie pakietu, nie z pamięci:
  1. **Droga podstawowa:** dodać metodę do istniejącej polityki (`FisheryPolicy::updateSaleSettings()`)
     i wołać ją ze strony ustawień zamiast `update`. Jeśli reguła daje się wyrazić bez **nowego
     literału uprawnienia** — rolą, istniejącym uprawnieniem, warunkiem na rekordzie — to jest cała
     robota: zero zmian w Shieldzie, zero w schemacie, `ShieldPermissionNamesTest` nietknięty.
  2. **Tylko jeśli potrzebne jest nazwane uprawnienie Shielda** (żeby dało się je klikać przy roli):
     dopisać klucz do `custom_permissions` w `config/filament-shield.php` (dziś pusta tablica)
     i przegenerować. `shield:generate` tworzy uprawnienia **bez zasobu** — robi to
     `generateCustomPermissions()` wołane z `GenerateCommand`. Dopiero wtedy polityka może pytać
     o ten literał i nadal przechodzić test.
  3. Przypisać rolom.

  ⚠️ Żaden z tych kroków nie rusza schematu, danych ani ścieżek odczytu — **strona ustawień pyta
  politykę w jednym miejscu** i to jedyne miejsce do zmiany. Dlatego dzisiejsze uproszczenie jest
  odroczeniem decyzji, a nie zamknięciem drogi.
  ⚠️ Co ono jednak **znaczy dzisiaj**, wprost: kto może edytować łowisko, ten może zmienić jego
  sezony sprzedaży. Rozróżnienia nie ma, dopóki ktoś go nie wprowadzi powyższą drogą.

- **`sale_periods.fishery_id` jest wymagany i kasuje się kaskadowo** — świadome odstępstwo od
  `positions`/`position_groups`, gdzie klucz jest `nullable` z `set null`. Stanowisko po odłączeniu
  od łowiska wciąż wisi w pozwoleniach i usługach, więc ma wartość historyczną; okres sprzedaży nie
  ma żadnej, a po tym, jak autoryzacja idzie wyłącznie przez łowisko, osierocony wiersz staje się
  danymi, do których nie prowadzi ani jedna ścieżka. Schemat pilnuje tu tego, czego pilnowałaby
  polityka, której ten model świadomie nie ma. `Fishery` ma `SoftDeletes`, więc kaskada odpala się
  dopiero przy trwałym usunięciu.

- **Terminologia: „grupa stanowisk", nie „strefa".** Ustalone przy tym przeglądzie; zadanie 014
  zostało w międzyczasie przepisane pod tę nazwę (`position_groups`) razem z przebudową samego
  pojęcia. „Strefa" sugerowała obszar na mapie, którego nie ma.

## Powiązane ADR-y

- [ADR-010 — Doba wędkarska jako przedział czasu i dwie reguły granic](../../adr/ADR-010-doba-wedkarska-jako-przedzial-czasu.md)
  — status `proposed`, **Decyzja do wypełnienia przez autora**. Obejmuje trzy rzeczy: dobę jako
  przedział w strefie czasowej łowiska, **zawieranie** dla okresu sprzedaży i **przecięcie** dla
  blokad i ograniczeń. Wiąże zadania 016, 017, 018 i 019.
- [ADR-006 — Natywne komponenty Filamenta…](../../adr/ADR-006-natywne-komponenty-filamenta-zamiast-recznych-przeplywow.md),
  **aktualizacja z zadania 015**: zakładka konfiguracyjna huba jest stroną ustawień, nie
  RelationManagerem. Rozszerzenie istniejącego ADR-a zamiast nowego pliku — decyzja jest wariantem
  już podjętej. Wiąże pięć kolejnych ekranów konfiguracyjnych pakietu.

## Otwarte pytania dla `/review-task` — zamknięte

1. **Doba jako przedział czasu i reguły granic** → [ADR-010](../../adr/ADR-010-doba-wedkarska-jako-przedzial-czasu.md).
   Spełnia wszystkie trzy warunki; przesądził **zasięg** — z definicji korzystają zadania 016–019
   i każde przyszłe zapytanie o dostępność.
2. **Nazwa i umiejscowienie klasy liczącej doby** → rozstrzygnięcie w treści zadania (`app/Services/`).
   Nie ADR: `CLAUDE.md` już odpowiada na to regułą o jednym domu dla logiki, a zmiana katalogu jest
   przeniesieniem pliku, nie migracją.
3. **Gdzie w panelu mieszka definicja doby** → rozstrzygnięcie w treści zadania (jeden ekran wg
   makiety) **plus aktualizacja [ADR-006](../../adr/ADR-006-natywne-komponenty-filamenta-zamiast-recznych-przeplywow.md)**.
   Samo umiejscowienie pól jest anty-sygnałem, ale „zakładka konfiguracyjna nie jest
   RelationManagerem" wiąże pięć kolejnych ekranów pakietu.
4. **Brak okresu sprzedaży = brak sprzedaży** → potwierdzone, rozstrzygnięcie w treści zadania.

Doszedł temat spoza listy: **terminologia „grupa" zamiast „strefa"**, wywołany przemianowaniem
makiety 014. Rozstrzygnięty tutaj; zadanie 014 przyjęło tę nazwę przy własnej przebudowie, w której
grupa przestała być poziomem pośrednim, a stała się etykietą w relacji wiele-do-wielu.

W drugim przebiegu przeglądu doszły dwa tematy, oba **rozstrzygnięte w treści zadania, nie ADR-em**:

5. **Autoryzacja modelu bez zasobu Filamenta.** Zasięg i uzasadnienie spełniają kryterium, ale koszt
   odwrócenia jest średni (dopisanie polityk i wpisów Shielda), a `docs/conventions/autoryzacja.md`
   jest udokumentowanym domem dla niezmienników autoryzacji i wiąże tak samo mocno jak ADR.
   Pociąga za sobą **przepisanie jednego zdania w `CLAUDE.md`**.
6. **Wymagalność i kaskada `sale_periods.fishery_id`** — odstępstwo od wzorca `positions`,
   uzasadnione tym, że okres sprzedaży nie ma wartości historycznej ani ścieżki dostępu poza
   łowiskiem.

⚠️ **To zadanie nie zależy od tamtej przebudowy w żadnym punkcie.** Jedyny wspólny plik to
`FisheryResource` — 014 dokłada zakładkę grup, 015 stronę ustawień „Sprzedaż i sezony". Zależność
idzie w drugą stronę: to 014 korzysta z reguł granic ustalonych tutaj (ADR-010).
