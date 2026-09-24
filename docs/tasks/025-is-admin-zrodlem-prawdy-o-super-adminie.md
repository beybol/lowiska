# 025 — `is_admin` jedynym źródłem prawdy o super adminie

> **Pochodzenie:** uwaga nr 6 z przeglądu wg frameworka pakietu 017–021 (`/review-implementation`,
> 24.09.2026). Kierunek uzgodniony z autorem tego samego dnia.

## Opis problemu

W aplikacji są dziś **dwie prawdy o tym, kto jest administratorem**:

- **flaga `users.is_admin`** — w pierwotnym zamyśle konto z tą flagą ma dostęp do panelu `/admin`
  i może w nim wszystko; flaga rozstrzyga też dostęp do panelu (`User::canAccessPanel()`);
- **rola `super_admin` z `bezhansalleh/filament-shield`** — z fizycznie przypisanymi uprawnieniami
  (`define_via_gate = false`, zadanie 008, `autoryzacja.md` §1).

Rola jest w praktyce kopią flagi, ale nikt nie trzyma ich w zgodzie:

- **każdy nowy zasób Filamenta dokłada uprawnienia**, których istniejąca rola `super_admin` nie
  dostaje sama. Do czasu ręcznego `php artisan MakeAdmin` administrator nie widzi nowego zasobu.
  Ostatnio: zadanie 021 — „Szablony dokumentów" i szablony przy nowej wersji dokumentu łowiska;
- `deploy.yml` nie uruchamia ani `shield:generate`, ani `MakeAdmin` — luka odnotowana już
  w zadaniu 008 („Zakres wyłączeń") i w `docs/operations/obraz-produkcyjny.md` §11;
- operacyjnie istnieją (i na razie będą) **trzy** konta z `is_admin`, każde w praktyce super adminem.

Nowa, osobna flaga „super admin" zdublowałaby `is_admin` — autor wybrał **usankcjonowanie stanu
faktycznego**: `is_admin = 1` **znaczy** super admina.

## Wymagania

- **`users.is_admin` jest jedynym źródłem prawdy.** Rola `super_admin` Shielda jest **pochodną**
  flagi — kierunek zależności: flaga → rola, nigdy odwrotnie.
- **Nowa, idempotentna komenda artisana `admins:sync`**, która przy każdym uruchomieniu:
  1. generuje uprawnienia Shielda dla obu paneli — tak jak dziś `MakeAdmin`
     (`shield:generate --option=permissions`, bez nadpisywania polityk, `autoryzacja.md` §3);
  2. nadaje roli `super_admin` **wszystkie** uprawnienia;
  3. przypisuje rolę `super_admin` **każdemu** kontu z `is_admin = 1`;
  4. **zdejmuje** rolę `super_admin` z kont bez `is_admin`;
  5. raportuje, co zmieniła (ile uprawnień dopisano, komu nadano i komu zdjęto rolę); drugie
     uruchomienie bez zmian w danych nic nie zmienia i mówi to wprost;
  6. gdy **żadne** konto nie ma `is_admin`, kończy się **ostrzeżeniem i kodem 0** — synchronizacja
     uprawnień i roli i tak się wykonuje, a raport mówi, że nikt nie ma dostępu do `/admin`.
- **Nazwa roli** — wyłącznie z `config('filament-shield.super_admin.name')` (`autoryzacja.md` §1).
- **Rola `owner` jest poza tą komendą** — jej uprawnienia ustawia `OwnerRoleProvisioner`, a nowe
  uprawnienia istniejącej roli dokładają migracje (`autoryzacja.md` §7). Komenda roli `owner` nie
  dotyka.
- **`MakeAdmin` zakłada / promuje konto, a uprawnienia deleguje do nowej komendy** — logika
  synchronizacji ma jeden dom.
- **`.github/workflows/deploy.yml`** — nowy krok w jobie `deploy`, **po** „Migrate database"
  i **przed** „Deploy service", uruchamiający komendę tą samą drogą co migracje (Cloud Run job).
  Błąd komendy zatrzymuje wdrożenie, zanim ruszy nowa rewizja. Job `security` bez zmian — nie
  dostaje sekretów chmurowych ani `id-token: write` (`CLAUDE.md`, „Bezpieczeństwo w CI").
- **Świadome koszty do zapisania wprost** w `autoryzacja.md`:
  - ręczna zmiana uprawnień roli `super_admin` w panelu Shielda jest **cofana** przy następnym
    wdrożeniu — nie ogranicza się administratora przez Shielda;
  - administrator o węższych prawach, gdyby był potrzebny, to **osobna rola**, a nie ograniczone
    `is_admin`; taka rola nie powstaje w tym zadaniu.
- **Uzgodnienie z zadaniem 008:** decyzja o fizycznie przypisanych uprawnieniach (`define_via_gate
  = false`) zostaje w mocy; przestaje obowiązywać wyłącznie powód „da się odebrać super adminowi
  pojedyncze uprawnienie". Zadanie 008 jest zamrożone — korekta idzie do ADR-a/konwencji, nie do
  pliku 008.

## Kryteria akceptacji

- [ ] Komenda na świeżej bazie: generuje uprawnienia, rola `super_admin` ma wszystkie, każde konto
      z `is_admin` ma rolę, konto bez flagi jej nie ma.
- [ ] Komenda po dodaniu nowego zasobu (symulowanego nowym uprawnieniem): rola `super_admin`
      dostaje brakujące uprawnienie bez ręcznego `MakeAdmin`.
- [ ] Konto, któremu zdjęto `is_admin`, traci rolę `super_admin`; konto, któremu flagę nadano —
      dostaje ją.
- [ ] Drugie uruchomienie bez zmian w danych niczego nie zmienia i raportuje brak zmian.
- [ ] Rola `owner` i jej uprawnienia nietknięte przez komendę.
- [ ] `MakeAdmin` korzysta z nowej komendy; `MakeAdminCommandTest` zielony.
- [ ] `deploy.yml` uruchamia komendę po migracjach, przed „Deploy service", w jobie `deploy`;
      job `security` bez zmian.
- [ ] Brak kont z `is_admin`: komenda kończy się kodem 0 z ostrzeżeniem w raporcie.
- [ ] **Pierwsze wdrożenie na staging** (checklista, bez testu automatycznego): log joba pokazuje
      raport `admins:sync`, a administrator widzi „Szablony dokumentów" bez ręcznego `MakeAdmin`.
- [ ] `ShieldPermissionNamesTest`, `AdminPanelTest`, `OwnerPanelTest`, `OwnerRoleProvisioningTest`
      zielone.
- [ ] Zielony pełny pakiet testów (T3).

## Zakres testów

- **Tier:** T3
- **Uruchamiamy:** `docker compose exec app php artisan test`
- **Uzasadnienie:** zadanie trafia w wyzwalacze T3 z `CLAUDE.md` — role i uprawnienia Shielda oraz
  znaczenie `User::is_admin` (dostęp do panelu administratora). T3 nie jest tu przedmiotem wyboru.
  Zmiana `deploy.yml` nie ma testu automatycznego — weryfikuje ją pierwsze wdrożenie na staging
  (kryterium akceptacji z checklistą).

## Zakres wyłączeń

- **Nowa flaga „super admin" na kontach** — odrzucona: zdublowałaby `is_admin`.
- **`define_via_gate = true`** — decyzja zadania 008 zostaje w mocy (rola z realnymi uprawnieniami,
  jeden model uprawnień dla `super_admin` i `owner`).
- **Rola administratora o węższych prawach** — nie powstaje; gdy będzie potrzebna, to osobne zadanie.
- **Wymuszone 2FA dla `is_admin`** — zapowiedziane w komentarzu `MakeAdminCommand`, osobny temat.
- **Uprawnienia roli `owner`** — bez zmian (`OwnerRoleProvisioner`, migracje).
- **Zmiana treści migracji `2026_09_26_100200_*`** (odczyt szablonów dla `owner`) — zostaje.

## Zmiany dokumentacji

- [ ] `docs/conventions/autoryzacja.md` §1 — `is_admin` jako jedyne źródło prawdy, komenda
      synchronizacji, cofanie ręcznych zmian roli `super_admin` przy wdrożeniu, droga dla węższego
      admina (osobna rola).
- [ ] `docs/operations/obraz-produkcyjny.md` §11 — krok w `deploy.yml`; ręczne `MakeAdmin` potrzebne
      już wyłącznie do założenia pierwszego konta.
- [ ] `README.md` — sekcja o `MakeAdmin`, jeśli zmieni się jej opis.
- [ ] `CLAUDE.md` — sekcja „Architektura" (zdanie o dwóch warstwach autoryzacji) i „Bezpieczeństwo
      w CI", jeśli `deploy.yml` dostaje nowy krok w jobie `deploy` — do potwierdzenia przy
      `/review-task`.
- [ ] `CHANGELOG.md` — wpis przez skill `changelog` (administrator widzi nowe sekcje panelu od razu
      po wdrożeniu).

## Ograniczenia techniczne

- Laravel 13, Filament 5, Shield 4, PHP 8.4; `spatie/laravel-permission` (cache uprawnień do
  wyczyszczenia po synchronizacji).
- Komenda uruchamiana w Cloud Run job — nieinteraktywna, kod wyjścia ≠ 0 przy błędzie, żeby
  `deploy.yml` zatrzymał wdrożenie.
- `shield:generate` z `--option=permissions` — bez generowania polityk (`policies.generate = false`,
  `autoryzacja.md` §3).
- Powierzchnie do przeczytania przed edycją: `autoryzacja.md`, `docs/operations/obraz-produkcyjny.md`.

## Rozstrzygnięcia

Ustalone przy `/review-task`, 24.09.2026:

- **Nazwa komendy: `admins:sync`.** Konwencja `grupa:akcja` Laravela, bez wchodzenia w przestrzeń
  nazw komend Shielda i bez mieszania z zakładaniem konta w `MakeAdmin`.
- **Krok w `deploy.yml`: po migracjach, przed „Deploy service".** Nowa rewizja widzi komplet uprawnień
  od pierwszego żądania, a błąd komendy zatrzymuje wdrożenie przed jej uruchomieniem.
- **Brak kont z `is_admin` — ostrzeżenie, kod 0.** Pierwsze wdrożenie świeżego środowiska nie może
  stanąć na tym, że pierwszego administratora zakłada się dopiero po nim (`MakeAdmin`).
- **Weryfikacja kroku `deploy.yml`: pierwsze wdrożenie na staging z checklistą** — jedyny sposób
  sprawdzenia kroku bez testu automatycznego.

## Powiązane ADR-y

- [ADR-018 — `is_admin` jako jedyne źródło prawdy o super adminie](../adr/ADR-018-is-admin-jako-zrodlo-prawdy-o-super-adminie.md) —
  **Decyzja do wypełnienia przez autora** przed `/implement-task 025`.
