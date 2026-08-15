# Konwencje: autoryzacja

Obowiązuje przy zmianach w `app/Policies/**`, rolach i uprawnieniach Shielda, `User`.

Zadania źródłowe: 008, 009.

---

## 1. Uprawnienia super admina

- **Rola super admina (`config('filament-shield.super_admin.name')`) musi mieć fizycznie
  przypisane uprawnienia** — `config/filament-shield.php` ma `super_admin.define_via_gate = false`,
  więc Shield **nie** rejestruje `Gate::before()` przepuszczającego wszystko. Pusta rola daje
  administratorowi zalogowanie bez dostępu do czegokolwiek: żadnej pozycji nawigacji, żadnego
  zasobu. Ten model jest świadomym wyborem, nie domyślnym zachowaniem Shielda — uzasadnienie
  odrzucenia `define_via_gate = true` w treści zadania 008.
- ⚠️ **Uprawnienia trzeba najpierw wygenerować, dopiero potem przypisać.** `shield:generate`
  nigdzie w tym projekcie nie uruchamia się samo (nie ma go w seederze ani w pipeline'u
  wdrożeniowym) — na świeżej bazie `Permission::all()` zwraca pustkę albo tylko uprawnienia
  nadane ręcznie gdzie indziej (`Helper::addOwnerRole()`). `php artisan MakeAdmin` woła
  `shield:generate` dla obu paneli (`--option=permissions --silent`, bez nadpisywania istniejących
  polityk) **przed** synchronizacją roli — nie wracaj do samego `syncPermissions(Permission::all())`
  bez tego kroku.
- **Nazwa roli super admina ma jedno źródło prawdy: `config('filament-shield.super_admin.name')`.**
  Zarówno `MakeAdminCommand`, jak i `tests/TestCase.php::createSuperAdmin()` czytają tę samą
  wartość. Przed zadaniem 008 testy tworzyły osobną rolę `'Super Admin'` (literał), różną od
  produkcyjnej `'super_admin'` z konfiguracji — dwie różne role o zbliżonej nazwie, więc testy nie
  pokrywały tego, co robiła produkcja. Nie wprowadzaj drugiego miejsca z nazwą roli na sztywno.
- `MakeAdmin` jest **idempotentne**: uruchomione na koncie/roli z kompletem uprawnień nie zmienia
  stanu i informuje o tym wprost; uruchomione na roli niepełnej (dzisiejszy stan produkcyjny przed
  zadaniem 008) uzupełnia braki bez tworzenia duplikatów.

---

## 2. Nazwy uprawnień — jedno źródło formatu

- **Format klucza uprawnienia ustala WYŁĄCZNIE `config/filament-shield.php` → `permissions`**
  (`separator: ':'`, `case: 'lower_snake'`), a generator Shielda produkuje z niego
  `view_any:additional_service`, `update:fishery_type`, `delete_any:company`. Nie dopisuj nazw
  uprawnień „z głowy" w nowym kodzie — sprawdź, co realnie generuje `shield:generate`.
- ⚠️ **Polityki muszą pytać dokładnie o te nazwy, które generator tworzy.** Rozjazd nie wywala
  aplikacji ani nie loguje błędu — po prostu **odbiera dostęp do wszystkiego**, bo `$user->can()`
  pyta o uprawnienie, którego nie ma w bazie. To awaria cicha, więc pilnuje jej osobny test:
  [`tests/Feature/ShieldPermissionNamesTest.php`](../../tests/Feature/ShieldPermissionNamesTest.php)
  porównuje literały z `app/Policies/**` z faktycznym wynikiem `shield:generate`.
- ⚠️ **Zwykły pakiet testów tego rozjazdu NIE wykryje** — `tests/TestCase.php::createSuperAdmin()`
  zakłada uprawnienia ręcznie, z własnej listy literałów, więc panele świecą na zielono nawet przy
  całkowicie błędnym formacie. Zmieniając `permissions.separator`/`case`, zmieniasz **naraz**:
  konfigurację, wszystkie polityki, `Helper::addOwnerRole()` i listy w `tests/TestCase.php`.
- Format zmienił się przy Shieldzie 4 (`view_any_fishery::type` → `view_any:fishery_type`) i
  **nie da się odtworzyć zapisu z 3.x** — separator `_` jest zabroniony przy case'ach snake.
  Szczegóły w zadaniu 009.

---

## 3. Polityki pisze człowiek, nie generator

- **`config/filament-shield.php` → `policies.generate` musi zostać `false`.** Czternaście polityk
  w `app/Policies/**` niesie logikę widoczności danych właściciela (`forCurrentUser()`), której
  generator Shielda nie zna — włączony nadpisałby je zaślepkami. Shield ma tu tworzyć **wyłącznie
  uprawnienia**; stąd `--option=permissions` w `MakeAdminCommand`.
- **`discovery.discover_all_*` jest włączone**, bo aplikacja ma dwa panele. Odkrywanie ograniczone
  do panelu domyślnego pomija stronę `VerifyCompany` panelu właściciela — czyli częściowo odtwarza
  objaw naprawiany zadaniem 008. Uprawnienie nieprzypisane do żadnej roli jest bezczynne, więc
  nadmiar nic nie kosztuje.
