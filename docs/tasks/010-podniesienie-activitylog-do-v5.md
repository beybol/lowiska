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
- **Migracja danych historycznych**: przenieść `properties.attributes` i `properties.old`
  do `attribute_changes` wg SQL z oficjalnego przewodnika (patrz „Rozstrzygnięcia"). W bazie
  roboczej jest dziś **48 wpisów** — migracja ma je zachować czytelnymi, nie wyzerować.
- **`config/activitylog.php` przepisany na schemat v5** — klucze przemianowane, usunięte usunięte,
  nowe dodane ze świadomie wybranymi wartościami (nie ślepa kopia stuba, jeśli projekt ma inne
  potrzeby niż domyślne).
- **Pokrycie testowe dziennika zmian**, którego dziś nie ma — patrz „Zakres testów". Minimum:
  zapis modelu tworzy wpis w dzienniku **z faktycznymi zmianami atrybutów**, nie sam wpis pusty.

## Kryteria akceptacji

- [ ] `composer.json` wskazuje `spatie/laravel-activitylog: ^5.0`, `composer.lock` zaktualizowany.
- [ ] Aplikacja wstaje (`php artisan package:discover` bez błędu) — to jest próg, na którym
      poległo podniesienie w zadaniu 009.
- [ ] `grep -rn "Activitylog\\\\Traits\|Activitylog\\\\LogOptions" app/` nie zwraca nic.
- [ ] **Zapis modelu tworzy wpis z niepustymi zmianami atrybutów** — zweryfikowane testem
      automatycznym, nie oglądaniem tabeli. To jedyne kryterium odróżniające „logowanie działa"
      od „tabela zapisuje puste wpisy, bo `getActivitylogOptions()` zniknęło".
- [ ] Migracja przechodzi na bazie **z danymi**: po migracji wpisy historyczne mają wypełnione
      `attribute_changes`, a `batch_uuid` nie istnieje.
      ⚠️ Dowodem ma być test albo migracja uruchomiona na kopii — **nie** `migrate:fresh` na bazie
      roboczej (twarda zasada z `CLAUDE.md`, wymaga osobnej zgody).
- [ ] `config/activitylog.php` nie zawiera kluczy usuniętych w v5 (`table_name`,
      `database_connection`, `delete_records_older_than_days`, `subject_returns_soft_deleted_models`).
- [ ] `composer audit --locked` — brak podatności.
- [ ] `vendor/bin/phpstan analyse` — zielono; baseline **nie rośnie** (nowe naruszenie oznacza
      realny błąd migracji, nie dług do zamrożenia — tak zachował się `Register::makeForm()`
      w zadaniu 009).
- [ ] Zakres testów zadeklarowany niżej (T3, pełny pakiet) jest zielony.
- [ ] `docker compose exec app vendor/bin/pint` — czysto.

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

- [ ] `docs/conventions/` — **powierzchnia bez pliku**: dziennik zmian nie należy ani do panelu
      admina, ani do właściciela, ani do autoryzacji. Jeśli zadanie ustali niezmiennik wiążący
      przyszły kod (a zapowiada się na to co najmniej jeden: „`getActivitylogOptions()` zostaje,
      bo domyślne v5 nie śledzi zmian"), założyć plik dla tej powierzchni i dopisać wiersz
      do tabeli routingu w `CLAUDE.md`. Nazwę pliku ustalić przy `/review-task`.
- [ ] `CLAUDE.md` — wiersz w tabeli „Konwencje powierzchni", jeśli powstanie nowy plik konwencji.
      Poza tym bez zmian (to nie jest reguła workflow).
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
  twarda zasada z `CLAUDE.md`. `RefreshDatabase` w pakiecie testowym odtwarza schemat od zera,
  więc test migracji danych musi sam zasiać wiersze „w formacie v4" przed jej uruchomieniem.

## Rozstrzygnięcia

- **Dane historyczne dziennika zostają przemigrowane**, nie porzucone ani wyczyszczone. Ustalone
  przy `/create-task` (`AskUserQuestion`). Oficjalny przewodnik dostarcza SQL przenoszący
  `properties.attributes`/`old` do `attribute_changes`, a migracja schematu i tak musi powstać —
  dołożenie do niej przeniesienia danych jest tańsze niż utrata czytelności historii.
- **Pokrycie testowe dziennika zmian powstaje w tym zadaniu**, nie w osobnym. Ustalone przy
  `/create-task`. Powód: v5 domyślnie **nie** śledzi zmian atrybutów, więc błąd w migracji jest
  cichy — dziennik zapisuje puste wpisy zamiast przestać działać głośno. Bez testu kryterium
  akceptacji „logowanie działa" byłoby niesprawdzalne inaczej niż ręcznym oglądaniem tabeli.

## Powiązane ADR-y

<!-- Numery ADR-ów podjętych dla tego zadania (uzupełnia /review-task).
     Tylko decyzje spełniające trzyskładnikowe kryterium z CLAUDE.md —
     reszta idzie do „Rozstrzygnięcia" powyżej. -->

## Otwarte pytania

- **Gdzie w `docs/conventions/` mieszka dziennik zmian?** Powierzchnia nie ma dziś pliku i nie
  pasuje do żadnego istniejącego (`panel-admina`, `panel-wlasciciela`, `autoryzacja`,
  `integracje`, `strona-publiczna`). Zadanie prawdopodobnie ustali co najmniej jeden niezmiennik
  wiążący przyszły kod, więc plik powinien powstać — do ustalenia przy `/review-task`, razem
  z nazwą i wierszem w tabeli routingu `CLAUDE.md`.
- **Czy `logOnly($this->fillable)` w trzynastu modelach to nadal właściwy zakres logowania?**
  Wykracza poza to zadanie (patrz „Zakres wyłączeń"), ale v5 wprowadza
  `default_except_attributes`, więc to naturalny moment, żeby pytanie postawić — zwłaszcza dla
  modeli z danymi osobowymi (`User.phone`, dane firmy). Odpowiedź nie blokuje implementacji.
