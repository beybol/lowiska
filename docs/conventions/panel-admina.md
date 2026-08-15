# Konwencje: panel administratora

Obowiązuje przy zmianach w `app/Filament/Resources/**`,
`app/Providers/Filament/AdminPanelProvider.php`.

Zadania źródłowe: 005, 009. Uzasadnienia w ADR-0013/ADR-0014 (`gcp-foundation`, cross-repo).

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
- ⚠️ **Strony autoryzacji Filamenta konfiguruje się przez nadpisanie `form(Schema $schema)`**,
  nie przez `getForms()` + `makeForm()` (metoda nie istnieje od wersji 5). Pozostawiony
  `getForms()` **nie jest wołany i nie zgłasza błędu** — strona zwraca 200 i po cichu renderuje
  wyłącznie domyślne pola rodzica. Własne pola rejestracji pilnuje
  [`tests/Feature/PanelRegistrationFormTest.php`](../../tests/Feature/PanelRegistrationFormTest.php),
  bo test samego kodu odpowiedzi tej awarii nie widzi.
