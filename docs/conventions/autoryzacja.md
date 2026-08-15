# Konwencje: autoryzacja

Obowiązuje przy zmianach w `app/Policies/**`, rolach i uprawnieniach Shielda, `User`.

Zadania źródłowe: 008.

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
  `shield:generate` dla obu paneli (`--option=permissions`, bez nadpisywania istniejących polityk)
  **przed** synchronizacją roli — nie wracaj do samego `syncPermissions(Permission::all())` bez
  tego kroku.
- **Nazwa roli super admina ma jedno źródło prawdy: `config('filament-shield.super_admin.name')`.**
  Zarówno `MakeAdminCommand`, jak i `tests/TestCase.php::createSuperAdmin()` czytają tę samą
  wartość. Przed zadaniem 008 testy tworzyły osobną rolę `'Super Admin'` (literał), różną od
  produkcyjnej `'super_admin'` z konfiguracji — dwie różne role o zbliżonej nazwie, więc testy nie
  pokrywały tego, co robiła produkcja. Nie wprowadzaj drugiego miejsca z nazwą roli na sztywno.
- `MakeAdmin` jest **idempotentne**: uruchomione na koncie/roli z kompletem uprawnień nie zmienia
  stanu i informuje o tym wprost; uruchomione na roli niepełnej (dzisiejszy stan produkcyjny przed
  zadaniem 008) uzupełnia braki bez tworzenia duplikatów.
