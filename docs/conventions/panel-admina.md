# Konwencje: panel administratora

Obowiązuje przy zmianach w `app/Filament/Resources/**`,
`app/Providers/Filament/AdminPanelProvider.php`.

Zadania źródłowe: 005, 009, 011. Uzasadnienia w ADR-0013/ADR-0014 (`gcp-foundation`, cross-repo).

---

## 1. Uploady (`FileUpload`)

- **Komponenty `FileUpload` nie przybijają dysku wywołaniem `->disk('...')`.** Dysk pochodzi
  wyłącznie z konfiguracji — `config('filament.default_filesystem_disk')`
  (zmienna `FILAMENT_FILESYSTEM_DISK`), którą Filament rozwiązuje sam, gdy `->disk()` nie jest
  wywołane (`BaseFileUpload::getDiskName()`). Przybite `->disk('public')` sprawiało, że zmienna
  środowiskowa nie przełączała niczego naprawdę — dokładnie ten stan zastany jest w bliźniaczym
  PunktachSzczepień i **nie wracaj do niego**.
- Lokalnie dysk domyślny to `public` (`FILESYSTEM_DISK=public`), na Cloud Run — `gcs`
  (`FILESYSTEM_DISK=gcs`, `FILAMENT_FILESYSTEM_DISK=gcs`). Pełny opis granicy lokalne/Cloud Run:
  `docs/operations/obraz-produkcyjny.md`.
- ⚠️ **`'visibility_handler' => \League\Flysystem\GoogleCloudStorage\UniformBucketLevelAccessVisibility::class`
  na dysku `gcs` (`config/filesystems.php`) jest obowiązkowy — nie usuwaj go.** Bucket fundamentu
  ma `uniform_bucket_level_access = true` bezwarunkowo; domyślny handler Flysystem próbuje ustawić
  legacy ACL przy każdym uploadzie i pęka błędem `Cannot insert legacy ACL for an object when
  uniform bucket-level access is enabled` — ujawnia się dopiero przy pierwszym realnym uploadzie
  na środowisku Cloud Run, nie lokalnie.
- Poza środowiskiem `local`/`testing` aplikacja **odmawia startu**, jeśli dysk uploadów rozwiązuje
  się do sterownika `local` (`AppServiceProvider::assertUploadDiskIsSafe()`) — to jest właściwa
  ochrona przed cichą utratą plików na efemerycznym kontenerze Cloud Run, nie odwrócony fallback
  konfiguracji. Rozstrzygnięcie i uzasadnienie w treści zadania 005.

⛏️ **Panel właściciela nie ma dziś żadnego pola `FileUpload`.** Gdy je dostanie, ten sam
niezmiennik go obejmuje — dopisz wtedy odsyłacz do tej sekcji
w [`panel-wlasciciela.md`](panel-wlasciciela.md) (albo, jeśli reguła urośnie ponad uploady,
wydziel wspólny plik o storage'u).

ℹ️ **Zasoby z `app/Filament/Resources/**` są współdzielone z panelem właściciela** — zanim
zmienisz zachowanie zasobu, sprawdź [`panel-wlasciciela.md`](panel-wlasciciela.md), bo część
z nich rozgałęzia się przez `Helper::isOwnerPanel()` (m.in. kreator zakładania łowiska
i hub „Zarządzaj łowiskiem", ADR-006).

---

## 2. Formularze na Filamencie 5 (`Schema`)

- **Zasób deklaruje formularz jako `public static function form(Schema $schema): Schema`
  i wypełnia go `->components([...])`** — nie `form(Form $form)` ani `->schema([...])` na poziomie
  formularza. `Filament\Forms\Form` nie istnieje od wersji 4; formularze, infolisty i układ
  scalono we wspólny `Filament\Schemas\Schema`. Komponenty układu (`Section`, `Actions`, `Get`)
  żyją teraz w `Filament\Schemas\Components\**`, a akcje tabel w `Filament\Actions\**`.
- **Tabela używa `->recordActions([...])` i `->toolbarActions([...])**` zamiast `->actions()`
  i `->bulkActions()`.
- ⚠️ **Nazwa komponentu NIE MOŻE być równa kluczowi stanu, który ten komponent sam odczytuje.**
  `Placeholder::make('error')` z `->content(fn (Get $get) => $get('error'))` do Filamenta 3
  działało; od 4/5 komponent odpytuje sam siebie i wpada w **rekursję bez dna** — proces zjada
  kilka gigabajtów i ginie (w testach jako `Segmentation fault`, bo pcov maskuje wyczerpanie
  pamięci), zamiast rzucić czytelnym błędem. Stąd `Placeholder::make('cso_error_message')`
  czytający stan `error` w `CompanyResource`. Diagnoza w zadaniu 009.
- ⚠️ **Nie kopiuj widoków Blade Filamenta, żeby dołożyć własny kawałek strony.** Filament 5 nie
  ma już komponentów `<x-filament-panels::form>` ani `form.actions` — strony auth budują treść
  przez `content(Schema $schema)`. Własna kopia widoku przeżywa aktualizacje pakietu jako
  **martwy, niekompilowalny plik** i wywraca `view:cache`, czyli **start obrazu produkcyjnego** —
  a lokalnie nie widać tego wcale, bo Blade kompiluje leniwie (diagnoza w zadaniu 009).
  Pilnuje tego [`tests/Feature/ViewCompilationTest.php`](../../tests/Feature/ViewCompilationTest.php).
- ⚠️ **Strony autoryzacji Filamenta konfiguruje się przez nadpisanie `form(Schema $schema)`**,
  nie przez `getForms()` + `makeForm()` (metoda nie istnieje od wersji 5). Pozostawiony
  `getForms()` **nie jest wołany i nie zgłasza błędu** — strona zwraca 200 i po cichu renderuje
  wyłącznie domyślne pola rodzica. Własne pola rejestracji pilnuje
  [`tests/Feature/PanelRegistrationFormTest.php`](../../tests/Feature/PanelRegistrationFormTest.php),
  bo test samego kodu odpowiedzi tej awarii nie widzi.

---

## 3. Pola haseł w formularzach zasobów

- **`form()` zasobu jest współdzielone przez strony `Create*` i `Edit*`** — pole, które ma się
  zachowywać inaczej w każdej z nich, rozróżnia je przez `$operation`, nie przez osobne
  formularze. Wzorzec dla hasła (`UserResource`):
  ```php
  TextInput::make('password')
      ->password()
      ->revealable()
      ->required(fn (string $operation): bool => $operation === 'create')
      ->dehydrated(fn (?string $state): bool => filled($state)),
  ```
  `required()` tylko przy tworzeniu; `dehydrated()` wypuszcza wartość do modelu **wyłącznie gdy
  pole jest wypełnione**, więc edycja innych pól nie zeruje istniejącego hasła.
- ⚠️ **Nie wołaj `Hash::make()` w formularzu.** `User::$casts` ma `'password' => 'hashed'`,
  więc model haszuje przy zapisie sam — jawne haszowanie w komponencie daje **podwójny hash**
  i ciche zerwanie logowania (hasło przestaje pasować, bez żadnego błędu przy zapisie).
- ⚠️ Pole wymagane przez bazę, którego **nie ma w formularzu**, wywraca zapis błędem SQL
  (`Field 'x' doesn't have a default value`) dopiero przy realnym `create()` — nie przy
  otwarciu formularza. Formularz zasobu musi pokrywać wszystkie kolumny `NOT NULL` bez wartości
  domyślnej. Tak zniknęło pole `password` z `UserResource` i tworzenie użytkownika przez panel
  nie działało (diagnoza w zadaniu 011); pilnuje tego
  [`tests/Feature/UserResourceTest.php`](../../tests/Feature/UserResourceTest.php).

---

## 4. Redirect po utworzeniu rekordu — lista, nie edycja

- **Standardowy CRUD nadpisuje `getRedirectUrl(): string` na stronie `Create*`, żeby po
  zapisaniu wrócić na listę zasobu**, nie na domyślny widok edycji Filamenta (zadanie 011).
  Wzorzec — dla zasobów bez podziału po łowisku:
  ```php
  public function getRedirectUrl(): string
  {
      return XResource::getUrl('index');
  }
  ```
  a dla zasobów zagnieżdżonych pod łowiskiem (stanowiska, usługi dodatkowe, pozwolenia)
  celem jest **zakładka huba „Zarządzaj łowiskiem"**, nie samotna strona listy (zadanie 012):
  ```php
  public function getRedirectUrl(): string
  {
      // Źródłem prawdy jest ZAPISANY rekord, nie parametr URL — przy właścicielu
      // dwóch łowisk rekord mógł wylądować w B, a przekierowanie prowadzić do A.
      $fisheryId = $this->record->fishery_id ?? request()->get('fishery');

      return self::sectionUrl($fisheryId);
  }

  private static function sectionUrl(int|string|null $fisheryId): string
  {
      return Helper::fisherySectionUrl(
          XResource::class,
          XRelationManager::class,
          $fisheryId,
      );
  }
  ```
  Ta sama metoda obsługuje też `getBreadcrumbs()` i stronę `Edit*`, żeby zapis i okruszki
  prowadziły w to samo miejsce. Szczegóły — w tym dlaczego numeru zakładki nie wolno wpisywać
  ręcznie — w [`panel-wlasciciela.md`](panel-wlasciciela.md).
- **Dzisiejsze wyjątki od tej reguły:**
  - **`CompanyResource` — świadomie wyłączony.** To jedna klasa współdzielona między panelami
    (patrz niżej), a panel właściciela ma pozostać bez zmian. Wprowadzenie rozgałęzienia
    `Helper::isOwnerPanel()` tylko po to, żeby admin zachowywał się inaczej niż owner, uznano
    za nieproporcjonalny koszt (zadanie 011, „Rozstrzygnięcia").
  - **`FisheryResource` — zgodny w adminie, odstępstwo tylko w panelu właściciela.**
    `CreateFishery::getRedirectUrl()` rozgałęzia jawnie: admin wraca na listę (czyli spełnia
    regułę), właściciel trafia do huba „Zarządzaj łowiskiem", bo kreator kończy tam proces
    zakładania (ADR-006, [`panel-wlasciciela.md`](panel-wlasciciela.md) §1).
    ⚠️ Dawny przepływ `?wizard=true` (Company → verify-company → Fishery) **już nie istnieje** —
    zadanie 012 usunęło `VerifyCompany` i parametr `wizard`.
  - `LongTermPermitResource` — już zgodny z regułą od zanim reguła powstała; to on był wzorcem
    dla zasobów podrzędnych, zanim zadanie 012 przeniosło ich cel na zakładkę huba.
- ⚠️ **`AdditionalServiceResource` i `PositionResource` nie mają osobnych klas per panel** —
  `OwnerPanelProvider` rejestruje wprost te same klasy z `app/Filament/Resources/`, które widzi
  panel admina. Zmiana `getRedirectUrl()` dla tych dwóch zasobów obejmuje **automatycznie oba
  panele** — nie da się tu ustawić „inny redirect w adminie, inny w ownerze" bez jawnego
  rozgałęzienia analogicznego do `CompanyResource`/`FisheryResource`.
- **Redirect da się testować na dwa sposoby i oba są w użyciu:**
  - `new CreateX; $page->record = $record;` a potem `getRedirectUrl()` — sprawdza gałąź
    fallbacku `$this->record->fishery_id`, bez symulowania żądania
    ([`PositionResourceTest`](../../tests/Feature/PositionResourceTest.php),
    [`AdditionalServiceResourceTest`](../../tests/Feature/AdditionalServiceResourceTest.php)).
  - `Livewire::withQueryParams(['fishery' => $id])->test(CreateX::class)->instance()->getRedirectUrl()`
    — sprawdza gałąź `request()->get('fishery')`, czyli tę realnie używaną w przeglądarce
    ([`OwnerPanelTest`](../../tests/Feature/OwnerPanelTest.php)). Zadanie 011 zapisało tu, że
    `withQueryParams()` nie dowozi query stringa do `mount()`; po upgrade z zadania 009 **dowozi**
    — strona montuje się bez 404 z `assertFisheryAccessOrAbort()` i widzi parametr.
