# 010 — Podniesienie `spatie/laravel-activitylog` do 5.x

## Opis problemu

Projekt stoi na `spatie/laravel-activitylog` **4.12.3**, podczas gdy dostępne jest **5.1.0**.
To jedyna bezpośrednia zależność Composera, która po zadaniu 009 została na starszym majorze —
`composer outdated --direct` pokazuje ją obok Pesta (już podniesionego) jako jedyną realną pozycję
do spłaty.

**Dlaczego nie weszło do zadania 009.** Podniesienie zostało tam podjęte i **świadomie wycofane**
po empirycznej weryfikacji: sama podmiana wersji wywraca aplikację na starcie
(`Trait "Spatie\Activitylog\Traits\LogsActivity" not found`), bo v5 przeniósł trait do innej
przestrzeni nazw. Trait jest używany w **trzynastu** modelach, a zmiana dotyka też schematu bazy
i pliku konfiguracyjnego — to migracja o własnym ryzyku, dotycząca **danych audytowych**, więc
doklejanie jej do upgrade'u frameworka byłoby mieszaniem dwóch niezależnych osi zmian.

⚠️ **Sprostowanie do notatki w zadaniu 009.** Zapisano tam, że v5 „usuwa trait `LogsActivity`" —
to wniosek z komunikatu błędu, nie z dokumentacji. W rzeczywistości trait **został przeniesiony**
(`Spatie\Activitylog\Traits\` → `Spatie\Activitylog\Models\Concerns\`), a nie usunięty. Zakres
pracy jest przez to mniejszy, niż sugerowała tamta notatka, ale wciąż nietrywialny — patrz niżej.

**Co realnie jest do zrobienia** (zweryfikowane w oficjalnym
[`UPGRADING.md`](https://github.com/spatie/laravel-activitylog/blob/main/UPGRADING.md)
oraz przez rozpoznanie w kodzie):

1. **Przestrzenie nazw w 13 modelach** — `AdditionalService`, `Company`, `Convenience`, `Country`,
   `Currency`, `Fish`, `Fishery`, `FisheryType`, `FishingMethod`, `LongTermPermit`, `Position`,
   `State`, `User`. Wszystkie mają identyczny wzorzec, więc zmiana jest mechaniczna:

   | v4 | v5 |
   |---|---|
   | `Spatie\Activitylog\Traits\LogsActivity` | `Spatie\Activitylog\Models\Concerns\LogsActivity` |
   | `Spatie\Activitylog\LogOptions` | `Spatie\Activitylog\Support\LogOptions` |

2. **Migracja schematu i danych** — v5 dokłada kolumnę `attribute_changes`, usuwa `batch_uuid`
   (system wsadów wycofany) i przenosi treść z `properties` do nowej kolumny. W repozytorium są
   trzy migracje dziennika, w tym `2025_08_01_092226_add_batch_uuid_column_to_activity_log_table.php`,
   której efekt v5 usuwa.

3. **Przepisanie `config/activitylog.php`** — klucze zmieniły nazwy albo zniknęły:

   | v4 | v5 |
   |---|---|
   | `delete_records_older_than_days` | `clean_after_days` |
   | `subject_returns_soft_deleted_models` | `include_soft_deleted_subjects` |
   | `table_name`, `database_connection` | **usunięte** — własny model `Activity` zamiast nich |
   | — | nowe: `actions.log_activity`, `actions.clean_log`, `default_except_attributes` |

**Czego w tym projekcie NIE ma, więc nie boli** — zweryfikowane grepem po `app/`, `routes/`,
`resources/`, `database/`, `tests/`: **zero** użyć `activities()`, `actions()`, `CauserResolver`,
`LogBatch`, `activity()`, `tapActivity()`, `withProperties()`, `getExtraProperty()`. Cała
lista przemianowanych i usuniętych metod z przewodnika **omija ten kod**. Zmienne
`ACTIVITY_LOGGER_TABLE_NAME` i `ACTIVITY_LOGGER_DB_CONNECTION` nie są ustawione w `.env`, więc
usunięcie tych kluczy z konfiguracji też jest bezbolesne.

⚠️ **Najpoważniejsze ryzyko jest ciche, nie głośne.** W v4 model **musiał** implementować
`getActivitylogOptions()`. W v5 metoda jest **opcjonalna**, a domyślne zachowanie loguje samo
zdarzenie (`created`/`updated`/`deleted`) **bez śledzenia zmian atrybutów**. Wszystkie trzynaście
modeli w tym projekcie jawnie robi `LogOptions::defaults()->logOnly($this->fillable)`, czyli
**polega** na śledzeniu zmian. Skasowanie albo błędne przeniesienie tej metody nie wywoła żadnego
błędu — dziennik po prostu zacznie zapisywać puste wpisy. **Dziennik zmian nie ma dziś ani jednego
testu**, więc dziś nic by tego nie wykryło.

## Wymagania

- **`spatie/laravel-activitylog` podniesiony do `^5.0`** w `composer.json`, aplikacja wstaje.
- **Przestrzenie nazw poprawione w trzynastu modelach** (tabela wyżej). Zmiana jest mechaniczna —
  po niej `grep -rn "Activitylog\\\\Traits\|Activitylog\\\\LogOptions" app/` ma nie zwracać nic.
- **`getActivitylogOptions()` zostaje we wszystkich trzynastu modelach**, bo wszystkie polegają na
  `logOnly($this->fillable)`. **Nie kasować** tej metody „bo w v5 jest opcjonalna" — domyślne
  zachowanie v5 nie śledzi zmian atrybutów i cicho wydrążyłoby dziennik.
- **Migracja schematu**: dodać `attribute_changes`, usunąć `batch_uuid`.
  ⚠️ **Nie publikować migracji z pakietu** (`vendor:publish`). v5 dostarcza jedną skonsolidowaną
  migrację tworzącą tabelę od zera — w tym projekcie tabela `activity_log` już istnieje wraz
  z trzema własnymi migracjami, więc obowiązuje **własna migracja różnicowa**.
- **Migracja danych historycznych**: przenieść `properties.attributes` i `properties.old`
  do `attribute_changes` wg SQL z oficjalnego przewodnika. W bazie roboczej jest dziś
  **48 wpisów** — migracja ma je zachować czytelnymi, nie wyzerować.
- **Samo przekształcenie danych ma mieszkać w klasie w `app/`, nie w ciele migracji** — migracja
  ją tylko woła. Powód jest podwójny: (1) `RefreshDatabase` uruchamia migracje w `setUp()`, więc
  logiki zaszytej w migracji **nie da się przetestować** na zasianych wierszach; (2) `CLAUDE.md`
  („Konwencje kodu") wymaga, żeby logika obliczeniowa miała jeden dom poza kontrolerem/migracją.
  Patrz „Rozstrzygnięcia".
- **`config/activitylog.php` przepisany na schemat v5** — klucze przemianowane, usunięte usunięte,
  nowe dodane ze świadomie wybranymi wartościami (nie ślepa kopia stuba, jeśli projekt ma inne
  potrzeby niż domyślne).
- **Pokrycie testowe dziennika zmian**, którego dziś nie ma — patrz „Zakres testów". Minimum:
  zapis modelu tworzy wpis w dzienniku **z faktycznymi zmianami atrybutów**, nie sam wpis pusty.

## Kryteria akceptacji

- [x] `composer.json` wskazuje `spatie/laravel-activitylog: ^5.0` (zainstalowane 5.1.0),
      `composer.lock` zaktualizowany.
- [x] Aplikacja wstaje — `php artisan package:discover` bez błędu.
- [x] `grep -rn "Activitylog\\\\Traits\|Activitylog\\\\LogOptions" app/` nie zwraca nic —
      13 modeli przepisanych na `Activitylog\Models\Concerns\LogsActivity` i
      `Activitylog\Support\LogOptions`.
- [x] **Zapis modelu tworzy wpis z niepustymi zmianami atrybutów** —
      `tests/Feature/ActivityLoggingTest.php`, zweryfikowane też negatywnie: po usunięciu
      `getActivitylogOptions()` z modelu test czerwienieje z konkretnym asercyjnym komunikatem
      (`Failed asserting that null is identical to 'Stara nazwa'`), nie cichym przejściem.
- [x] **Przekształcenie danych ma test jednostkowy** —
      `tests/Unit/ActivityLogSchemaMigratorTest.php`, cztery przypadki (podział, brak kluczy,
      pusta tablica, same klucze zmian). Testowana klasa
      (`app/Services/ActivityLogSchemaMigrator.php`), nie migracja.
- [x] Schemat po migracji: `attribute_changes` istnieje, `batch_uuid` nie istnieje. Zweryfikowane
      też na bazie roboczej **z danymi** (53 wiersze, w tym 48 sprzed migracji) — wszystkie mają
      teraz wypełnione `attribute_changes`, zero utraty treści.
- [x] `config/activitylog.php` nie zawiera kluczy usuniętych w v5.
- [x] `composer audit --locked` — brak podatności.
- [x] `vendor/bin/phpstan analyse` — `[OK] No errors`, baseline nietknięty (nie było potrzeby
      dodawać nowych wpisów).
- [x] Zakres testów (T3, pełny pakiet) zielony: **76 testów, 256 asercji** (+5 względem stanu
      po zadaniu 009: 71/244).
- [x] `vendor/bin/pint` — czysto (201 plików; 13 poprawek `ordered_imports` w modelach po zmianie
      przestrzeni nazw, naprawione).

## Zakres testów

- **Tier:** T3 — pełny pakiet
- **Uruchamiamy:**
  ```bash
  docker compose exec app php artisan test
  ```
- **Uzasadnienie:** T3 **nie jest tu przedmiotem wyboru** — zadanie trafia w trzy pozycje
  z listy obowiązkowych wyzwalaczy w `CLAUDE.md` naraz: **migracje**, **`composer.json`**
  oraz **model `User`** (jeden z trzynastu modelów z traitem). Dodatkowo trait siedzi w modelach
  używanych przez praktycznie każdy test funkcjonalny, więc regresja w logowaniu ujawni się
  w dowolnym miejscu pakietu, nie w jednym filtrze.
- **Nowe pokrycie do napisania w tym zadaniu** (dziś **nie istnieje żadne**): test dziennika zmian
  sprawdzający, że zapis modelu tworzy wpis z **niepustymi** zmianami atrybutów. Bez tego migracja
  jest nieweryfikowalna automatycznie.

## Zakres wyłączeń

- **Zastąpienie `LogsActivity` nowym traitem `HasActivity`** (v5 łączy `LogsActivity`
  i `CausesActivity` w jeden) — projekt nie używa `CausesActivity`, więc zysk jest zerowy,
  a zmiana dotknęłaby trzynastu modeli bez powodu. Minimalna zmiana to sama przestrzeń nazw.
- **Zastępnik systemu wsadów (`LogBatch`)** — projekt nigdy go nie używał (zweryfikowane grepem),
  więc nie ma czego zastępować grupowaniem po `withProperty()`.
- **Rozszerzanie zakresu logowania** (nowe modele w dzienniku, `default_except_attributes`
  wykluczające pola wrażliwe, zmiana `clean_after_days`) — to decyzje produktowe o tym, **co**
  logujemy; to zadanie przenosi istniejące zachowanie na nową wersję, nie projektuje audytu
  od nowa. ⚠️ Warto odnotować jako kandydata na osobne zadanie, zwłaszcza
  `default_except_attributes` — dziś `logOnly($this->fillable)` obejmuje **wszystkie** pola
  wypełnialne, także takie jak `phone` czy dane firmy.
- **Interfejs przeglądania dziennika w panelu** — nie istnieje i to zadanie go nie tworzy.

## Zmiany dokumentacji

- [ ] **`docs/conventions/dziennik-zmian.md` — nowy plik** (ustalone przy `/review-task`).
      Niezmienniki do zapisania, co najmniej: **`getActivitylogOptions()` zostaje w modelach
      z traitem**, bo domyślne v5 loguje zdarzenie bez śledzenia zmian atrybutów — a ta awaria
      jest cicha (dziennik zapisuje puste wpisy, nic nie pęka); zakaz publikowania migracji
      pakietu na istniejącą tabelę; wskazanie testu pilnującego niepustych zmian.
- [ ] `CLAUDE.md` — **wiersz w tabeli „Konwencje powierzchni"** kierujący z modeli z traitem
      `LogsActivity` (i `config/activitylog.php`) do nowego pliku konwencji. Poza tym bez zmian —
      to nie jest reguła workflow.
- [ ] `CHANGELOG.md` — wpis z perspektywy użytkownika. ⚠️ Uwaga: jeśli historia sprzed migracji
      pozostanie częściowo nieczytelna, to jest **zmiana widoczna** i musi tam trafić.
- [ ] `README.md` — bez zmian (activitylog nie jest wspomniany).

## Ograniczenia techniczne

- **v5 wymaga PHP 8.4+ i Laravel 13+** — oba warunki spełnia stan po zadaniu 009
  (PHP 8.4.24, Laravel 13.25.0). To zadanie **jest zależne od 009** i nie da się go wykonać wcześniej.
- `config.platform.php` w `composer.json` to dziś `8.4.24`; jeśli v5 albo jego zależności wymagają
  nowszej łatki, obowiązuje niezmiennik z `CLAUDE.md` — pin podnosi się **dopiero** po
  zweryfikowaniu wersji PHP w **obu** obrazach (dev i prod), nigdy „w ciemno".
- Trzy istniejące migracje dziennika (`2025_08_01_0922*`) zostają w historii — **nie edytujemy
  migracji już wykonanych**; zmiany schematu idą nową migracją.
- Testy uruchamiane **wyłącznie** przez `docker compose exec app php artisan test` — pakiet biegnie
  na MySQL-u w schemacie `lowiska_test`, chronionym pięcioma warstwami izolacji (ADR-001).
- ⚠️ Weryfikacja migracji danych **nie może** iść przez `migrate:fresh` na bazie roboczej —
  twarda zasada z `CLAUDE.md`.
- ⚠️ **`RefreshDatabase` uruchamia migracje w `setUp()`, czyli PRZED ciałem testu** — nie da się
  więc zasiać wierszy „w formacie v4" tak, żeby zobaczyła je migracja. To jest powód, dla którego
  transformacja danych ma być osobną klasą wołaną z migracji: test sprawdza klasę wprost, na
  własnych danych, bez udawania przebiegu migracji.

## Rozstrzygnięcia

- **Dane historyczne dziennika zostają przemigrowane**, nie porzucone ani wyczyszczone. Ustalone
  przy `/create-task` (`AskUserQuestion`). Oficjalny przewodnik dostarcza SQL przenoszący
  `properties.attributes`/`old` do `attribute_changes`, a migracja schematu i tak musi powstać —
  dołożenie do niej przeniesienia danych jest tańsze niż utrata czytelności historii.
- **Pokrycie testowe dziennika zmian powstaje w tym zadaniu**, nie w osobnym. Ustalone przy
  `/create-task`. Powód: v5 domyślnie **nie** śledzi zmian atrybutów, więc błąd w migracji jest
  cichy — dziennik zapisuje puste wpisy zamiast przestać działać głośno. Bez testu kryterium
  akceptacji „logowanie działa" byłoby niesprawdzalne inaczej niż ręcznym oglądaniem tabeli.
- **Przekształcenie danych trafia do klasy w `app/`; migracja tylko ją woła.** Ustalone przy
  `/review-task` (`AskUserQuestion`) po wykryciu, że pierwotny zapis zadania („test zasieje
  wiersze przed migracją") jest **niewykonalny** — `RefreshDatabase` uruchamia migracje
  w `setUp()`, więc ciało testu zawsze zaczyna się po nich. Wydzielenie klasy daje test wołający
  transformację wprost, jest odporne na kolejność migracji i pokrywa się z regułą z `CLAUDE.md`,
  że logika ma jeden dom poza migracją. Odrzucono wariant „test cofa i ponawia migrację" jako
  kruchy (łatwo o test zielony bez sprawdzania czegokolwiek) oraz wariant bez testu — jedyny krok
  dotykający danych zostałby wtedy bez automatycznego dowodu.
- **Niezmienniki dziennika zmian idą do nowego `docs/conventions/dziennik-zmian.md`.** Ustalone
  przy `/review-task`. Powierzchnia jest realna i będzie wracać (zakres logowania, dane wrażliwe,
  czyszczenie starych wpisów), a doklejenie jej do `autoryzacja.md` mieszałoby dwie różne
  powierzchnie w jednym dokumencie. Plik wymaga wiersza w tabeli routingu w `CLAUDE.md`.

## Powiązane ADR-y

- **Brak.** Obie kwestie otwarte przy `/create-task` zamknięto rozstrzygnięciami w treści; żadna
  nie spełnia kompletu trzyskładnikowego kryterium z `CLAUDE.md`:
  - **Sposób weryfikacji migracji danych** (klasa transformująca kontra logika w migracji) —
    ma zasięg wykraczający poza zadanie i sensowne uzasadnienie, ale **koszt odwrócenia jest
    niski**: to przeniesienie jednej metody, bez migracji danych i bez łamania niezmiennika.
    Dodatkowo nie ustanawia nic nowego — powtarza regułę „logika ma jeden dom", którą `CLAUDE.md`
    już niesie.
  - **Nazwa i umiejscowienie pliku konwencji** — wprost wymienione w `CLAUDE.md` wśród
    anty-sygnałów („nazwa pola/trasy/kolumny", rzeczy odwracalne jedną linijką).

## Otwarte pytania

- ~~**Gdzie w `docs/conventions/` mieszka dziennik zmian?**~~ → **Nowy `dziennik-zmian.md`**
  plus wiersz w tabeli routingu `CLAUDE.md`. Zamknięte przy `/review-task`, patrz
  „Rozstrzygnięcia".
- **Czy `logOnly($this->fillable)` w trzynastu modelach to nadal właściwy zakres logowania?**
  **Celowo pozostawione otwarte** — wykracza poza to zadanie (patrz „Zakres wyłączeń"), ale v5
  wprowadza `default_except_attributes`, więc to naturalny moment, żeby pytanie postawić,
  zwłaszcza dla modeli z danymi osobowymi (`User.phone`, dane firmy). **Nie blokuje
  implementacji** i nie powinno być rozstrzygane na zgadywanie przy `/implement-task` —
  to materiał na osobne zadanie o zakresie audytu.
