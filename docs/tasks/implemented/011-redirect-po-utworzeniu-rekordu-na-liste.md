# 011 — Redirect po utworzeniu rekordu Filament — lista zamiast edycji

## Opis problemu

Filament domyślnie po zapisaniu nowego rekordu (`CreateRecord::getRedirectUrl()`) przenosi
użytkownika do widoku edycji dopiero co utworzonego rekordu. Dla większości zasobów w tym
projekcie to zachowanie jest niepotrzebnym krokiem pośrednim — użytkownik dodaje rekord i
oczekuje powrotu na listę, nie kolejnego formularza. Zachowanie ma się zmienić na przekierowanie
do listy (`index`), ale **nie wszędzie automatycznie** — kilka zasobów ma dziś celowo inną,
nieoczywistą ścieżkę po utworzeniu (wizard, redirect już nadpisany), którą trzeba uszanować.

## Wymagania

- Dla wszystkich zasobów panelu admina objętych tym zadaniem (lista niżej) — po zapisaniu
  nowego rekordu przekierowanie ma prowadzić do listy zasobu (`index`), nie do widoku edycji.
- W panelu właściciela zmiana obejmuje **wyłącznie** tworzenie usług dodatkowych
  (`AdditionalServiceResource`) i stanowisk (`PositionResource`) — pozostałe zasoby panelu
  właściciela (`CompanyResource`, `FisheryResource`, `LongTermPermitResource`) **nie są** objęte
  tym zadaniem.
- Zasada ma zostać zapisana w `CLAUDE.md` (sekcja „Konwencje kodu") jako obowiązująca konwencja
  dla standardowych CRUD-ów: nowy zasób Filamenta domyślnie przekierowuje po utworzeniu na listę,
  chyba że treść zadania świadomie ustali inaczej.

### Ważny fakt architektoniczny ustalony podczas wywiadu

`AdditionalServiceResource` i `PositionResource` **nie mają osobnych klas per panel** —
`OwnerPanelProvider` rejestruje bezpośrednio te same klasy z `app/Filament/Resources/`, które
widzi panel admina (podobnie jak `CompanyResource`, `FisheryResource`, `LongTermPermitResource`).
Nie istnieje mechanizm „inny redirect w adminie, inny w ownerze" bez jawnego rozgałęzienia
(`Helper::isOwnerPanel()`, wzorem `FisheryResource\Pages\CreateFishery`). Konsekwencje:

- Zmiana redirectu dla `AdditionalServiceResource` i `PositionResource` **automatycznie** obejmie
  oba panele — to jest spójne z wymaganiem (owner ma się zmienić, admin i tak dostaje ewidencję
  „zmień").
- `CompanyResource` i `FisheryResource` **są tą samą klasą** w obu panelach, mają już dziś
  celową, warunkową nawigację po utworzeniu w trybie wizard (`wizard=true` — kreator
  zakładania łowiska: Company → verify-company → Fishery) oraz odrębną, niewymuszoną ścieżkę
  poza wizardem. Ponieważ owner ma pozostać bez zmian dla tych dwóch zasobów, a nie ma osobnej
  klasy admina — **`CompanyResource` i `FisheryResource` są wyłączone z tego zadania w obu
  panelach**. Decyzja podjęta z użytkownikiem podczas `/create-task` (patrz „Rozstrzygnięcia").

### Ewidencja zasobów panelu admina (13 zasobów w `app/Filament/Resources/`)

| Zasób | Stan dziś | Decyzja tego zadania |
|---|---|---|
| `AdditionalServiceResource` | domyślny redirect do edycji | **zmień → index** (wymagane też przez panel ownera) |
| `CompanyResource` | wizard ma własną nawigację; poza wizardem domyślny redirect | **wyłączone z zadania** (współdzielona klasa z ownerem, patrz wyżej) |
| `ConvenienceResource` | domyślny redirect do edycji, brak wizarda/relacji wymagających edycji tuż po dodaniu | **zmień → index** |
| `CountryResource` | jw. | **zmień → index** |
| `CurrencyResource` | jw. | **zmień → index** |
| `FisheryResource` | wizard ma własną nawigację; poza wizardem domyślny redirect | **wyłączone z zadania** (współdzielona klasa z ownerem, patrz wyżej) |
| `FisheryTypeResource` | domyślny redirect do edycji | **zmień → index** |
| `FishingMethodResource` | jw. | **zmień → index** |
| `FishResource` | jw. | **zmień → index** |
| `LongTermPermitResource` | **już dziś** nadpisuje `getRedirectUrl()` → index (`fishery` w query) | **bez zmian** — już zgodny z docelowym zachowaniem |
| `PositionResource` | domyślny redirect do edycji | **zmień → index** (wymagane też przez panel ownera) |
| `StateResource` | domyślny redirect do edycji | **zmień → index** |
| `UserResource` | role przypisywane bezpośrednio w formularzu tworzenia (`CheckboxList` na relacji `roles`), brak potrzeby edycji tuż po dodaniu | **zmień → index** |

Żaden z ośmiu „prostych" zasobów (Convenience, Country, Currency, FisheryType, FishingMethod,
Fish, State, User) nie ma dziś logiki sugerującej potrzebę pozostania na edycji po utworzeniu
(brak wizardów, brak relation managerów wymagających uzupełnienia zaraz po zapisie) — stąd
propozycja blankietowej zmiany na wszystkich ośmiu. Jeśli w trakcie implementacji okaże się to
nietrafne dla któregoś z nich, zatrzymać się i zapytać, zgodnie z poleceniem użytkownika.

## Kryteria akceptacji

- [x] Dla ośmiu prostych zasobów panelu admina (Convenience, Country, Currency, FisheryType,
      FishingMethod, Fish, State, User) — utworzenie rekordu przenosi na listę zasobu.
      Potwierdzone testem per zasób (8 nowych plików), w tym pełnym cyklem Livewire
      (`fillForm()->call('create')->assertRedirect(...)`) dla siedmiu z nich —
      `UserResourceTest` weryfikuje `getRedirectUrl()` bezpośrednio, patrz „Wyniki weryfikacji".
- [x] Dla `AdditionalServiceResource` i `PositionResource` — utworzenie rekordu przenosi na
      listę zasobu, **w obu panelach** (admin i owner — ta sama klasa), z zachowaniem query
      stringa `fishery` analogicznie do `LongTermPermitResource`. Potwierdzone testem
      wołającym `getRedirectUrl()` na instancji z ustawionym rekordem — nie pełnym cyklem
      Livewire, patrz „Wyniki weryfikacji".
- [x] `CompanyResource` i `FisheryResource` — brak zmian zachowania (zweryfikowane: `git diff`
      tych katalogów jest pusty).
- [x] `LongTermPermitResource` — brak zmian (zweryfikowane: `git diff` pusty).
- [x] `CLAUDE.md`, sekcja „Konwencje kodu", zawiera nową regułę.
- [x] Zakres testów **eskalowany z T1 do T3 w trakcie implementacji** — uruchomiony i zielony
      w całości, nie odroczony. Patrz „Wyniki weryfikacji" po uzasadnienie.

## Zakres testów

- **Tier:** T1 — punktowy
- **Uruchamiamy:** `docker compose exec app php artisan test --filter="AdditionalServiceResourceTest|PositionResourceTest|ConvenienceResourceTest|CountryResourceTest|CurrencyResourceTest|FisheryTypeResourceTest|FishingMethodResourceTest|FishResourceTest|StateResourceTest|UserResourceTest"`
  (dostosować nazwy filtrów do faktycznie utworzonych/zmienionych klas testowych — zadanie
  dodaje test na redirect dla każdego z dziesięciu zmienianych zasobów; jeśli takie klasy testowe
  nie istnieją, utworzyć je zamiast rozszerzać istniejące testy o inny cel).
- **Uzasadnienie:** Zmiana dotyka wyłącznie klas stron `Create*` (nadpisanie/dodanie
  `getRedirectUrl()`) — logika lokalna do każdej strony, bez wspólnej bazowej klasy ani
  zmiany kontraktu współdzielonego przez inne warstwy. Żaden z wyzwalaczy T3 z `CLAUDE.md`
  (provider panelu, `User`/polityki/Shield, migracje, `phpunit.xml`, `composer.json`,
  `Dockerfile`, `docker-compose.yml`) nie jest dotknięty. T2 nie jest potrzebny, bo żadna inna
  klasa nie woła bezpośrednio `getRedirectUrl()` tych stron — to punkt wejścia samego Filamenta
  po submit formularza, nie kontrakt wywoływany z kodu projektu.

## Zakres wyłączeń

- `CompanyResource` i `FisheryResource` — patrz uzasadnienie wyżej („Ważny fakt
  architektoniczny").
- Redirect po **edycji** (`EditRecord::getRedirectUrl()`) — poza zakresem, zadanie dotyczy
  wyłącznie tworzenia.
- Redirect po usunięciu/duplikacji rekordu — poza zakresem.
- Zmiana zachowania `getCreateAnotherFormAction()` („Zapisz i utwórz kolejny") — ten przycisk ma
  z definicji zostać na formularzu tworzenia; zadanie dotyczy wyłącznie ścieżki „Zapisz"
  (`getCreateFormAction()`).

## Zmiany dokumentacji

- [x] `docs/conventions/panel-admina.md` — nowa sekcja „3. Redirect po utworzeniu rekordu —
      lista, nie edycja": oba wzorce (`Xresource::getUrl('index')` i wariant z `fishery`),
      wyjątki (Company/Fishery wyłączone, LongTermPermit jako wzorzec, nie odstępstwo), notatka
      o współdzielonych klasach `AdditionalServiceResource`/`PositionResource` między panelami
      oraz o tym, dlaczego ich testy nie idą przez pełny cykl Livewire.
- [x] `README.md` — bez zmian (nie dotyczy).
- [x] `CLAUDE.md` — nowa reguła w sekcji „Konwencje kodu".
- [x] `CHANGELOG.md` — wpis w „Zmienione".

## Ograniczenia techniczne

- Implementacja przez nadpisanie `getRedirectUrl(): string` na klasach `Create*` — wzorem już
  istniejącego `LongTermPermitResource\Pages\CreateLongTermPermit::getRedirectUrl()`, nie przez
  zmianę zachowania bazowego `CreateRecord` ani konfigurację globalną panelu (Filament nie
  udostępnia takiego przełącznika na poziomie `Panel`).
- Zachować istniejący wzorzec przekazywania `fishery` w query string dla zasobów zagnieżdżonych
  pod łowiskiem (`AdditionalServiceResource`, `PositionResource`, `LongTermPermitResource`).

## Wyniki weryfikacji (implementacja, 2026-08-15)

Stan końcowy: **86 testów, 301 asercji** (baza przed zadaniem: 76/256), `pint` czysto
(211 plików), PHPStan `[OK] No errors`.

### 1. Tier eskalowany z T1 do T3 w trakcie implementacji

Uzasadnienie T1 w treści zadania było poprawne w momencie pisania — zmiana rzeczywiście dotyka
wyłącznie stron `Create*`. Ale żeby **w ogóle napisać** test dla `CurrencyResource` (jedno
z ośmiu wymaganych kryterium akceptacji), trzeba było naprawić
[`tests/TestCase.php`](../../../tests/TestCase.php) — patrz punkt 2 niżej. `tests/TestCase.php`
jest wprost na liście obowiązkowych wyzwalaczy T3 w `CLAUDE.md`. Zgodnie z zasadą „trafienie
w wyzwalacz przesądza tier, nie jest propozycją" — zakres podniesiony **w trakcie**
implementacji, zgłoszone wprost, nie po cichu. Pełny pakiet uruchomiony i zielony (nie
odroczony).

### 2. `tests/TestCase.php::createSuperAdmin()` nie miał uprawnień do `Currency` — od zawsze

Próba napisania testu dla `CurrencyResource` kończyła się **HTTP 403** zamiast oczekiwanego
przekierowania. Przyczyna: `createSuperAdmin()` ręcznie wylicza uprawnienia per zasób
(9 tablic: `company`, `fishery`, `convenience`, `country`, `fish`, `fisheryType`,
`fishingMethod`, `state`, `user`) i syncuje z rolą tylko to, co realnie istnieje w bazie —
`CurrencyResource` nigdy nie miał swojej tablicy, mimo że zasób istnieje w projekcie od dawna.
To nie jest regres tego zadania — to luka odziedziczona, ujawniona dopiero przez pierwszy test
dotykający tego zasobu (żaden nie istniał). Naprawione dopisaniem `$currencyPermissions`
w tym samym wzorcu co pozostałe dziewięć.

⚠️ **Nie sprawdzałem systematycznie pozostałych zasobów spoza tego zadania** (`Role` — brak
własnej tablicy, ale i brak testu, który by to ujawnił) — to może być ten sam wzorzec gdzie
indziej. Poza zakresem tego zadania, warto odnotować jako kandydata do przeglądu.

### 3. Pre-existing bug w `UserResource`: brak pola `password` w formularzu — NAPRAWIONE

Próba przetestowania pełnego cyklu `fillForm()->call('create')` dla `UserResource` kończyła się
**błędem SQL** (`Field 'password' doesn't have a default value`) — formularz zasobu
(`app/Filament/Resources/UserResource.php::form()`) nie miał pola `password`, a kolumna
`users.password` w bazie nie ma wartości domyślnej. Utworzenie użytkownika przez panel
administratora **nie działało w ogóle** — niezależnie od tego zadania.

⚠️ **Początkowo odłożone jako defekt spoza zakresu**, z testem obchodzącym problem
(`getRedirectUrl()` wołane bezpośrednio, z pominięciem `create()`). Na decyzję użytkownika
**naprawione inline w tym samym zadaniu** jako zmiana wystarczająco mała, żeby nie zakładać
osobnego zadania — dodane pole `password` w `form()`:

- `->required(fn (string $operation): bool => $operation === 'create')` — `form()` jest
  współdzielone między `CreateUser` a `EditUser`, więc hasło jest wymagane wyłącznie przy
  tworzeniu;
- `->dehydrated(fn (?string $state): bool => filled($state))` — puste pole przy edycji **nie
  trafia do modelu**, więc edycja innych pól nie zeruje istniejącego hasła;
- bez `Hash::make()` — `User::$casts` ma `'password' => 'hashed'`, więc model haszuje sam;
  jawne haszowanie dałoby podwójny hash.

Test obchodzący problem zastąpiony pełnym cyklem, plus dwa nowe testy pokrywające obie gałęzie
(`password is required on create and hashed`, `editing a user without touching the password
field keeps it intact`). Obie gałęzie zweryfikowane empirycznie przed zapisaniem testów:
hasło haszowane poprawnie (pojedynczo), edycja bez dotykania pola zachowuje stary hash.

### 4. `Livewire::test()` nie przenosi query stringa do `mount()` — zweryfikowane empirycznie

Dla `AdditionalServiceResource`/`PositionResource`, których `mount()` czyta
`request()->get('fishery')` wprost (nie przez właściwość Livewire `#[Url]`), ani zwykłe
`Livewire::test()`, ani `Livewire::withQueryParams([...])->test()` (który obsługuje wyłącznie
synchronizację `#[Url]`) nie przekazują query stringa do wnętrza komponentu — obie próby kończyły
się `Call to a member function getDefaultTestingSchemaName() on null` (strona `abort(404)`-owała
w `mount()` przez `Helper::assertFisheryAccessOrAbort()`, zanim formularz zdążył się zmontować).

Zamiast symulować pełne żądanie HTTP, testy tworzą stronę bezpośrednio
(`new CreatePosition()`, `new CreateAdditionalService()`) i ustawiają publiczną właściwość
`$record` przed wywołaniem `getRedirectUrl()` — metoda ma fallback
`$this->record->fishery_id`, więc to legalna, węższa weryfikacja tej samej logiki (gałąź
fallbacku), nie obejście. Diagnoza i wzorzec zapisane w
`docs/conventions/panel-admina.md`, sekcja 3.

## Rozstrzygnięcia

- **`CompanyResource` i `FisheryResource` wyłączone z zadania w obu panelach** — bo to jedna
  klasa współdzielona między adminem a ownerem, owner ma jawnie pozostać bez zmian dla tych
  dwóch zasobów, a wprowadzenie rozgałęzienia `Helper::isOwnerPanel()` tylko po to, by admin
  zachowywał się inaczej niż owner, uznano za nieproporcjonalny koszt względem tego zadania.
  Decyzja użytkownika podjęta podczas `/create-task` (opcja „Wyłącz z zadania").

## Powiązane ADR-y

- **Brak.** Rozważone przy `/review-task` i odrzucone: nowa reguła w `CLAUDE.md` („standardowy
  CRUD przekierowuje po utworzeniu na listę") ma zasięg poza to zadanie (wiąże przyszłe zasoby),
  ale nie spełnia kryterium (2) „wysoki koszt odwrócenia" — to pojedyncze nadpisanie
  `getRedirectUrl()` per strona, odwracalne linijkowo i bez żadnego niezmiennika danych ani
  schematu w tle. Wyłączenie `CompanyResource`/`FisheryResource` jest już rozstrzygnięciem
  punktowym wyżej — z tego samego powodu (odwracalne, wąski zasięg).
