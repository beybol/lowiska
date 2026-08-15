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
