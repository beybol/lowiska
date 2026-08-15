# 008 — `MakeAdmin` nadaje uprawnienia Shielda, nie tylko flagę `is_admin`

## Opis problemu

`php artisan MakeAdmin <imię> <nazwisko> <e-mail>` tworzy konto z `is_admin = true` i przypisuje mu
rolę `super_admin`, ale **ta rola nie ma żadnych uprawnień**. Efekt: użytkownik loguje się do
`/admin` i **nie widzi żadnej pozycji nawigacji ani żadnego zasobu** — panel wygląda na pusty,
mimo że logowanie się udało. Awaria jest cicha: komenda kończy się komunikatem „Admin created.",
niczego nie sygnalizując.

**Zweryfikowane empirycznie w bazie roboczej (2026-08-15):**

```
role: super_admin (uprawnień: 0) | owner (uprawnień: 24)
uprawnień w bazie razem: 24
```

Wszystkie 24 uprawnienia pochodzą z `Helper::addOwnerRole()` (dotyczą wyłącznie `company`
i `fishery`). Dla pozostałych jedenastu zasobów panelu administratora — `Fish`, `Convenience`,
`Country`, `State`, `FisheryType`, `FishingMethod`, `Currency`, `LongTermPermit`,
`AdditionalService`, `Position`, `User` — **uprawnienia w ogóle nie istnieją w bazie**.

**Dlaczego pusta rola nie wystarcza.** `config/filament-shield.php` ma
`'super_admin.define_via_gate' => false`, więc Shield **nie** rejestruje `Gate::before()`
przepuszczającego wszystko dla super admina. Rola musi mieć fizycznie przypisane uprawnienia,
żeby cokolwiek było widoczne. Przy `define_via_gate => true` pusta rola by wystarczyła — to
alternatywa rozważona i odrzucona, patrz `## Rozstrzygnięcia`.

**Skąd biorą się uprawnienia.** Wyłącznie z komendy `shield:generate`, która skanuje zasoby, strony
i widgety panelu i tworzy dla nich wpisy. W tym repozytorium **nikt jej nigdy nie uruchomił**: nie
ma jej ani w `DatabaseSeeder`, ani w `deploy.yml`, ani w żadnym runbooku. Świeżo postawiony system
(`migrate --seed` + `MakeAdmin`) daje więc administratora bez dostępu do czegokolwiek.

⚠️ **Samo `syncPermissions(Permission::all())` problemu nie rozwiąże** — na świeżej bazie
`Permission::all()` zwraca pustą kolekcję (albo, jak dziś, wyłącznie 24 uprawnienia `owner`).
Uprawnienia trzeba najpierw **wygenerować**, dopiero potem przypisać.

## Wymagania

- **`MakeAdmin` generuje brakujące uprawnienia**, zanim je przypisze — przez
  **`Artisan::call('shield:generate', …)`** (patrz „Rozstrzygnięcia"), dla **obu paneli**,
  z opcją generatora ograniczoną do samych uprawnień: polityki już istnieją (czternaście klas
  w `app/Policies/`) i **nie mają być nadpisywane** (`--option=permissions` albo
  `--ignore-existing-policies`). Wyjście komendy wyciszone (`--silent`/`--minimal`), żeby nie
  zagłuszało własnych komunikatów `MakeAdmin`.
- **Rola super admina dostaje komplet uprawnień** po wygenerowaniu — `syncPermissions()` na
  pełnej liście, nie dopisywanie pojedynczych wpisów.
- **Idempotencja jest wymaganiem, nie efektem ubocznym.** Komenda uruchomiona na istniejącym
  użytkowniku ma **zweryfikować** stan i uzupełnić braki:
  - użytkownik istnieje, ma rolę, rola ma komplet uprawnień → nic nie zmienia, informuje, że stan
    jest poprawny;
  - użytkownik istnieje, ale rola jest niepełna (dzisiejszy stan produkcyjny) → uzupełnia
    uprawnienia;
  - użytkownik nie istnieje → tworzy konto i nadaje komplet.
- **Komunikaty mają mówić, co się faktycznie stało** — ile uprawnień wygenerowano, ile przypisano,
  czy rola była już kompletna. Dzisiejsze „Admin created." jest prawdziwe i jednocześnie mylące,
  bo nie wspomina, że konto nie ma dostępu do niczego.
- **Ujednolicić nazwę roli między testami a produkcją.** `tests/TestCase.php::createSuperAdmin()`
  tworzy rolę **`'Super Admin'`**, podczas gdy komenda i konfiguracja używają **`'super_admin'`**.
  To dwie różne role — testy nie pokrywają dziś tego, co robi produkcja. Testy mają korzystać
  z `config('filament-shield.super_admin.name')`, tak jak komenda.
- **Test pokrywający komendę**, obejmujący co najmniej: świeży system (zero uprawnień w bazie →
  admin z kompletem), promocja istniejącego użytkownika, oraz ponowne uruchomienie na komplecie
  (brak zmian, brak duplikatów).

## Kryteria akceptacji

- [x] Na **czystej bazie** komenda daje konto z kompletem uprawnień — zweryfikowane liczbą
      uprawnień roli (> 0 i równą liczbie wygenerowanych), nie samym brakiem błędu.
      ⚠️ **Dowodem ma być test automatyczny**, nie `migrate:fresh` na bazie roboczej: pakiet
      biegnie na `lowiska_test` z `RefreshDatabase`, więc każdy test funkcjonalny **i tak**
      startuje na pustym schemacie — to jest naturalne, bezpieczne środowisko dla tego przypadku.
      Czyszczenie bazy roboczej jest objęte twardą zasadą z `CLAUDE.md` i nie jest tu potrzebne.
      Potwierdzone testem `fresh system: new admin gets the full set of Shield permissions`
      (`tests/Feature/MakeAdminCommandTest.php`) oraz ręcznie w bazie roboczej: `super_admin`
      poszło z 0 → 163 uprawnień.
- [x] Ponowne uruchomienie tej samej komendy nie tworzy duplikatów uprawnień ani nie zmienia
      stanu — komunikat informuje, że rola jest już kompletna.
      Potwierdzone testem `re-running on an already-complete role is idempotent`.
- [x] Uruchomienie na **istniejącym** użytkowniku z niepełną rolą (dzisiejszy stan: 0 uprawnień)
      uzupełnia je do kompletu.
      Potwierdzone testem `running against an incomplete role completes it, without touching
      existing permissions`.
- [x] Nazwa roli super admina pochodzi z `config('filament-shield.super_admin.name')` **zarówno**
      w komendzie, jak i w `tests/TestCase.php` — jedno źródło prawdy.
      `tests/TestCase.php::createSuperAdmin()` tworzyło wcześniej osobną rolę `'Super Admin'`
      (literał) — zamienione na wywołanie configu.
- [x] Test komendy istnieje i przechodzi (co najmniej trzy przypadki z „Wymagań").
      `tests/Feature/MakeAdminCommandTest.php` — 4 przypadki, 16 asercji.
- [x] Rola `owner` i uprawnienia nadawane przez `Helper::addOwnerRole()` **nie zmieniają się** —
      to zadanie nie dotyka panelu właściciela.
      Zweryfikowane ręcznie w bazie roboczej: rola `owner` pozostała przy 24 uprawnieniach.
- [x] Zakres testów zadeklarowany niżej (T3, pełny pakiet) jest zielony.
      68/68 testów, 237 asercji.

## Zakres testów

- **Tier:** T3 — pełny pakiet
- **Uruchamiamy:**
  ```bash
  docker compose exec app php artisan test
  ```
- **Uzasadnienie:** T3 **nie jest tu przedmiotem wyboru** — zadanie trafia w **dwie** pozycje
  z listy obowiązkowych wyzwalaczy w `CLAUDE.md` naraz: „`User`, role i uprawnienia Shielda"
  oraz `tests/TestCase.php` (ujednolicenie nazwy roli). Dodatkowo `createSuperAdmin()` jest
  używane przez `AdminPanelTest`, `OwnerPanelTest`, `CountryImporterTest` i
  `FisheryFileUploadTest` — zmiana nazwy roli dotyka więc czterech niezależnych plików
  testowych, a błąd w niej nie ujawni się w żadnym pojedynczym filtrze.

## Zakres wyłączeń

- **Zmiana `define_via_gate` na `true`** — rozważona i odrzucona, patrz `## Rozstrzygnięcia`.
- **Dopisanie kroku do `.github/workflows/deploy.yml`** (automatyczne `shield:generate` albo
  `MakeAdmin` po wdrożeniu) — **poza zakresem, ale odnotowane jako realna luka**: świeżo wdrożone
  środowisko Cloud Run ma dziś dokładnie ten sam problem, a pipeline nie uruchamia żadnej z tych
  komend. To decyzja operacyjna (kto i kiedy zakłada pierwsze konto administratora na produkcji),
  z własnym ryzykiem, i zasługuje na osobne zadanie — zwłaszcza że `deploy.yml` dostał świeżo
  bramkę bezpieczeństwa w zadaniu 007.
- **Refaktor `Helper::addOwnerRole()`** ani zmiana uprawnień roli `owner` — mimo że ta metoda
  tworzy dziś uprawnienia „ręcznie", listą literałów, zamiast korzystać z generatora Shielda.
  To osobny dług o innym charakterze (panel właściciela, nie administratora).
- **Interfejs zarządzania rolami w panelu** (zasób Shielda) — istnieje i działa; to zadanie
  naprawia wyłącznie ścieżkę pierwszego uruchomienia.
- **Zmiana sposobu ustawiania hasła** przez `MakeAdmin` (dziś: hasło = adres e-mail) — osobny
  temat bezpieczeństwa, nie dotyczy uprawnień. ⚠️ Warto odnotować jako kandydata na własne
  zadanie.

## Zmiany dokumentacji

- [x] ~~`docs/conventions/panel-admina.md`~~ → **skorygowane na `docs/conventions/autoryzacja.md`**
      podczas `/implement-task`: `MakeAdminCommand.php` leży w `app/Console/Commands/`, nie
      w `app/Filament/Resources/**` ani w `AdminPanelProvider.php`, więc nie dotyka powierzchni
      panelu admina. Wg tabeli routingu w `CLAUDE.md` właściwa powierzchnia to „role i uprawnienia
      Shielda, `User`" → `docs/conventions/autoryzacja.md`. Plik nie istniał (oznaczony ⛏️
      w `CLAUDE.md`) — założony tym zadaniem, niezmiennik: skąd biorą się uprawnienia super admina,
      dlaczego pusta rola nie wystarcza przy `define_via_gate = false`, pułapka „samo
      `Permission::all()` na świeżej bazie zwraca pustkę", jedno źródło prawdy dla nazwy roli.
      Znacznik ⛏️ przy tym wierszu usunięty z `CLAUDE.md`.
- [x] `README.md` — sekcja „Uruchomienie": doprecyzowano, że `MakeAdmin` nadaje też uprawnienia
      i że ponowne uruchomienie jest bezpieczne.
- [x] `docs/operations/obraz-produkcyjny.md` — dodana sekcja 11: pierwsze konto administratora na
      środowisku wdrożonym, wzmianka że pipeline tego nie robi automatycznie (patrz „Zakres
      wyłączeń").
- [x] `CLAUDE.md` — bez zmian merytorycznych w treści workflow; jedyna zmiana to usunięcie
      znacznika ⛏️ przy `docs/conventions/autoryzacja.md` w tabeli routingu, teraz gdy plik istnieje.
- [x] `CHANGELOG.md` — wpis w sekcji „Poprawione": administrator zakładany komendą ma od razu
      dostęp do panelu.

## Ograniczenia techniczne

- Laravel 12, Filament 3.3, `bezhansalleh/filament-shield` ^3.3, `spatie/laravel-permission`;
  testy uruchamiane **wyłącznie** przez `docker compose exec app php artisan test`.
- `config/filament-shield.php` ma `super_admin.enabled = true`, `name = 'super_admin'`,
  `define_via_gate = false`, `intercept_gate = 'before'` — zadanie **nie zmienia** tej
  konfiguracji (patrz „Zakres wyłączeń").
- Panele są **dwa** (`admin`, `owner`); `shield:generate` przyjmuje `--panel`. Zasoby panelu
  administratora leżą w `app/Filament/Resources/**` (13 klas), panel właściciela ma własne strony
  w `app/Filament/Owner/Pages/**`. Rola `super_admin` dotyczy panelu administratora — zakres
  generowania trzeba ustalić świadomie, nie przypadkiem.
- Czternaście polityk w `app/Policies/**` **już istnieje** — `shield:generate` nie ma ich
  nadpisywać (opcja generatora ograniczona do uprawnień albo `--ignore-existing-policies`).
- ⚠️ Weryfikacja na czystej bazie wymaga `migrate:fresh` albo skasowania wolumenu — jedno i drugie
  jest objęte **twardą zasadą z `CLAUDE.md`** i wymaga wcześniejszej, wyraźnej zgody użytkownika.
  Alternatywa bez czyszczenia: test automatyczny na bazie testowej (`RefreshDatabase` i tak
  odtwarza schemat od zera dla każdego testu funkcjonalnego).

## Rozstrzygnięcia

- **Komenda generuje i przypisuje realne uprawnienia; `define_via_gate` zostaje `false`.**
  Rozważana była alternatywa: `define_via_gate => true`, przy której Shield rejestruje
  `Gate::before()` przepuszczający wszystko dla super admina, a pusta rola wystarcza (jedna linia
  w konfiguracji zamiast zmian w komendzie). Odrzucona z trzech powodów: (1) rola `super_admin`
  zostawałaby **pusta w panelu Shielda**, co jest mylące dla kogoś, kto ją tam ogląda;
  (2) nie dałoby się odebrać super adminowi **pojedynczego** uprawnienia, bo gate przepuszcza
  wszystko bezwarunkowo; (3) mechanizm rozjeżdżałby się z tym, jak w tym projekcie działa już
  rola `owner` (24 realne uprawnienia nadawane przez `Helper::addOwnerRole()`) — dwa różne modele
  uprawnień w jednej aplikacji są droższe w utrzymaniu niż jeden.
- **Rozjazd nazwy roli między testami (`'Super Admin'`) a produkcją (`'super_admin'`
  z konfiguracji) wchodzi do tego zadania**, nie do osobnego. Powód: bez tego nowe testy komendy
  przechodziłyby na roli, której produkcja nie używa — czyli dokładnie ta klasa luki, którą to
  zadanie ma zamknąć.
- **`deploy.yml` zostaje nietknięty.** Kto i kiedy zakłada pierwsze konto administratora na
  środowisku wdrożonym, to decyzja operacyjna z własnym ryzykiem; zadanie naprawia komendę, a nie
  moment jej wywołania.
- **Uprawnienia generowane dla obu paneli, cały komplet synchronizowany z rolą super admina.**
  Ustalone przy `/review-task` po weryfikacji w kodzie: panel `owner` rejestruje **te same klasy**
  `App\Filament\Resources` co panel `admin` (podzbiór — `Company`, `Fishery`, `LongTermPermit`,
  `AdditionalService`, `Position`), ale ma **własną stronę `VerifyCompany`**, której panel admina
  nie ma. Ponieważ super admin ma dostęp do `/owner` (zakłada to `OwnerPanelTest > Admin has
  access to owner panel`), zawężenie generowania do panelu `admin` odtworzyłoby częściowo ten sam
  objaw, który to zadanie naprawia. Uprawnienie nieprzypisane do żadnej roli jest bezczynne, więc
  nadmiar nic nie kosztuje — brak kosztuje niewidoczność.
- **Generowanie przez `Artisan::call('shield:generate', …)`, nie własną implementacją.** Shield
  sam decyduje, jakie prefiksy uprawnień tworzy dla zasobów, stron i widgetów
  (`config/filament-shield.php` → `permission_prefixes`); powielenie tej logiki rozjechałoby się
  przy pierwszej aktualizacji pakietu. Wyjście da się wyciszyć (`--silent`/`--minimal`), a test
  i tak weryfikuje **skutek** (liczbę uprawnień roli), nie komunikaty na konsoli.

⚠️ **Dopisek po pierwszym pushu (2026-08-15): to zadanie nie jest przyczyną awarii deploya.**
Pierwszy realny przebieg `security` z zadania 007 czerwienił się po tym pushu — zdiagnozowane
jako luka współdzielona przez zadania 005 i 007 (strażnik dysku uploadów budzi framework w jobie
CI bez `APP_ENV`), niezwiązana ani z wersją PHP, ani ze zmianami tego zadania (008 nie dotyka
`deploy.yml` ani `AppServiceProvider`). Naprawione i opisane w `docs/tasks/007-*.md`, sekcja
„Wyniki weryfikacji" → „Czego nie udało się potwierdzić empirycznie".

## Powiązane ADR-y

<!-- Numery ADR-ów podjętych dla tego zadania (uzupełnia /review-task).
     Tylko decyzje spełniające trzyskładnikowe kryterium z CLAUDE.md —
     reszta idzie do „Rozstrzygnięcia" powyżej. -->
- **Brak.** Trzy pytania otwarte przy `/create-task` zamknięto rozstrzygnięciami w treści.
  Żadne nie spełnia kompletu kryterium z `CLAUDE.md`: zakres generowania i sposób wywołania są
  odwracalne jedną flagą albo jedną linijką, i nie wiążą kodu poza `MakeAdminCommand`.
  ⚠️ Decyzja, która **byłaby** kandydatem na ADR — model uprawnień super admina (realne
  uprawnienia kontra `define_via_gate`) — została podjęta przez autora już na etapie
  `/create-task` i zapisana w „Rozstrzygnięciach" z pełnym uzasadnieniem odrzucenia alternatywy.
  Zapisanie jej tam zamiast w ADR-ze jest świadome: nie zmienia konfiguracji Shielda, tylko
  utrwala model **już obowiązujący** w tym projekcie (rola `owner` też ma realne uprawnienia),
  więc niczego nowego nie ustanawia.

## Otwarte pytania — zamknięte przy `/review-task` (2026-08-15)

- ~~**Zakres generowania uprawnień: oba panele czy wyłącznie `admin`?**~~ → **Oba panele.**
  Zweryfikowane w kodzie: zasoby `owner` są podzbiorem `admin`, ale `owner` ma własną stronę
  `VerifyCompany` — patrz „Rozstrzygnięcia".
- ~~**Czy nadawać komplet uprawnień, czy komplet bez panelu właściciela?**~~ → **Komplet** —
  ta sama odpowiedź co wyżej, oba pytania okazały się jednym.
- ~~**`Artisan::call()` czy powielić logikę `shield:generate`?**~~ → **`Artisan::call()`** —
  patrz „Rozstrzygnięcia".