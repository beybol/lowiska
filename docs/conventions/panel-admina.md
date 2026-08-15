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
niezmiennik go obejmuje — dopisz odsyłacz do tego pliku w `docs/conventions/panel-wlasciciela.md`
(albo, jeśli reguła urośnie ponad uploady, wydziel wtedy wspólny plik o storage'u).

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

## 3. Redirect po utworzeniu rekordu — lista, nie edycja

- **Standardowy CRUD nadpisuje `getRedirectUrl(): string` na stronie `Create*`, żeby po
  zapisaniu wrócić na listę zasobu**, nie na domyślny widok edycji Filamenta (zadanie 011).
  Wzorzec — dla zasobów bez podziału po łowisku:
  ```php
  public function getRedirectUrl(): string
  {
      return XResource::getUrl('index');
  }
  ```
  a dla zasobów zagnieżdżonych pod łowiskiem (query string `fishery`), wzorem
  `LongTermPermitResource\Pages\CreateLongTermPermit`:
  ```php
  public function getRedirectUrl(): string
  {
      $fisheryId = request()->get('fishery') ?? $this->record->fishery_id ?? null;

      return XResource::getUrl('index', ['fishery' => $fisheryId]);
  }
  ```
- **Dzisiejsze wyjątki od tej reguły:**
  - **`CompanyResource` i `FisheryResource` — świadomie wyłączone.** To jedna klasa
    współdzielona między panelem admina a panelem właściciela (patrz niżej); wizard zakładania
    łowiska (`wizard=true`: Company → verify-company → Fishery) ma już własną, celową nawigację
    poza tą regułą, a panel właściciela ma pozostać bez zmian dla obu zasobów. Wprowadzenie
    rozgałęzienia `Helper::isOwnerPanel()` tylko po to, żeby admin zachowywał się inaczej niż
    owner, uznano za nieproporcjonalny koszt (zadanie 011, „Rozstrzygnięcia").
  - `LongTermPermitResource` — już zgodny z regułą od zanim reguła powstała; to on jest wzorcem
    powyżej, nie odstępstwem.
- ⚠️ **`AdditionalServiceResource` i `PositionResource` nie mają osobnych klas per panel** —
  `OwnerPanelProvider` rejestruje wprost te same klasy z `app/Filament/Resources/`, które widzi
  panel admina. Zmiana `getRedirectUrl()` dla tych dwóch zasobów obejmuje **automatycznie oba
  panele** — nie da się tu ustawić „inny redirect w adminie, inny w ownerze" bez jawnego
  rozgałęzienia analogicznego do `CompanyResource`/`FisheryResource`.
- ⚠️ **Test tych dwóch zasobów nie idzie przez pełny cykl Livewire** (`fillForm()->call('create')`)
  — `mount()` czyta `request()->get('fishery')` wprost z frameworkowego żądania, a testowy
  harness Livewire (`Livewire::test()`, także `withQueryParams()`, który obsługuje wyłącznie
  właściwości `#[Url]`) nie przenosi query stringa do tego wywołania. Testy
  ([`PositionResourceTest`](../../tests/Feature/PositionResourceTest.php),
  [`AdditionalServiceResourceTest`](../../tests/Feature/AdditionalServiceResourceTest.php))
  wołają `getRedirectUrl()` bezpośrednio na instancji strony z ręcznie ustawionym `$record` —
  testuje to samą logikę (gałąź fallbacku `$this->record->fishery_id`) bez symulowania żądania.
