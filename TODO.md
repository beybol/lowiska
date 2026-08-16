# TODO

Rzeczy odłożone świadomie — z powodem i momentem, w którym wracamy. To **nie jest** backlog zadań
(te żyją w [`docs/tasks/`](docs/tasks/)), tylko lista pozycji czekających na warunek zewnętrzny.

---

## ~~Po upgrade do Laravel 13 + najnowszego Filamenta~~ — ZAŁATWIONE (2026-08-16)

### ~~Drugi składnik uwierzytelniania w panelu `/owner`~~

**Zamknięte w zadaniu 012**, po tym jak audyt bezpieczeństwa wykazał, że warunek odroczenia
(„po upgrade do Laravel 13 + najnowszego Filamenta") **spełnił się zadaniem 009**, a pozycja
nie została podjęta. `TwoFactorMiddleware::class` jest teraz w stosie obu paneli.

⚠️ **Ostrzeżenie z tej pozycji sprawdziło się co do joty** i warto je zapamiętać: ustawienie
bezpieczeństwa dodane do jednego panelu i niedodane do drugiego przetrwało tu **dwa** zadania
i trzy tury przeglądu kodu — wyłapał je dopiero audyt czytający konfigurację paneli obok siebie.
Odroczenie z warunkiem zewnętrznym wymaga sprawdzenia tego warunku przy każdym większym
upgradzie, inaczej zamienia się w ciche zaniechanie.

Przy okazji audytu naprawiono dwie pokrewne dziury tej samej klasy: logowanie społecznościowe
pomijało 2FA w całości, a `MakeAdmin` ustawiało hasło równe adresowi e-mail. Szczegóły:
`docs/tasks/012-poprawki-panelu-wlasciciela-po-laravel-13.md` §20.

---

## Przed pierwszym wdrożeniem produkcyjnym

### Hasło konta administratora i wymuszone 2FA dla `is_admin`

**Stan dziś:** `MakeAdminCommand` zakłada konto z hasłem **równym adresowi e-mail**, a adres
jest jawny — `deploy.yml` podaje go w `ADMIN_EMAIL`. Konto ma auto-zweryfikowany e-mail
(`User::booted()`) i pusty `two_factor_code`, więc przy pierwszym logowaniu nie ma drugiego
składnika.

**Dlaczego nie naprawiamy teraz:** świadome rozstrzygnięcie autora (zadanie 012 §21). Projekt
nie działa produkcyjnie, staging stoi za dodatkowym hasłem, a panel admina wymaga 2FA. Losowe
hasło byłoby utrudnieniem bez realnej ochrony, a wypisane raz w logach
`gcloud run jobs execute` grozi utratą dostępu do konta.

**Co robimy przy wdrożeniu produkcyjnym — komplet, nie pojedynczy punkt:**

1. Hasło losowe albo podane jawnie przez `--password` (opcja **już istnieje**, wystarczy jej użyć).
2. Wymuszone 2FA dla każdego konta z `is_admin = 1` — dziś świeże konto ma pusty
   `two_factor_code`, a `TwoFactorMiddleware` traktuje pusty kod jako „brak oczekującego wyzwania".
3. Wymuszona zmiana hasła przy pierwszym logowaniu.

⚠️ **Nie odkładaj tego „na po wdrożeniu".** Poprzednia pozycja z warunkiem zewnętrznym w tym
pliku (2FA na `/owner`, warunek: upgrade do Laravela 13) przeżyła spełnienie swojego warunku
o dwa zadania i trzy tury przeglądu kodu — wyłapał ją dopiero audyt.

**Źródło:** audyt bezpieczeństwa przy zadaniu 012 (2026-08-16), znalezisko HIGH; rozstrzygnięcie
autora w `docs/tasks/012-poprawki-panelu-wlasciciela-po-laravel-13.md` §21. Pilnuje tego
`tests/Feature/MakeAdminCommandTest.php`.

---

## Do rozstrzygnięcia — zakres obowiązywania 2FA

### Czy właściciel może sobie wyłączyć drugi składnik?

**Stan dziś:** drugi składnik jest **bezwarunkowy dla wszystkich** — `TwoFactorMiddleware`
stoi w stosie obu paneli (`/admin` i `/owner`, zadanie 012 §20), a `Login::authenticate()`
generuje kod przy każdym logowaniu hasłem i przez dostawcę społecznościowego. Nie ma
przełącznika ani po stronie konta, ani po stronie roli.

**Założenie wstępne (do potwierdzenia analizą):** konta z `is_admin = 1` **muszą** mieć 2FA
włączone bez możliwości wyłączenia; właściciel łowiska **być może** będzie mógł je sobie
wyłączyć.

**Dlaczego to wymaga analizy, a nie decyzji z marszu:**

- **Sesja jest wspólna (guard `web`).** Administrator ma dostęp do panelu właściciela
  (pilnuje tego `OwnerPanelTest`), więc „wyłączone dla ownera" nie może oznaczać „wyłączone
  dla sesji" — inaczej administrator z rolą `owner` obchodziłby własne wymuszenie. To jest
  dokładnie ten kształt błędu, który zadanie 012 właśnie naprawiało.
- **`TwoFactorMiddleware` nie zna pojęcia „ta sesja przeszła drugi składnik"** — traktuje
  *obecność* `two_factor_code` jako *oczekujące wyzwanie*, a jego brak jako „przepuść".
  Dodanie opcji wyłączenia bez zmiany tego modelu da flagę, której nie da się bezpiecznie
  odróżnić od stanu spoczynkowego.
- **Właściciel obraca pieniędzmi** — sprzedaje pozwolenia i przyjmuje rezerwacje, a jego konto
  trzyma numer rachunku (`Fishery::bank_account_number`). Wyłączalne 2FA to decyzja produktowa
  o akceptowanym ryzyku, nie wygoda UI.
- **Filament 5 ma natywne MFA** (`->multiFactorAuthentication(...)`, konfigurowane per panel).
  Zanim dołożymy własny przełącznik do własnego middleware'u, warto sprawdzić, czy natywny
  mechanizm nie rozwiązuje tego lepiej — łącznie z aplikacjami TOTP zamiast kodu na e-mail.

**Kandydat na ADR**, jeśli analiza potwierdzi, że wybór wiąże kod poza jednym zadaniem
(model „sesja przeszła 2FA", miejsce przechowywania flagi, granica ról).

**Powiązane:** wymuszone 2FA dla `is_admin` z pozycji „Przed pierwszym wdrożeniem produkcyjnym"
wyżej — te dwie rzeczy trzeba rozstrzygnąć razem, bo opisują dwie strony tej samej reguły.

---

## Edycja profilu w panelach — zmiana własnego hasła

**Stan dziś:** ani `/admin`, ani `/owner` nie ma strony profilu. Zmiana własnego hasła jest
możliwa **wyłącznie** przez Breeze (`/profile`, poza panelami) albo przez `UserResource`
w panelu administratora — czyli użytkownik z rolą `owner` **nie ma jak zmienić sobie hasła
z panelu, w którym pracuje**, a administrator robi to przez formularz zarządzania cudzymi
kontami.

**Co jest potrzebne:**

- Strona profilu w obu panelach: zmiana hasła (z potwierdzeniem obecnego), dane kontaktowe,
  docelowo przełącznik 2FA — jeśli rozstrzygnięcie wyżej na to pozwoli.
- Filament ma to wbudowane (`->profile()` na panelu); do sprawdzenia, czy domyślna strona
  wystarczy, czy potrzebna jest własna (projekt ma `surname`, `country_id`, `phone` poza
  standardowym zestawem).

**Dlaczego to nie jest kosmetyka:** dopóki nie ma tej strony, „wymuszona zmiana hasła przy
pierwszym logowaniu" z pozycji o wdrożeniu produkcyjnym **nie ma gdzie się wydarzyć**.
Te dwie pozycje są od siebie zależne — profil jest warunkiem koniecznym tamtej.

⚠️ Przy okazji: `UserResource` pozwala administratorowi ustawić hasło dowolnego konta bez
podania obecnego. To jest w porządku dla zarządzania cudzymi kontami, ale **nie** jest
wzorcem do skopiowania na stronę profilu — tam obecne hasło musi być wymagane.

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
