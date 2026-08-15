# Konwencje: dziennik zmian (activity log)

Obowiązuje przy zmianach w modelach z traitem `LogsActivity`
(`Spatie\Activitylog\Models\Concerns\LogsActivity`) i w `config/activitylog.php`.

Zadania źródłowe: 010.

---

## 1. `getActivitylogOptions()` zostaje obowiązkowe, mimo że pakiet mówi inaczej

- **Każdy model z traitem `LogsActivity` musi implementować `getActivitylogOptions()`
  zwracające `LogOptions::defaults()->logOnly($this->fillable)`.** Od `spatie/laravel-activitylog`
  5.x ta metoda jest formalnie **opcjonalna** — bez niej pakiet i tak loguje zdarzenie
  (`created`/`updated`/`deleted`), tylko **bez śledzenia zmian atrybutów**.
- ⚠️ **Nie usuwaj tej metody „bo w v5 jest opcjonalna".** Skutek jest cichy: dziennik dalej
  zapisuje wiersze, tylko puste — `attribute_changes` zostaje `null`. Żaden test standardowy tego
  nie złapie, bo aplikacja nie rzuca błędu. Pilnuje tego wyłącznie
  [`tests/Feature/ActivityLoggingTest.php`](../../tests/Feature/ActivityLoggingTest.php),
  który sprawdza **treść** wpisu (`attribute_changes['old']`/`['attributes']`), nie sam fakt jego
  istnienia.
- **Przestrzenie nazw v5**: `Spatie\Activitylog\Models\Concerns\LogsActivity` (nie `Traits\…`)
  i `Spatie\Activitylog\Support\LogOptions` (nie bez `Support\`). Zmiana z v4 była mechaniczna,
  ale łatwa do przeoczenia przy dopisywaniu traita do nowego modelu przez kopiowanie starego kodu
  ze starej dokumentacji/przykładu.

## 2. Migracje dziennika nie czytają konfiguracji pakietu

- **Tabela dziennika to na sztywno `activity_log`, bez pośrednictwa
  `config('activitylog.table_name')`.** Klucze `table_name` i `database_connection` **nie
  istnieją** w `config/activitylog.php` od zadania 010 — v5 sam ich już nie czyta (własny model
  `Activity` ma `protected $table = 'activity_log'` zaszyte w kodzie pakietu), a stare migracje
  tego projektu zostały przepisane analogicznie (literał zamiast `config()`).
- ⚠️ **Nie publikuj migracji z pakietu** (`vendor:publish --tag=activitylog-migrations`). v5
  dostarcza jedną skonsolidowaną migrację zakładającą tabelę od zera — w tym projekcie tabela już
  istnieje z własną historią migracji; publikacja próbowałaby założyć ją drugi raz.
- **Transformacja danych między formatami schematu ma mieszkać w klasie w `app/`, nie w ciele
  migracji.** Wzorzec: [`app/Services/ActivityLogSchemaMigrator.php`](../../app/Services/ActivityLogSchemaMigrator.php),
  wołany z migracji, testowany bezpośrednio w
  [`tests/Unit/ActivityLogSchemaMigratorTest.php`](../../tests/Unit/ActivityLogSchemaMigratorTest.php).
  Powód: `RefreshDatabase` uruchamia migracje w `setUp()`, więc logiki zaszytej w ciele migracji
  nie da się przetestować na zasianych danych — test zawsze startuje po tym, jak migracja już
  przeszła.

## 3. Zakres logowania

- **`logOnly($this->fillable)` obejmuje wszystkie pola wypełnialne modelu**, bez wykluczeń —
  to świadomie zachowany zakres z czasów 4.x, nie nowa decyzja tego zadania. Obejmuje też pola
  z danymi osobowymi (np. `User.phone`).
  ⚠️ **Zawężenie zakresu (`config('activitylog.default_except_attributes')` albo
  `logExcept()` per model) to osobna decyzja produktowa, celowo poza tym zadaniem** — nie
  wprowadzaj jej przy okazji niepowiązanej zmiany w modelu.
