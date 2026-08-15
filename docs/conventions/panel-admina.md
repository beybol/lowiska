# Konwencje: panel administratora

Obowiązuje przy zmianach w `app/Filament/Resources/**`,
`app/Providers/Filament/AdminPanelProvider.php`.

Zadania źródłowe: 005. Uzasadnienia w ADR-0013/ADR-0014 (`gcp-foundation`, cross-repo).

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
