# 014 — Stanowiska, grupy i cechy

## Opis problemu

Stanowisko ma dziś `name`, `description`, `fishery_id` i binarne `is_active`. Dowolna etykieta
i zachowanie historii po wycofanym stanowisku już działają. Brakuje trzech rzeczy:

1. **Pojemności** — ilu wędkarzy może łowić na stanowisku i ile osób może na nim przebywać.
   Pojemność jest wejściem do wyceny, nie opisem.
2. **Cech, po których wybiera się stanowisko** — dziś mieszczą się wyłącznie w polu opisu, więc nie
   da się po nich filtrować ani porównywać między łowiskami.
3. **Sposobu opisania i zaznaczania grup stanowisk** — część informacji dotyczy zbioru miejsc,
   a część czynności operatora powtarza się na kilkunastu rekordach naraz.

Zadanie realizuje moduł **M5** wraz z punktem elastyczności **F4** z
[Wymagań konfiguracji sprzedaży krótkoterminowej](../../project/etapy/00-konfiguracja-sprzedazy/WYMAGANIA-SPRZEDAZ-KROTKOTERMINOWA.md).
Makieta docelowych ekranów wraz z deltą pól:
[`makieta-014-stanowiska-grupy-i-cechy.html`](../../project/mockups/makieta-014-stanowiska-grupy-i-cechy.html).

⚠️ **To jest przepisana wersja zadania.** Pierwotna opierała się na strefie jako poziomie pośrednim
z dziedziczeniem cech i własnym stanem czasowym. Model został przebudowany, a trzy ADR-y powstałe przy
pierwszym przeglądzie są wycofane — patrz sekcja „Powiązane ADR-y".

## Wymagania

### Nowa tabela `position_groups` — grupa stanowisk

| Kolumna | Typ | Uwagi |
|---|---|---|
| `id` | `id()` | |
| `fishery_id` | `foreignId`, **wymagany**, `cascadeOnDelete()` | ⚠️ świadome odstępstwo od `positions` — patrz „Rozstrzygnięcia" |
| `name` | `string` | wymagane w formularzu |
| `description` | `text` nullable | treść widoczna dla wędkarza; tu mieści się dojazd, charakterystyka brzegu i wszystko, czego flaga nie uniesie |
| | `softDeletes()`, `timestamps()` | |

Grupa **nie niesie cech ani żadnego stanu**. Jest etykietą, nośnikiem opisu i zapisanym zaznaczeniem.

### Nowa tabela pośrednia `group_position`

| Kolumna | Typ | Uwagi |
|---|---|---|
| `position_id` | `foreignId`, `cascadeOnDelete()` | |
| `position_group_id` | `foreignId`, `cascadeOnDelete()` | |
| — | `primary(['position_id','position_group_id'])` | |

Relacja jest **wiele-do-wielu**: stanowisko należy do dowolnej liczby grup, grupa zbiera dowolną
liczbę stanowisk. Nazwa tabeli jest ustawiona jawnie, bo domyślna (`position_position_group`) jest
nieczytelna.

### Nowa tabela `position_attributes` — słownik cech, panel administratora

| Kolumna | Typ | Uwagi |
|---|---|---|
| `id` | `id()` | |
| `name` | `string` | jak w pozostałych słownikach portalu |
| `type` | `string(20)` | rzutowane na enum `PositionAttributeType`: `flag`, `number`, `choice` |
| `unit` | `string(20)` nullable | wyłącznie dla `type = number` |
| `is_filterable` | `boolean`, domyślnie `false` | znacznik na przyszłą wyszukiwarkę; sam filtr poza zakresem |
| | `softDeletes()`, `timestamps()` | |

Słownik jest **wspólny dla całego portalu i wyłącznie w rękach administratora**. Cechy własne łowiska
nie powstają — patrz „Zakres wyłączeń".

### Nowa tabela `position_attribute_options` — wartości dla cech typu `choice`

| Kolumna | Typ | Uwagi |
|---|---|---|
| `id` | `id()` | |
| `position_attribute_id` | `foreignId`, `cascadeOnDelete()` | |
| `name` | `string` | |
| `sort_order` | `unsignedSmallInteger`, domyślnie `0` | |
| | `softDeletes()`, `timestamps()` | |

### Nowa tabela `position_attribute_values` — wartości cech na stanowisku

| Kolumna | Typ | Uwagi |
|---|---|---|
| `id` | `id()` | |
| `position_id` | `foreignId`, `cascadeOnDelete()` | **jedyny właściciel wartości** |
| `position_attribute_id` | `foreignId`, `cascadeOnDelete()` | |
| `value_flag` | `boolean` nullable | dla `type = flag` |
| `value_number` | `decimal(8,2)` nullable | dla `type = number` |
| `position_attribute_option_id` | `foreignId` nullable, `onDelete('set null')` | dla `type = choice` |
| | `timestamps()` | |
| — | `unique(['position_id','position_attribute_id'])` | jedna wartość cechy na stanowisko |

### Zmiany w tabeli `positions`

| Zmiana | Kolumna | Typ | Uwagi |
|---|---|---|---|
| dodać | `max_anglers` | `unsignedTinyInteger` nullable | wymagane w formularzu; nullable ze względu na istniejące wiersze |
| dodać | `max_people` | `unsignedTinyInteger` nullable | łącznie z osobami niełowiącymi |
| dodać | `status` | `string(20)`, domyślnie `available` | enum `PositionStatus`: `available`, `withdrawn` |
| dodać | — | `unique(['fishery_id','name'])` | indeks **obejmuje wiersze usunięte miękko**, przez co etykieta wycofanego stanowiska nie wraca do obiegu |
| usunąć | `is_active` | `boolean` | po przeniesieniu danych |

**Migracja danych:** `is_active = 1` → `status = 'available'`, `is_active = 0` → `status = 'withdrawn'`.

⚠️ Nałożenie indeksu unikalnego może się nie powieść, jeśli w obrębie jednego łowiska są już
duplikaty etykiet. Migracja ma to wykryć i przerwać z czytelnym komunikatem, a nie paść na błędzie
bazy.

**Czego w `positions` nie ma:** kolumny grupy (relacja jest wiele-do-wielu, więc mieszka w tabeli
pośredniej), żadnej kolumny dojazdu (jest opisem grupy albo stanowiska) ani żadnych kolumn dat
i przyczyn ograniczenia (ograniczenia to osobne rekordy z zadania 016).

### Enumy — `app/Enums/`

- `PositionStatus`: `available`, `withdrawn`. **Stan własny stanowiska, niezależny od czasu** —
  decyzja operatora, czy miejsce jest w sprzedaży. Dostępność w konkretnym terminie jest czymś innym
  i wylicza ją zadanie 016.
- `PositionAttributeType`: `flag`, `number`, `choice`.

Etykiety obu enumów w `lang/pl.json`.

### Modele

| Model | Relacje | Uwagi |
|---|---|---|
| `PositionGroup` (nowy) | `belongsTo(Fishery)`, `belongsToMany(Position)` | `SoftDeletes`, `LogsActivity`, `HasFactory` |
| `PositionAttribute` (nowy) | `hasMany(PositionAttributeOption)`, `hasMany(PositionAttributeValue)` | `SoftDeletes`, `LogsActivity` |
| `PositionAttributeOption` (nowy) | `belongsTo(PositionAttribute)` | `SoftDeletes` |
| `PositionAttributeValue` (nowy) | `belongsTo(Position)`, `belongsTo(PositionAttribute)`, `belongsTo(PositionAttributeOption)` | bez `SoftDeletes` |
| `Position` (zmiana) | dochodzi `belongsToMany(PositionGroup)` i `hasMany(PositionAttributeValue)` | |
| `Fishery` (zmiana) | dochodzi `hasMany(PositionGroup)` | |

**`Position` — zmiany szczegółowe:**

- `$fillable`: usunąć `is_active`, dodać `max_anglers`, `max_people`, `status`;
- `$casts`: `status` na enum;
- zakres `isActive()` zastąpić zakresem odpowiadającym na to samo pytanie w nowym modelu; wywołania
  zakresu na **innych** modelach (`LongTermPermit`, `AdditionalService`) zostają nietknięte;
- `LogsActivity` loguje `$fillable`, więc lista pól w dzienniku zmienia się automatycznie.

### Akcja zbiorcza — ustawianie cechy wielu stanowiskom

Zastępuje dziedziczenie. **Zapisuje wartość wprost na każdym stanowisku**, więc po wykonaniu każde
niesie własną wartość i nic nie jest rozwiązywane przy odczycie.

- **Prymitywem jest akcja na zaznaczonych wierszach tabeli stanowisk:** zaznacz stanowiska, wybierz
  cechę, wybierz wartość, zapisz. Działa niezależnie od tego, czy łowisko używa grup.
- **Akcja z poziomu grupy jest skrótem do tego samego** — z zaznaczeniem wypełnionym stanowiskami tej
  grupy. Ten sam kod, drugie wejście.
- Przed zapisem akcja podaje, ilu stanowisk dotyczy.
- **Nie powstaje wariant „ustaw wszystkim" bez wskazania wartości** — to byłoby dziedziczenie
  tylnymi drzwiami, tylko niewidoczne.

### Reguły zachowania

- **`max_anglers` jest wymagane** przy zapisie stanowiska.
- **`max_people`, jeśli podane, nie może być mniejsze niż `max_anglers`.**
- **Wartości cech typu `choice` ograniczone są do opcji przypisanej cechy.**
- **Dokładnie jedna z trzech kolumn wartości jest wypełniona, zgodnie z `type` cechy.** Reguła nie ma
  odpowiednika w schemacie, więc musi mieć **jeden dom w kodzie** (`app/Rules/`), a nie kopię
  w każdym formularzu.
- **Brak wiersza to trzeci stan, nie „nie".** Cecha niewypełniona oznacza „nikt się nie wypowiedział"
  i **nie bierze udziału w filtrowaniu w żadną stronę**. W panelu jest widoczna jako nieuzupełniona,
  żeby operator wiedział, co zostawił.
- **Grupa i stanowisko muszą należeć do tego samego łowiska.**
- **Grupa nie jest jednostką sprzedaży.** Kupuje się stanowisko; grupa opisuje i zaznacza.
- **Cechy powstają wyłącznie dla rzeczy niekupowalnych.** Wszystko, co wędkarz dokupuje, jest usługą
  dodatkową i należy do zadania 020.

### Zasoby Filamenta

| Zasób | Panel | Wzorzec |
|---|---|---|
| `PositionAttributeResource` (nowy) | administrator | jak `ConvenienceResource`; opcje cechy jako `Repeater` w formularzu |
| `PositionGroupResource` (nowy) | właściciel i administrator | jak `PositionResource`: `shouldRegisterNavigation()` = `false`, zawężenie przez `Helper::scopeToOwnedFisheries()`; ⚠️ **wymaga wpisu w `OwnerPanelProvider`** — panel administratora odkrywa zasoby katalogiem, panel właściciela ma listę jawną |
| `PositionGroupsRelationManager` (nowy) | właściciel | jak `PositionsRelationManager` — zakładka huba, `canViewForRecord()` ograniczone do huba, akcje zwykłe zamiast CRUD-owych |
| `PositionResource` (zmiana) | oba | pojemność, przypisanie do grup jako wielokrotny wybór, cechy generowane ze słownika, stan zamiast przełącznika, akcja zbiorcza w tabeli |
| `PositionsRelationManager` (zmiana) | właściciel | plakietka zakładki liczy po nowym stanie |

**Formularz cech generuje się ze słownika** — komponent zależy od typu cechy. Dodanie wpisu do
słownika udostępnia cechę we wszystkich łowiskach bez zmiany kodu i bez migracji.

### Autoryzacja i uprawnienia

- **Polityki wyłącznie dla modeli mających własny zasób Filamenta: `PositionGroup`
  i `PositionAttribute`.** Uprawnienia Shielda dla nich; nazwy zgodne ze wzorcem pilnowanym przez
  `ShieldPermissionNamesTest`.
- ⚠️ **`PositionAttributeOption` i `PositionAttributeValue` polityk NIE dostają.** `shield:generate`
  wyprowadza uprawnienia z zarejestrowanych zasobów, a te dwa modele zasobu nie mają — polityka
  pytająca o `'view_any:position_attribute_value'` nazwałaby uprawnienie, którego generator nigdy nie
  utworzy, i wywróciłaby `ShieldPermissionNamesTest`. Nie mają też własnego cyklu życia: opcja żyje
  przez cechę, wartość przez stanowisko, a edytuje się je wyłącznie z formularzy właścicieli. Dostępu
  pilnują `PositionAttributePolicy` i `PositionPolicy`.
- **Niezmiennik: polityka w `app/Policies/` odpowiada zasobowi Filamenta jeden do jednego; model bez
  zasobu autoryzuje się przez rodzica.** Reguła jest już zapisana —
  [`docs/conventions/autoryzacja.md`](../../conventions/autoryzacja.md) §5 (zadanie 015) — więc to
  zadanie ją **stosuje**, a nie ustanawia na nowo.
  ⚠️ Licznik: dziś czternaście polityk odpowiada trzynastu zasobom z `app/Filament/Resources/`
  **plus zasobowi ról dostarczanemu przez Shielda** (stąd `RolePolicy` bez pliku w katalogu zasobów).
  To zadanie dokłada dwa zasoby i dwie polityki, więc po nim jest szesnaście do szesnastu.

### Tłumaczenia

Nowe etykiety pól, nazwy zasobów i etykiety wartości enumów w `lang/pl.json`.

## Kryteria akceptacji

- [ ] Migracje przechodzą w obie strony na bazie zawierającej dane.
- [ ] Po migracji żadne stanowisko nie traci informacji o dostępności: wcześniejsze `is_active = 1`
      jest `available`, `is_active = 0` jest `withdrawn`.
- [ ] Migracja przerywa się z czytelnym komunikatem, gdy w obrębie łowiska istnieją duplikaty
      etykiet, zamiast padać na błędzie indeksu.
- [ ] Nie da się zapisać drugiego stanowiska o etykiecie już użytej w tym łowisku — również wtedy,
      gdy pierwsze zostało usunięte miękko.
- [ ] Stanowisko zapisuje się z `max_anglers`; próba zapisu bez tej wartości jest odrzucana.
- [ ] `max_people` mniejsze niż `max_anglers` jest odrzucane.
- [ ] Cecha typu `flag`, `number` i `choice` zapisuje się i odczytuje poprawnie.
- [ ] Cecha typu `choice` przyjmuje wyłącznie opcje przypisane do tej cechy.
- [ ] Wartość niezgodna z typem cechy jest odrzucana przez **jedną** regułę, wołaną z każdego miejsca
      zapisu.
- [ ] Brak wiersza jest odróżnialny od wartości fałszywej: stanowisko bez wiersza dla cechy typu
      `flag` ma inny odczyt niż stanowisko z `value_flag = false`.
      ⚠️ Kryterium sprawdza **dane i odczyt**, nie filtr — samo filtrowanie po cechach jest poza
      zakresem (patrz „Zakres wyłączeń"), a zapis „nie trafia do wyniku filtrowania" testowałby
      mechanizm, który w tym zadaniu nie powstaje. Niezmiennik „brak wiersza to trzeci stan" wiąże
      przyszły filtr i jest zapisany w regułach zachowania.
- [ ] Stanowisko należy do wielu grup jednocześnie, a grupa zbiera wiele stanowisk.
- [ ] Próba przypisania stanowiska do grupy innego łowiska jest odrzucana.
- [ ] Akcja zbiorcza ustawia wskazaną cechę na wszystkich zaznaczonych stanowiskach i podaje ich
      liczbę przed zapisem; ta sama akcja wywołana z grupy daje ten sam wynik.
- [ ] Po akcji zbiorczej każde objęte stanowisko ma własny wiersz wartości — nic nie jest
      rozwiązywane przy odczycie.
- [ ] Po migracji plakietka zakładki stanowisk, zakres zapytań i kolumna w tabeli liczą na nowym
      stanie, a nigdzie w kodzie nie pozostaje odwołanie do `positions.is_active`.
- [ ] Istniejące powiązania stanowisk z pozwoleniami długookresowymi i z usługami dodatkowymi działają
      po migracji tak samo jak przed nią.
- [ ] Właściciel nie widzi ani nie edytuje grup cudzego łowiska.
- [ ] Właściciel nie edytuje słownika cech.
- [ ] Zielony zakres T2 zadeklarowany niżej.
- [ ] **Pełny pakiet testów ODROCZONY** do punktu kontrolnego A — odroczony, nie pominięty.

## Zakres testów

- **Tier:** T2
- **Uruchamiamy:** `docker compose exec app php artisan test --filter="PositionGroupTest|PositionAttributeTest|PositionAttributeValueRuleTest|BulkAttributeActionTest|PositionResourceTest|PositionAdditionalServicesTest|LongTermPermitResourceTest|AdditionalServiceResourceTest|ActivityLoggingTest|ShieldPermissionNamesTest|AdminPanelTest|OwnerPanelTest|HelperFisheryAccessTest"`
- **Uzasadnienie:** patrz „Rozstrzygnięcia" — tier T2 utrzymany mimo trzech wyzwalaczy T3, na
  podstawie decyzji pakietowej z rozdziału 14.2
  [wymagań](../../project/etapy/00-konfiguracja-sprzedazy/WYMAGANIA-SPRZEDAZ-KROTKOTERMINOWA.md). Zakres T2 obejmuje testy stanowisk oraz
  wszystkiego, co ze stanowisk korzysta albo dzieli z nimi mechanikę: powiązania z pozwoleniami
  i usługami, dziennik zmian, nazwy uprawnień, granice obu paneli i zawężanie widoczności do własnych
  łowisk oraz klasy testowe nowe w tym zadaniu: grupy, słownik cech, regułę wartości i akcję zbiorczą.
  ⚠️ Nazwy czterech pierwszych klas w filtrze są **propozycją** — jeśli implementacja nazwie je
  inaczej, poprawia filtr razem z nimi, zamiast zostawiać komendę, która cicho nic nie uruchamia.
  ⚠️ `ActivityLoggingTest` sprawdza dziś **wyłącznie `Country`**, więc nie zobaczy zmienionej listy
  pól `Position` ani N wpisów z akcji zbiorczej — zostaje jako tani bezpiecznik traita `LogsActivity`,
  a dowód na ziarnistość dziennika należy do klasy testowej akcji zbiorczej.
  ⚠️ Zadanie nie trafia do `tasks/implemented/`, dopóki punkt kontrolny A nie jest zielony.

## Zakres wyłączeń

- **Blokady i ograniczenia czasowe** — zadanie 016. Powstają tam jako **jeden byt z polem skutku**
  (pełna blokada albo zawieszenie cechy), wskazujący rozwiązany zbiór stanowisk. W tym zadaniu nie ma
  żadnych kolumn dat, przyczyn ani stanu zależnego od czasu.
- **Definicja doby i okresy sprzedaży** — zadanie 015, które idzie **przed** tym zadaniem.
- **Wycena i warunki cenowe oparte na pojemności** — zadanie 018.
- **Jednostka rozliczenia i limit egzemplarzy usług** — zadanie 020.
- **Filtrowanie po cechach w portalu wędkarza** — powstaje wyłącznie znacznik `is_filterable`.
- **Cechy własne łowiska** — odrzucone co do zasady, nie odłożone: słownik definiowany przez
  operatorów przestałby być wspólny, a filtr przez wszystkie łowiska straciłby sens. Z tego powodu
  w słowniku **nie powstaje nawet kolumna przygotowawcza** — pusta furtka do czegoś, czego świadomie
  nie chcemy, z czasem zostanie użyta.
- **Mapa klikalna z pozycjonowaniem** — mapa jako obrazek zostaje bez zmian.
- **Pozwolenia długookresowe** — bez zmian; sprawdzamy wyłącznie, że powiązania przetrwały migrację.
- **`fisheries.positions_count`** — pole wpisywane ręcznie, niepowiązane z liczbą rekordów. Zostaje.
- **Mechanizm tłumaczenia treści operatora** — patrz „Rozstrzygnięcia".

## Zmiany dokumentacji

- [x] `docs/conventions/panel-wlasciciela.md` — stan stanowiska zamiast przełącznika; grupy jako
      etykiety w relacji wiele-do-wielu; akcja zbiorcza jako sposób ustawiania cech hurtem
- [x] `docs/conventions/panel-admina.md` — słownik cech stanowisk, typy cech i ich opcje
- [x] `README.md` — bez zmian
- [x] `CLAUDE.md` — bez zmian; niezmienniki powierzchni idą do `docs/conventions/`
- [x] `CHANGELOG.md` — wpis w changelogu

## Ograniczenia techniczne

- Laravel 13, Filament 5, PHP 8.4 — stan po zadaniu 009.
- **Osobny plik migracji na każdą tabelę**: `position_groups`, `group_position`,
  `position_attributes`, `position_attribute_options`, `position_attribute_values` oraz zmiany
  w `positions`.
- Kolejność migracji: `position_groups` przed tabelą pośrednią; `position_attributes` przed opcjami
  i przed wartościami.
- **Zadanie idzie po 015**, które wprowadza katalog `app/Enums/` oraz pojęcie doby. Jedyna
  przewidywana kolizja plików to `FisheryResource`, do którego oba zadania dokładają zakładkę.
- Tabela `positions` jest powiązana z `long_term_permits` i `additional_services` przez tabele
  pośrednie. Zmiana nie może naruszyć tych powiązań.
- Nazwy tabel, kolumn, klas i tras po angielsku; dokumentacja po polsku.
- Testy biegną na MySQL-u w schemacie `lowiska_test`.

## Rozstrzygnięcia

- **Treści wprowadzane przez właściciela pozostają jednojęzyczne — pojedyncza kolumna tekstowa, bez
  mechanizmu tłumaczenia.** Dotyczy opisu grupy, opisu stanowiska i wszystkich pól opisowych.
  Uzasadnienie i warunek utrzymania: decyzja **D8** w
  [Decyzje i TODO biznesowe](../../project/DECYZJE-I-TODO-BIZNESOWE.md), rozdział 6. Warunek w skrócie:
  jedna kolumna na jedno pojęcie, nazwana bez sufiksu językowego.

- **Grupa jest etykietą w relacji wiele-do-wielu, nie poziomem hierarchii.** Nie niesie cech, dojazdu
  ani stanu. Konsekwencją jest brak dziedziczenia, brak reguły „co wygrywa" i identyczne zachowanie
  łowiska, które grup nie używa. Zastępuje wycofany ADR-007 o strefie jako poziomie
  pośrednim.

- **Wygodę ustawiania cech hurtem daje akcja zbiorcza, nie dziedziczenie.** Akcja zapisuje wartość
  wprost na każdym stanowisku, więc kosztuje zero w schemacie i zostawia jedno źródło prawdy.

- **Cecha odpowiada za znalezienie, opis za zrozumienie.** Cecha jest wspólna dla portalu i po niej
  się filtruje; opis grupy i stanowiska jest własny i niesie to, czego flaga nie uniesie. Operator,
  który robi jedno i drugie, nie dubluje pracy.

- **Dom logiki wyliczanej to `app/Services/`** — zgodnie z regułą „logika obliczeniowa ma jeden dom"
  z `CLAUDE.md`. Po usunięciu dziedziczenia zostaje z tego mniej niż pierwotnie, ale zasada wiąże
  dalej: panel, przyszły portal wędkarza i sprzedaż offline wołają to samo.

- **Tier T2 utrzymany mimo trzech wyzwalaczy T3.** Zadanie trafia w migracje, polityki wraz
  z uprawnieniami Shielda oraz `OwnerPanelProvider`, którego rozdział 14.2 wymagań nie przewidywał.
  Odstępstwo zostaje, bo filtr T2 obejmuje `AdminPanelTest` i `OwnerPanelTest` — dokładnie to, co
  chroni zmianę providera.

- **`PositionGroupResource` żyje w obu panelach, `PositionAttributeResource` wyłącznie w panelu
  administratora.** Grupa jest konfiguracją pojedynczego łowiska, ale administrator ma mieć w nią
  wgląd na potrzeby wdrożenia; słownik cech jest wspólny dla portalu, więc właściciel go nie edytuje.

- **Model nazywa się `PositionGroup`** (tabela `position_groups`, pivot `group_position` ustawiony
  jawnie). Samo `Group` czytałoby się dwuznacznie w projekcie z rolami i uprawnieniami Shielda,
  a zadanie 016 wprowadza `selection_kind = group` — dwuznaczność by się utrwaliła.

- **Akcja zbiorcza zostawia N wpisów w dzienniku, po jednym na stanowisko.** Bez identyfikatora
  partii i bez wpisu zbiorczego. Wpis opisuje zmianę atrybutów **jednego rekordu** i ten niezmiennik
  z [`dziennik-zmian.md`](../../conventions/dziennik-zmian.md) zostaje nietknięty; pytanie zadawane
  najczęściej brzmi „co się działo z TYM stanowiskiem", a na nie odpowiada tylko wpis per rekord.
  Zwijanie historii w jedno zdarzenie można dołożyć później, gdy okaże się potrzebne — dokładanie
  pola teraz byłoby projektowaniem pod mechanizm, którego nikt nie widział w użyciu.

- **`position_groups.fishery_id` jest wymagany i kasuje się kaskadowo** — tak jak `sale_periods`
  z zadania 015, a inaczej niż `positions`. Różnica nie jest przypadkowa: stanowisko po odcięciu od
  łowiska wciąż wisi w tabelach pośrednich pozwoleń i usług, więc coś znaczy; grupa po odcięciu nie
  znaczy nic, a jej osierocony wiersz przepuszczałby regułę „grupa i stanowisko z tego samego
  łowiska" dla pary dwóch sierot.

- **Reguła „grupa i stanowisko należą do tego samego łowiska" stoi na dwóch warstwach**, zgodnie
  z [`autoryzacja.md`](../../conventions/autoryzacja.md) §4: zawężenie listy grup w formularzu przez
  `Helper::scopeToOwnedFisheries()` **oraz** sprawdzenie przy zapisie. Samo zawężenie opcji nie
  wystarcza — wybór wielokrotny jest stanem komponentu, czyli danymi od klienta.

- **Grupa jako etykieta w relacji wiele-do-wielu NIE dostaje ADR-a.** Rozstrzygnięcie wyżej wystarcza:
  po przebudowie nie ma tu reguły rozstrzygania ani dziedziczenia, a odwrócenie w stronę hierarchii
  byłoby **dołożeniem** struktury obok istniejącej (wartości per stanowisko zostają wiarygodne),
  nie migracją z rozplątywaniem. Ślad po wycofanym ADR-007 zostaje w sekcji „Powiązane ADR-y".

## Powiązane ADR-y

Trzy ADR-y powstałe przy pierwszym przeglądzie tego zadania (007, 008, 009) **zostały wycofane
i usunięte z repozytorium** po przebudowie modelu. Żaden z nich nie wiąże już implementacji. Dla
zachowania śladu, co i dlaczego upadło:

- **ADR-007 — strefa jako poziom pośredni.** Poziom pośredni nie powstaje; grupa jest etykietą
  w relacji wiele-do-wielu. Zawiodła przesłanka, że cechy są zwykle wspólne w obrębie fragmentu
  łowiska. Przetrwało jedno zastrzeżenie, obowiązujące dalej: **grupa nie jest jednostką sprzedaży**.
- **ADR-008 — przechowywanie wartości cech.** Łączył dwa pytania. Pytanie o dwóch właścicieli
  rozpłynęło się wraz z cechami grupy. Pytanie o trzy kolumny typowane kontra jedna tekstowa
  **zostaje w mocy** i wraca jako otwarte pytanie niżej.
- **ADR-009 — stan zależny od czasu wyliczany przy odczycie.** Zniknął jego przedmiot: nie ma kolumny
  trzymającej stan zależny od czasu, więc nie ma czego uspójniać ani zadania cyklicznego, które
  mogłoby zawieść. Zadania 016–018 **nie dziedziczą z niego niczego**.

**Wiążące dla tego zadania:**

- [ADR-011 — Kształt przechowywania wartości cech stanowiska](../../adr/ADR-011-ksztalt-wartosci-cech-stanowiska.md)
  — **Decyzja do wypełnienia przez autora.** Niesie ten jeden wątek wycofanego ADR-008, który
  przetrwał przebudowę: trzy kolumny typowane kontra jedna tekstowa.
- [ADR-010 — Doba wędkarska jako przedział czasu](../../adr/ADR-010-doba-wedkarska-jako-przedzial-czasu.md),
  założony przy zadaniu 015. To zadanie **korzysta** z jego reguł granic, nie ustala własnych.
- [ADR-006, aktualizacja z zadania 015](../../adr/ADR-006-natywne-komponenty-filamenta-zamiast-recznych-przeplywow.md)
  — granica między dwoma rodzajami zakładki huba. Grupy są **listą rekordów**, więc idą
  RelationManagerem, a nie stroną ustawień.

## Otwarte pytania dla `/review-task` — zamknięte

1. **Czy grupa jako etykieta wymaga własnego ADR-a** → **nie**, rozstrzygnięcie w treści zadania.
   Kryterium przewraca się na **koszcie odwrócenia**: powrót do hierarchii z dziedziczeniem jest
   dołożeniem struktury obok istniejącej, a nie migracją z rozplątywaniem danych — wartości zapisane
   per stanowisko zostają wiarygodne niezależnie od tego, czy grupa kiedyś zacznie coś dziedziczyć.
2. **Czy kształt wartości cech nadal wymaga ADR-a** → **tak**,
   [ADR-011](../../adr/ADR-011-ksztalt-wartosci-cech-stanowiska.md). Zmniejszenie skali (jeden właściciel
   zamiast dwóch) nie ruszyło żadnego z trzech warunków: zasięg obejmuje przyszły filtr przez
   wszystkie łowiska, koszt odwrócenia to migracja z parsowaniem tekstu, a pytanie „dlaczego trzy
   kolumny, skoro jedna jest prostsza" zadaje się co roku.

Przy przeglądzie doszły trzy rozstrzygnięcia punktowe: nazwa modelu (`PositionGroup`), ziarnistość
dziennika przy akcji zbiorczej (N wpisów) i wymagalność klucza obcego grupy (kaskada).
