# TODO

Rzeczy odłożone świadomie — z powodem i momentem, w którym wracamy. To **nie jest** backlog zadań
(te żyją w [`docs/tasks/`](docs/tasks/)), tylko lista pozycji czekających na warunek zewnętrzny.

---

## Po upgrade do Laravel 13 + najnowszego Filamenta

### Drugi składnik uwierzytelniania w panelu `/owner`

**Stan dziś:** `TwoFactorMiddleware::class` jest w stosie `->middleware([...])` panelu `/admin`
([`AdminPanelProvider`](app/Providers/Filament/AdminPanelProvider.php)), ale **nie ma go w panelu
`/owner`** ([`OwnerPanelProvider`](app/Providers/Filament/OwnerPanelProvider.php)) — mimo że import
klasy jest w obu plikach.

**Dlaczego to odnotowujemy:** sesja jest wspólna (guard `web`), a testy `OwnerPanelTest` zakładają,
że administrator ma dostęp do panelu właściciela. Użytkownik z ustawionym, niezweryfikowanym
`two_factor_code` przechodzi więc bramkę na `/admin`, ale na `/owner` nie ma jej wcale.

**Dlaczego nie naprawiamy teraz:** po przejściu na Laravel 13 i najnowszego Filamenta MFA jest
częścią pakietu (`->multiFactorAuthentication(...)` konfigurowane per panel), więc własny
`TwoFactorMiddleware` zostanie zastąpiony rozwiązaniem bibliotecznym. Łatanie dzisiejszej
implementacji byłoby pracą do wyrzucenia.

⚠️ **Przy tym upgradzie zrób listę kontrolną obu paneli.** Dokładnie ten wzorzec — ustawienie
bezpieczeństwa dodane do jednego panelu i niedodane do drugiego — był drugim znaleziskiem audytu
bliźniaczego WorkSnapa (2026-08-15, obejście 2FA na `/admin`, waga High). Konfiguracja per panel
nie dziedziczy się sama.

**Źródło:** `WorkSnap/docs/security/2026-08-15-zatrucie-hosta-i-obejscie-2fa-na-admin.md`, sekcja 2.
Odnotowane przy `/review-task 002` (2026-08-15).

---

## `Role` bez własnej tablicy uprawnień w `createSuperAdmin()`

**Stan dziś:** [`tests/TestCase.php`](tests/TestCase.php) trzyma osobną tablicę uprawnień per
zasób (`$companyPermissions`, `$fisheryPermissions`, … `$currencyPermissions`), które
`createSuperAdmin()` tworzy przez `Permission::firstOrCreate()` przed przypisaniem ich do roli
super admina. `RolePolicy` sprawdza uprawnienia `view_any:role`, `view:role`, `create:role`,
`update:role`, `delete:role`, `delete_any:role` (zasób `Role` z `filament-shield`) — ale w
`TestCase.php` nie ma odpowiadającej im `$rolePermissions`, więc nic ich nie tworzy jawnie
przed testami.

**Dlaczego to odnotowujemy:** to dokładnie ten sam wzorzec, co brakujące `$currencyPermissions`
naprawione w [zadaniu 011](docs/tasks/implemented/011-redirect-po-utworzeniu-rekordu-na-liste.md)
— zasób ma politykę i (przez `filament-shield`) zasób administracyjny, ale nikt nie dopisał go do
listy w `createSuperAdmin()`. Tam problem ujawnił się dopiero przy pisaniu testu sprawdzającego
konkretne zachowanie zasobu; tu żaden istniejący test nie zarządza rolami jako super admin, więc
luka jest dziś niewidoczna. Zgłoszone przez użytkownika 2026-08-16 przy okazji przeglądu zadania
011.

**Dlaczego nie naprawiamy teraz:** nic dziś nie psuje — brak testu, który by to ujawnił, więc nie
ma czerwonego testu prowadzącego naprawę, a dodanie samej tablicy „na zapas" bez testu
wymuszającego jej użycie byłoby zgadywaniem, czy nazwy uprawnień (`role` vs `roles` — do
sprawdzenia względem `config/permission.php`) są aktualne.

**Warunek powrotu:** pierwszy test lub zadanie dotykające zarządzania rolami w panelu admina
(np. zasób `RoleResource` z `filament-shield`) jako super admin — wtedy dopisać
`$rolePermissions` do `createSuperAdmin()` tym samym wzorcem co pozostałe zasoby.
