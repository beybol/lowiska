# Konwencje: autoryzacja

Obowiązuje przy zmianach w `app/Policies/**`, rolach i uprawnieniach Shielda, `User`.

Zadania źródłowe: 008, 009, 012, 013, 015, 021, 025; security-review 2026-09-20.

---

## 1. Uprawnienia super admina

- ⚠️ **`users.is_admin` jest JEDYNYM źródłem prawdy o super adminie**
  ([ADR-018](../adr/ADR-018-is-admin-jako-zrodlo-prawdy-o-super-adminie.md)). Rola `super_admin`
  Shielda jest jej **pochodną** — kierunek zależności flaga → rola, nigdy odwrotnie. Zgrywa je
  **`php artisan admins:sync`** (`AdminPermissionSync`, jedyny dom tej reguły): generuje
  uprawnienia Shielda dla obu paneli, daje roli **wszystkie**, przypisuje ją każdemu kontu
  z `is_admin` i **zdejmuje** z kont bez flagi. `deploy.yml` uruchamia ją przy **każdym**
  wdrożeniu, po migracjach i przed wdrożeniem usługi — nowy zasób jest widoczny dla administratora
  od pierwszego żądania. Brak kont z `is_admin` to ostrzeżenie z kodem 0, nie błąd.
  - ⚠️ **Ręczna zmiana uprawnień roli `super_admin` w panelu Shielda jest cofana** przy następnym
    wdrożeniu. Nie ogranicza się administratora przez Shielda; administrator o węższych prawach,
    gdy będzie potrzebny, to **osobna rola**, a nie ograniczone `is_admin`.
  - Nowej flagi „super admin" na kontach nie ma i mieć nie ma — dublowałaby `is_admin`.
- **Rola super admina (`config('filament-shield.super_admin.name')`) musi mieć fizycznie
  przypisane uprawnienia** — `config/filament-shield.php` ma `super_admin.define_via_gate = false`,
  więc Shield **nie** rejestruje `Gate::before()` przepuszczającego wszystko. Pusta rola daje
  administratorowi zalogowanie bez dostępu do czegokolwiek: żadnej pozycji nawigacji, żadnego
  zasobu. Ten model jest świadomym wyborem, nie domyślnym zachowaniem Shielda — uzasadnienie
  odrzucenia `define_via_gate = true` w treści zadania 008.
- ⚠️ **Uprawnienia trzeba najpierw wygenerować, dopiero potem przypisać.** `shield:generate`
  nigdzie w tym projekcie nie uruchamia się samo (nie ma go w seederze ani w pipeline'u
  wdrożeniowym) — na świeżej bazie `Permission::all()` zwraca pustkę albo tylko uprawnienia
  nadane ręcznie gdzie indziej (`OwnerRoleProvisioner::addOwnerRole()`). `AdminPermissionSync`
  (wołane przez `admins:sync` i `MakeAdmin`) woła `shield:generate` dla obu paneli
  (`--option=permissions --silent`, bez nadpisywania istniejących polityk) **przed** synchronizacją
  roli — nie wracaj do samego `syncPermissions(Permission::all())` bez tego kroku.
- **Nazwa roli super admina ma jedno źródło prawdy: `config('filament-shield.super_admin.name')`.**
  Zarówno `AdminPermissionSync`, jak i `tests/TestCase.php::createSuperAdmin()` czytają tę samą
  wartość. Przed zadaniem 008 testy tworzyły osobną rolę `'Super Admin'` (literał), różną od
  produkcyjnej `'super_admin'` z konfiguracji — dwie różne role o zbliżonej nazwie, więc testy nie
  pokrywały tego, co robiła produkcja. Nie wprowadzaj drugiego miejsca z nazwą roli na sztywno.
- **`MakeAdmin` zakłada albo promuje konto** (ustawia `is_admin`), a rolę i uprawnienia deleguje do
  `AdminPermissionSync`. Obie komendy są **idempotentne**: na komplecie nie zmieniają stanu i mówią
  to wprost, na roli niepełnej uzupełniają braki bez duplikatów. Ręczne `MakeAdmin` jest dziś
  potrzebne wyłącznie do założenia (albo wskazania) konta administratora.

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
  konfigurację, wszystkie polityki, `OwnerRoleProvisioner::addOwnerRole()` i listy w `tests/TestCase.php`.
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

⚠️ **Wszystkie trzy warstwy niesie [`App\Services\FisheryAccess`](../../app/Services/FisheryAccess.php)**
(od zadania 013; wcześniej `App\Helpers\Helper`). Tam mieszkają też `isOwnerPanel()`
i `isAdminPanel()` — **celowo razem z bramkami**, bo `scopeToOwnedFisheries()` jest fail-closed
(`! isAdminPanel()`) i obie połowy tego niezmiennika muszą reagować identycznie na nierozpoznany
panel. Nie rozdzielaj ich między klasy.

⚠️ **Zawężenie danych pyta `! isAdminPanel()`, nigdy `isOwnerPanel()`.** Obie metody są publiczne
i łatwo je pomylić. Różnica ujawnia się przy panelu **zarejestrowanym, ale nie-adminowym**:
`! isAdminPanel()` wtedy zawęża, `isOwnerPanel()` nie. `isOwnerPanel()` jest wyłącznie do decyzji
o widoczności elementów interfejsu — `->hidden()`, gałąź kreatora, tytuł strony. Dotyczy to
`getEloquentQuery()` każdego zasobu widzącego dane wielu właścicieli; dziś stosują to
`FisheryResource` i `CompanyResource`.

⚠️ **Żaden z tych zapisów NIE jest fail-closed poza kontekstem panelu — i nie udawaj, że jest.**
`Filament::getCurrentOrDefaultPanel()` spada na panel **domyślny**, a domyślny to admin
(`AdminPanelProvider::panel()` woła `->default()`). W kolejce, komendzie konsolowej i na
przyszłej stronie publicznej `isAdminPanel()` zwraca więc `true` i **zawężenie nie działa**.
Zweryfikowane wprost: poza panelem `getCurrentOrDefaultPanel()` daje `admin`, a
`getCurrentPanel()` — `null`. Praktyczny skutek: `FisheryAccess::findFishery()` jest „domyślnie
zawężone" **tylko w panelu**; wołając je spoza panelu, podaj `$scopedToCurrentUser` jawnie.
Gdyby zawężenie miało obowiązywać także tam, trzeba pytać `getCurrentPanel()` bez fallbacku —
to zmienia zachowanie kolejek i komend, więc jest osobną decyzją, nie poprawką przy okazji.

Stanowiska, usługi dodatkowe i pozwolenia należą do łowiska, a ich polityki **przy tworzeniu nie
widzą rekordu nadrzędnego** — `PositionPolicy::create()` dostaje sam typ i przepuszcza każdego
z rolą `owner`. Dlatego przynależność do łowiska pilnują trzy warstwy naraz i **żadnej nie wolno
zdejmować pojedynczo** (zadanie 012, sekcje 15 i 16):

1. **Zawężenie zapytania zasobu** — `FisheryAccess::scopeToOwnedFisheries($query)` w `getEloquentQuery()`.
   To jedyna warstwa działająca na **odczycie pojedynczego rekordu** (`resolveRecordRouteBinding()`
   na stronie edycji), więc bez niej da się wejść na cudzy rekord wprost z URL-a. Nowy zasób
   podrzędny wobec łowiska dostaje ją jedną linijką — **nie przeklejaj warunku**.
   ⚠️ To zawężenie **nie obejmuje zapytań o opcje w formularzu** (`Select`, `CheckboxList`).
   Te liczą się z `$get('fishery_id')`, czyli z pola `Hidden` — danych od klienta — i muszą
   przejść przez `scopeToOwnedFisheries()` osobno, inaczej podmiana stanu wyświetli nazwy
   z cudzego łowiska. Zapis pozostaje bezpieczny, ale to i tak wyciek odczytowy.
   ⚠️ **Dotyczy to także wartości wyliczanych z tych pól przy zapisie**, nie tylko list opcji.
   `AvailabilityBlockResource::withSelectionLabel()` zapisuje do `selection_label` nazwę
   wybranej grupy — wyszukanie tej grupy musi mieć `where('fishery_id', …)`, bo samo
   zawężenie opcji nie obejmuje wartości przysłanej w żądaniu. Cechy stanowisk zawężeniu
   **nie podlegają i podlegać nie mogą**: ich słownik jest wspólny dla całego portalu.
   ⚠️ **`scopeToOwnedFisheries()` zawęża WYŁĄCZNIE zasoby podrzędne** — dokłada warunek na
   kolumnie `fishery_id`. Nałożone na `Fishery::query()` wywala zapytanie błędem SQL
   („Unknown column 'fishery_id'"), czyli awarią, nie dziurą. Bramką dla samego łowiska jest
   `FisheryAccess::findFishery()`, domyślnie zawężone do łowisk bieżącego użytkownika.
2. **Bramka na każdym żądaniu listy** — `FisheryAccess::assertFisheryAccessOrAbort($this->fisheryId)`
   w `getTableQuery()`, nie tylko w `mount()`. ⚠️ `$fisheryId` jest publiczną właściwością
   komponentu wiązaną z query stringiem, więc kolejne żądanie Livewire może przynieść inną
   wartość — albo `null`, przy którym warunkowy filtr nie dokładał **żadnego** ograniczenia.
3. **Bramka przy zapisie** — `FisheryAccess::forceVerifiedFishery($data)` w
   `mutateFormDataBeforeCreate()` **oraz** `mutateFormDataBeforeSave()`. ⚠️ Obie, nie jedna:
   `mount()` strony edycji sprawdza łowisko rekordu **sprzed** zmiany, a `fishery_id` jest
   w formularzu polem `Hidden`, więc bez tego dało się przenieść własny rekord pod cudze łowisko.

⚠️ **Bramka przy zapisie musi być bezstanowa.** Nie zapamiętuj zweryfikowanego ID we właściwości
strony: Livewire utrwala między żądaniami **wyłącznie właściwości publiczne**, a żądanie zapisu
leci na `/livewire/update` i nie niesie ani `?fishery`, ani niczego z `protected`. Pierwsza wersja
tej poprawki właśnie tak wyglądała i przerywała zapis błędem 404 — złapały to testy, nie przegląd.

⚠️ **Polityka bez sprawdzenia właściciela na rekordzie jest zerową warstwą, nie pierwszą.**
`OwnerRoleProvisioner::addOwnerRole()` nadaje roli `owner` **pełny** zestaw `*:fishery` i `*:company`, więc
samo `$user->can('update:fishery')` zwraca `true` dla cudzego rekordu. Polityka zasobu należącego
do właściciela musi porównać `user_id` — wzorzec w `FisheryPolicy`/`CompanyPolicy`/`PositionPolicy`.
Administrator (`is_admin`) zostaje poza zawężeniem także wtedy, gdy ma dodatkowo rolę `owner`.

⚠️ **Nazwa cudzego rekordu też jest danymi.** Okruszki i tytuły stron `Create*` czytają `?fishery`
wprost z żądania, a Filament przelicza je przy **każdym** renderze Livewire — bramka z `mount()`
biegnie tylko przy pierwszym GET-cie. Dlatego `FisheryAccess::findFishery()` jest **domyślnie zawężone**,
a wariant nieograniczony trzeba wybrać świadomie. Nie odwracaj tej domyślności.

⚠️ **Ustawienie bezpieczeństwa dodane do jednego panelu trzeba dodać do drugiego.**
`TwoFactorMiddleware` żył w stosie `/admin` i nie żył w `/owner` przez dwa zadania i trzy tury
przeglądu kodu — wyłapał to dopiero audyt czytający oba providery obok siebie. Konfiguracja
per panel nie dziedziczy się sama; przy każdej zmianie w `AdminPanelProvider` sprawdź
`OwnerPanelProvider` i odwrotnie.

### Warstwa 4 — relacje wiele-do-wielu mają regułę na WARTOŚCIACH, nie tylko zawężone opcje

⚠️ **Zawężenie `options()` nie jest walidacją.** Wartość pola wielokrotnego wyboru (`Select
->multiple()`, `CheckboxList`, repeater) jest stanem komponentu Livewire i da się ją podmienić
w żądaniu; **Filament nie sprawdza, czy przysłane identyfikatory pochodzą z listy, którą
wyrenderował**. Sprawdzone wprost, nie założone (security-review 2026-09-20): bez reguły
właściciel podpinał stanowiska **cudzego** łowiska do własnej grupy, a stamtąd akcja zbiorcza
„Ustaw cechę" zapisywała wiersze na tych stanowiskach.

⚠️ To **nie dotyczy** pojedynczego `Select` z `options()` w akcji — tam Filament wartość
odrzuca. Różnica jest w polu relacyjnym i łatwo się na niej przejechać: test przez
`callTableBulkAction()` na zwykłym `Select` przechodzi na zielono nawet z wyłączoną regułą.

Dom reguły: [`RecordsBelongToFishery`](../../app/Rules/RecordsBelongToFishery.php) — generyczna,
pustą wartość przepuszcza. Gdy zbiór ma być **niepusty**, użyj
[`PositionsBelongToFishery`](../../app/Rules/PositionsBelongToFishery.php), która dokłada ten
warunek i deleguje samą przynależność do tej pierwszej. Dzisiejsze zastosowania:
`PositionGroupResource::positions`, `PositionResource::groups`, `PositionResource::long_term_permit_id`,
`AvailabilityBlockResource::positions`.

⚠️ **Repeater nie ma pola, do którego dałoby się przypiąć błąd** — dla usług dodatkowych bramka
stoi w [`AdditionalServiceSync`](../../app/Services/AdditionalServiceSync.php), czyli w jedynym
wejściu zapisu tej relacji, i **pomija** pozycje spoza łowiska zamiast wywracać cały zapis.

### Rola nadawana przy zakładaniu konta, uprawnienia definiowane osobno

⚠️ **`OwnerRoleProvisioner::addOwnerRole()` NIE ustawia uprawnień roli.** Wołają je ścieżki
rejestracji i logowania, czyli obsługa żądania nieuprzywilejowanego użytkownika — a wcześniej
metoda kończyła się `syncPermissions()`. Skutkowało to tym, że dowolny użytkownik jednym
żądaniem przywracał uprawnienia globalnej roli `owner` do literałów z kodu, kasując zmiany
administratora, a rola odebrana komuś wracała przy następnym logowaniu przez Google.
Uprawnienia ustawia `provisionRole()` — wołane z bootstrapu, gdy roli **jeszcze nie ma**.
⚠️ **Logowanie społecznościowe nadaje rolę wyłącznie pod `wasRecentlyCreated`.**

### Inline editable columns

⚠️ `ToggleColumn`/`TextInputColumn` z `->hidden()` **są** chronione po stronie serwera —
`HasColumns::updateTableColumnState()` sprawdza `isHidden()` i przerywa. Ale ta ścieżka **nie
pyta polityki**: ochroną jest zawężenie zapytania tabeli plus `hidden()`. Filament ostrzega
o tym we własnym źródle przy `callTableColumnMethod()` („Inline editable columns called through
here bypass Model Policies"). Kolumna edytowalna inline, której widoczność nie jest zawężona
zapytaniem, wymaga `->updateStateUsing()` z jawną autoryzacją.

⚠️ **Testy tych warstw weryfikuj negatywnie** — zepsuj bramkę i sprawdź, że test czerwienieje.
Asercje typu „nie zawiera nazwy cudzego rekordu" łatwo przechodzą z niewłaściwego powodu: kolumny
opisowe mają `limit(20)`, więc losowa treść z fabryki i tak nie trafia do HTML-a w całości.
Wzorzec: `tests/Feature/OwnerPanelTest.php`, przypadki „Owner can not…".

---

## 5. Polityka odpowiada zasobowi Filamenta; model bez zasobu autoryzuje się przez rodzica

- **Niezmiennik:** plik w `app/Policies/` istnieje dla modelu, który ma **zarejestrowany zasób
  Filamenta**. Dziś osiemnaście polityk odpowiada siedemnastu zasobom z `app/Filament/Resources/`
  **plus zasobowi ról dostarczanemu przez Shielda** (stąd `RolePolicy` bez pliku w katalogu
  zasobów) — licznik zgadza się dopiero z tym osiemnastym.
- **Model bez własnego zasobu autoryzuje się przez rodzica.** `SalePeriod` istnieje wyłącznie
  przez łowisko: edytuje się go `Repeaterem` na stronie ustawień, a dostępu pilnuje
  `FisheryPolicy` — kto może edytować łowisko, ten edytuje jego sezony.
- ⚠️ **Dlaczego nie „na wszelki wypadek własna polityka":** `shield:generate` wyprowadza
  uprawnienia z **zarejestrowanych zasobów**, a `ShieldPermissionNamesTest` skanuje
  `app/Policies/*.php` po literałach uprawnień i porównuje je z faktycznym wynikiem generatora.
  Polityka pytająca o `'view_any:sale_period'` nazwałaby uprawnienie, którego generator nigdy
  nie utworzy — i wywróciłaby ten test. To nie jest uproszczenie do posprzątania później, tylko
  jedyny spójny kształt przy dzisiejszej konfiguracji Shielda.
- **Co to znaczy dzisiaj, wprost:** kto może edytować łowisko, ten może zmienić jego sezony
  sprzedaży. Rozróżnienia uprawnień nie ma, dopóki ktoś go świadomie nie wprowadzi.
- **Droga wyjścia, gdy uprawnienia trzeba będzie zróżnicować** (np. pracownik łowiska zarządza
  stanowiskami, ale nie rusza sezonów i cen) — decyzja NIE jest jednokierunkowa:
  1. **Podstawowa:** dodać metodę do istniejącej polityki (`FisheryPolicy::updateSaleSettings()`)
     i wołać ją ze strony ustawień. Jeśli reguła daje się wyrazić bez **nowego literału
     uprawnienia** — rolą, istniejącym uprawnieniem, warunkiem na rekordzie — to cała robota:
     zero zmian w Shieldzie, `ShieldPermissionNamesTest` nietknięty.
  2. **Tylko gdy potrzebne jest nazwane uprawnienie Shielda** (żeby dało się je klikać przy roli):
     dopisać klucz do `custom_permissions` w `config/filament-shield.php` (dziś pusta tablica)
     i przegenerować. `shield:generate` tworzy uprawnienia **bez zasobu** —
     robi to `generateCustomPermissions()` wołane z `GenerateCommand`. Dopiero wtedy polityka
     może pytać o ten literał i nadal przechodzić test.
- ⚠️ **Test czerwienieje TYLKO od nowego literału uprawnienia**, nie od nowej metody w polityce.
  Sama metoda niczego nie wyzwala.
- **Zawężenie widoczności bierze się z rodzica.** Strona ustawień jest stroną `FisheryResource`,
  więc wiązanie rekordu przechodzi przez `getEloquentQuery()` z `forCurrentUser()`, a
  `EditRecord::authorizeAccess()` pyta `FisheryPolicy::update()`. Cudze łowisko **nie istnieje**
  dla tej strony (404), nie „istnieje, ale zabronione".

---

## 6. Uwierzytelnianie: drugi składnik i logowanie społecznościowe

⚠️ **Drugi składnik ma licznik prób, nie tylko `throttle`.** Kod jest sześciocyfrowy i żyje
dziesięć minut, a sesja guarda `web` istnieje **już** w momencie wyzwania (Breeze uwierzytelnia
przed przekierowaniem na `/verify`) — więc bez licznika napastnik z samym hasłem przechodził
przez przestrzeń kodów w pętli. Obowiązują **obie** warstwy naraz i żadnej nie wolno zdjąć
pojedynczo:

1. `throttle` na `verify.store` **i** `verify.resend` (`routes/auth.php`) — bez tej drugiej
   wyczerpanie licznika obchodziło się przez zamówienie nowego kodu;
2. licznik nieudanych prób w `TwoFactorController` (`RateLimiter`, klucz per użytkownik), który
   po pięciu pudłach **unieważnia kod**, a nie tylko odracza kolejną próbę.

⚠️ **Logowanie społecznościowe rozpoznaje konto najpierw po TOŻSAMOŚCI DOSTAWCY**
(`users.provider` + `users.provider_id`, **unikalny indeks**, jedno powiązanie na konto), a adres
e-mail służy wyłącznie **dowiązaniu** do konta, które dostawcy jeszcze nie ma
([ADR-019](../adr/ADR-019-dowiazanie-dostawcy-logowania-do-istniejacego-konta.md)).
`provider`, `provider_id` i `has_password` **nie są w `$fillable`** i mają tam nie trafić — to
powierzchnia mass-assignment; zapis wyłącznie przez `forceFill`. Kontroler:

- odrzuca payload bez `id` albo bez adresu;
- odrzuca `email_verified` różne od `true`, jeśli dostawca tę flagę podaje;
- **dowiązuje dostawcę do konta bez dostawcy o tym samym adresie** (bez rozróżniania wielkości
  liter — załatwia to kolacja kolumny), ale **wyłącznie przy JAWNYM `email_verified === true`**;
  brak klucza (dostawca się nie wypowiada, np. Facebook) blokuje dowiązanie:
  - konto **zweryfikowane** → dostawca zapisany, hasło zostaje (konto hybrydowe);
  - konto **niezweryfikowane** → dostawca zapisany, weryfikacja ustawiona, hasło zastąpione losowym
    (`has_password = false`), komunikat na ekranie 2FA o ustawieniu hasła przez reset;
- odmawia, gdy adres ma już **innego** dostawcę;
- zapisuje dowiązanie w dzienniku zmian **z nazwą dostawcy, bez `provider_id`**;
- nadaje rolę `owner` wyłącznie przy zakładaniu konta.

⚠️ **Dowiązanie po adresie jest bezpieczne wyłącznie dzięki TRZEM warunkom naraz** (ADR-019):
jawne potwierdzenie adresu u dostawcy, `->emailVerification()` w **obu** panelach (konto
niezweryfikowane jest martwe dla swojego twórcy) i **2FA wysyłane na adres konta** (konto u dostawcy
na adres, do którego ktoś stracił skrzynkę, nie przejdzie drugiego kroku). **Zmiana kanału 2FA,
wyłączenie 2FA na tej ścieżce albo zdjęcie weryfikacji adresu z któregoś panelu wymaga ponownej oceny
ADR-019** — inaczej dowiązanie staje się przejęciem konta, także `is_admin`, bo konta administratorów
dowiązują się na tych samych zasadach.

- **`users.has_password` mówi, czy użytkownik ZNA hasło** — konto założone przez dostawcę ma hasło
  losowe (kolumna `password` jest NOT NULL). Jeden dom reguły: hak `saving` w `User` — zmiana
  `password` bez jawnego `has_password` ustawia `true` (reset, profil, administrator). Losowe hasło
  ustawia wyłącznie `SocialAuthController`, razem z `has_password = false`.

⚠️ **2FA obowiązuje na tej ścieżce tak samo jak przy haśle** — `TwoFactorMiddleware` nie wyłapie
braku, bo pusty kod traktuje jako „brak oczekującego wyzwania".

Zadania źródłowe: 012, 028; security-review 2026-09-20.

---

## 7. Szablony i dokumenty łowiska (zadanie 021)

- **`DocumentTemplatePolicy`** odpowiada zasobowi szablonów w `/admin`. ⚠️ **Rola `owner` dostaje
  wyłącznie `view_any:document_template` i `view:document_template`** (lista przy nowej wersji
  i podgląd); tworzenie, edycja i usuwanie zostają przy administratorze.
  ⚠️ `provisionRole()` ustawia uprawnienia tylko roli, której nie ma (§4 — „Rola nadawana przy
  zakładaniu konta"), więc istniejącej roli `owner` dokłada je migracja przez
  `OwnerRoleProvisioner::grantDocumentTemplateReading()` — bez ruszania pozostałych uprawnień.
  Kolejne uprawnienie dla istniejącej roli idzie tą samą drogą, nie przez `syncPermissions()`.
- **`Document` nie ma polityki** — nie ma zasobu (§5). Stronę „Dokumenty" i każdą jej akcję
  autoryzuje `FisheryPolicy::update()`, podgląd szablonu — `FisheryPolicy::view()` plus
  `DocumentTemplatePolicy::viewAny()`. Nienaruszalność wersji to reguła **danych**, nie dostępu —
  pilnuje jej model, także wobec administratora.
