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

---

## 4. Zasoby podrzędne łowiska — polityka NIE wystarcza

Stanowiska, usługi dodatkowe i pozwolenia należą do łowiska, a ich polityki **przy tworzeniu nie
widzą rekordu nadrzędnego** — `PositionPolicy::create()` dostaje sam typ i przepuszcza każdego
z rolą `owner`. Dlatego przynależność do łowiska pilnują trzy warstwy naraz i **żadnej nie wolno
zdejmować pojedynczo** (zadanie 012, sekcje 15 i 16):

1. **Zawężenie zapytania zasobu** — `Helper::scopeToOwnedFisheries($query)` w `getEloquentQuery()`.
   To jedyna warstwa działająca na **odczycie pojedynczego rekordu** (`resolveRecordRouteBinding()`
   na stronie edycji), więc bez niej da się wejść na cudzy rekord wprost z URL-a. Nowy zasób
   podrzędny wobec łowiska dostaje ją jedną linijką — **nie przeklejaj warunku**.
   ⚠️ To zawężenie **nie obejmuje zapytań o opcje w formularzu** (`Select`, `CheckboxList`).
   Te liczą się z `$get('fishery_id')`, czyli z pola `Hidden` — danych od klienta — i muszą
   przejść przez `scopeToOwnedFisheries()` osobno, inaczej podmiana stanu wyświetli nazwy
   z cudzego łowiska. Zapis pozostaje bezpieczny, ale to i tak wyciek odczytowy.
2. **Bramka na każdym żądaniu listy** — `Helper::assertFisheryAccessOrAbort($this->fisheryId)`
   w `getTableQuery()`, nie tylko w `mount()`. ⚠️ `$fisheryId` jest publiczną właściwością
   komponentu wiązaną z query stringiem, więc kolejne żądanie Livewire może przynieść inną
   wartość — albo `null`, przy którym warunkowy filtr nie dokładał **żadnego** ograniczenia.
3. **Bramka przy zapisie** — `Helper::forceVerifiedFishery($data)` w
   `mutateFormDataBeforeCreate()` **oraz** `mutateFormDataBeforeSave()`. ⚠️ Obie, nie jedna:
   `mount()` strony edycji sprawdza łowisko rekordu **sprzed** zmiany, a `fishery_id` jest
   w formularzu polem `Hidden`, więc bez tego dało się przenieść własny rekord pod cudze łowisko.

⚠️ **Bramka przy zapisie musi być bezstanowa.** Nie zapamiętuj zweryfikowanego ID we właściwości
strony: Livewire utrwala między żądaniami **wyłącznie właściwości publiczne**, a żądanie zapisu
leci na `/livewire/update` i nie niesie ani `?fishery`, ani niczego z `protected`. Pierwsza wersja
tej poprawki właśnie tak wyglądała i przerywała zapis błędem 404 — złapały to testy, nie przegląd.

⚠️ **Polityka bez sprawdzenia właściciela na rekordzie jest zerową warstwą, nie pierwszą.**
`Helper::addOwnerRole()` nadaje roli `owner` **pełny** zestaw `*:fishery` i `*:company`, więc
samo `$user->can('update:fishery')` zwraca `true` dla cudzego rekordu. Polityka zasobu należącego
do właściciela musi porównać `user_id` — wzorzec w `FisheryPolicy`/`CompanyPolicy`/`PositionPolicy`.
Administrator (`is_admin`) zostaje poza zawężeniem także wtedy, gdy ma dodatkowo rolę `owner`.

⚠️ **Nazwa cudzego rekordu też jest danymi.** Okruszki i tytuły stron `Create*` czytają `?fishery`
wprost z żądania, a Filament przelicza je przy **każdym** renderze Livewire — bramka z `mount()`
biegnie tylko przy pierwszym GET-cie. Dlatego `Helper::findFishery()` jest **domyślnie zawężone**,
a wariant nieograniczony trzeba wybrać świadomie. Nie odwracaj tej domyślności.

⚠️ **Ustawienie bezpieczeństwa dodane do jednego panelu trzeba dodać do drugiego.**
`TwoFactorMiddleware` żył w stosie `/admin` i nie żył w `/owner` przez dwa zadania i trzy tury
przeglądu kodu — wyłapał to dopiero audyt czytający oba providery obok siebie. Konfiguracja
per panel nie dziedziczy się sama; przy każdej zmianie w `AdminPanelProvider` sprawdź
`OwnerPanelProvider` i odwrotnie.

⚠️ **Testy tych warstw weryfikuj negatywnie** — zepsuj bramkę i sprawdź, że test czerwienieje.
Asercje typu „nie zawiera nazwy cudzego rekordu" łatwo przechodzą z niewłaściwego powodu: kolumny
opisowe mają `limit(20)`, więc losowa treść z fabryki i tak nie trafia do HTML-a w całości.
Wzorzec: `tests/Feature/OwnerPanelTest.php`, przypadki „Owner can not…".
