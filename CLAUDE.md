# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

Ten plik jest **konstytucją projektu** — obowiązuje w każdej sesji, niezależnie od tematu zadania.
Reguły związane z konkretną powierzchnią aplikacji żyją w [`docs/conventions/`](docs/conventions/)
i wczytuje się je na żądanie — patrz sekcja „Konwencje powierzchni" niżej. **To nie jest materiał
opcjonalny: wejście w powierzchnię bez przeczytania jej pliku jest błędem procesu.**

> ℹ️ **Workflow tego repozytorium został przeniesiony z bliźniaczego projektu.** Część
> proceduralna (komendy, tiery testów, kryterium ADR, struktura dokumentacji) jest kompletna
> i obowiązuje od razu. Część opisująca **samą aplikację** została odtworzona z kodu i uzupełniona ręcznie.

## Co to jest

**Łowiska** — aplikacja Laravel 12 z dwoma panelami Filament 3.3, obsługująca katalog i zarządzanie
łowiskami wędkarskimi. Model domenowy widoczny w kodzie: `Fishery` (łowisko) z `Position`
(stanowiska), `FisheryType`, `Convenience`, `AdditionalService`, `Fish`, `FishingMethod`,
`LongTermPermit` (zezwolenia długoterminowe), `Company` (podmiot gospodarczy) oraz słowniki
`Country`/`State`/`Currency`.

**Kontekst produktowy:** Aplikacja jest adresowana do dwóch grup użytkowników:
Właścicieli/operatorów łowisk komercyjnych oraz wędkarzy. Operatorzy, dzięki aplikacji, mogą zarządzać łowiskami i sprzedawać online pozwolenia dla wędkarzy.
Wędkarze mogą wygodnie wykupić sobie pozwolenie na łowienie i zarezerwować miejsce nad łowiskiem.
Aplikacja jest w fazie tworzenia i nie działa jeszcze produkcyjnie.

## Język — ważna konwencja

- **Dokumentacja** (`docs/`, ADR-y, zadania, `CHANGELOG.md`, opisy commitów, rozmowa z użytkownikiem)
  — **po polsku**.
- **Kod** (klasy, zmienne, tabele, kolumny, migracje, trasy) oraz **UI aplikacji** — **po angielsku**.
- **Aplikacja** od samego początku musi być wielojęzyczna i na start oferuje język polski i angielski.

## Architektura — big picture

Jedna aplikacja Laravel, **dwa panele Filament**:

- **`/admin`** ([`AdminPanelProvider`](app/Providers/Filament/AdminPanelProvider.php)) — panel
  administracyjny; trzynaście zasobów w [`app/Filament/Resources/`](app/Filament/Resources/)
  pokrywających cały model domenowy i słowniki.
- **`/owner`** ([`OwnerPanelProvider`](app/Providers/Filament/OwnerPanelProvider.php)) — panel
  właściciela łowiska; strony w [`app/Filament/Owner/Pages/`](app/Filament/Owner/Pages/).

**Autoryzacja** stoi na dwóch warstwach naraz i obie trzeba respektować: `bezhansalleh/filament-shield`
(role i uprawnienia, stąd `RolePolicy`) oraz czternaście polityk w [`app/Policies/`](app/Policies/) —
po jednej na model. Pokrycie testowe granic paneli żyje w `tests/Feature/AdminPanelTest.php`
i `tests/Feature/OwnerPanelTest.php`.

**Uwierzytelnianie** — Laravel Breeze (widoki i trasy w `routes/`) plus `laravel/socialite`.
Przełącznik języka: `bezhansalleh/filament-language-switch`.

**Integracje zewnętrzne** — [`CSOService`](app/Services/CSOService.php) odpytuje rejestr GUS przez
`gusapi/gusapi`; [`IbanValidation`](app/Rules/IbanValidation.php) waliduje numery rachunków.

** granica między panelem administratora a panelem właściciela** - panel administratora jest aplikacją dla twórców i właścicieli usługi i służy do zarządzania portalem, rozliczeń z łowiskami tip.
Panel właśiciela to aplikacja dla właściciela/operatora/pracownika łowiska, która pozwala mu konfigurować parametry swojego obiektu w systemi i zarządzać nim (rezerwacjami, cenami itp)

## Konwencje powierzchni — wczytaj przed pracą

Wiedza „jak to jest zrobione i dlaczego akurat tak" żyje w [`docs/conventions/`](docs/conventions/),
pogrupowana po powierzchni aplikacji. Ustal, której powierzchni dotyka zadanie, i **przeczytaj jej plik
przed pierwszą edycją kodu** — te pliki niosą niezmienniki, świadome odstępstwa i pułapki, których nie
da się odtworzyć z samego kodu.

| Dotykasz | Przeczytaj |
|---|---|
| `app/Filament/Resources/**`, `app/Providers/Filament/AdminPanelProvider.php` | `docs/conventions/panel-admina.md` ⛏️ |
| `app/Filament/Owner/**`, `app/Providers/Filament/OwnerPanelProvider.php` | `docs/conventions/panel-wlasciciela.md` ⛏️ |
| `app/Policies/**`, role i uprawnienia Shielda, `User` | `docs/conventions/autoryzacja.md` ⛏️ |
| `app/Services/CSOService.php`, `app/Rules/IbanValidation.php`, `app/Helpers/**` | `docs/conventions/integracje.md` ⛏️ |
| `routes/**`, widoki Breeze, `resources/views/**` | `docs/conventions/strona-publiczna.md` ⛏️ |

**Dokumentacja projektu** - znajduje się w katalogu docs

**Zasady korzystania:**

1. **Zadanie dotykające dwóch powierzchni czyta oba pliki.** Nie zgaduj po analogii z sąsiedniej
   powierzchni — udokumentowane są tam także **świadome rozjazdy** między nimi.
2. **Rozstrzygnięcia w tych plikach wiążą tak samo jak decyzje z ADR-ów** — różnią się miejscem
   zapisu, nie mocą.
3. **Uzasadnienia są w ADR-ach** (`docs/adr/`), przebieg zadania w `docs/tasks/NNN-*.md`. Pliki
   konwencji niosą **niezmiennik obowiązujący dziś**, nie historię.

## Workflow (spec-driven, przez komendy)

Praca toczy się przez pliki zadań i ADR-y, nie ad-hoc. Wszystkie operacje wykonuj **bezpośrednio
w katalogu głównym** — bez git worktree, bez komend git (chyba że użytkownik poprosi).

1. **Zadania** żyją w `docs/tasks/NNN-opis.md` (szablon: [`docs/tasks/_template.md`](docs/tasks/_template.md)),
   numer trzycyfrowy. Zrealizowane przenosi **autor** do `docs/tasks/implemented/` — nie robi tego
   żadna komenda.
2. **`/create-task <temat>`** — zakłada plik zadania: kolejny wolny numer, szkielet z szablonu, krótki
   wywiad (problem, wymagania, wyłączenia) i **dobór zakresu testów** (niżej). Nie ocenia kompletności
   i **nie tworzy ADR-ów** — po utworzeniu przekazuje do `/review-task`.
3. **`/review-task <numer>`** — ocenia kompletność zadania i wykrywa decyzje architektoniczne. Dla każdej
   decyzji **spełniającej kryterium ADR** (niżej) tworzy `docs/adr/ADR-NNN-temat.md` (szablon:
   [`docs/adr/_template.md`](docs/adr/_template.md)) z Kontekstem, Alternatywami i Rekomendacją, ale
   **z pustą sekcją Decyzja** — wypełnia ją autor. Drobne nieścisłości rozstrzyga na miejscu, wpisując
   je do sekcji `## Rozstrzygnięcia` zadania.
4. **`/implement-task <numer>`** — implementuje zadanie. **Zatrzymuje się, jeśli którykolwiek powiązany
   ADR ma pustą sekcję Decyzja.** Respektuje decyzje z ADR-ów, rozstrzygnięcia z zadania oraz **plik
   konwencji powierzchni**, której zadanie dotyka. Uruchamia testy w zadeklarowanym zakresie
   i **raportuje, czego nie uruchomił**.
5. Po zmianach aktualizuj dokumentację wskazaną w sekcji „Zmiany dokumentacji" zadania — w tym **plik
   konwencji powierzchni**, jeśli zadanie ustaliło nowy niezmiennik. Wpis w `CHANGELOG.md` realizuje
   **skill `changelog`** (format keepachangelog 1.0.0 PL, wersjonowanie semantyczne, opis z perspektywy
   użytkownika w czasie przeszłym).
6. **`/review-implementation`** — na koniec sesji: pełny pakiet testów (Krok 1), przegląd wg frameworka,
   testy mutacyjne i security-review. Tu domyka się zakres testów odroczony w zadaniach.

Poza tym: **`/security-audit`** — pełny audyt bezpieczeństwa całego kodu (nie diff), na żądanie.

#### Zakres testów — dobierany do zadania, nie domyślnie pełny

Każde zadanie deklaruje **jeden tier** w sekcji `## Zakres testów` i to on, nie nawyk, ląduje
w kryteriach akceptacji. Powód: pełny pakiet trwa swoje, a im drobniejsze zadania definiujemy, tym
częściej implementacja jest krótsza niż przebieg testów całości.

- **T1 — punktowy**: testy klas nowych i zmienionych (`./test.sh --filter="NazwaKlasy"`). Domyślny dla
  zmian treści, copy, CSS, pojedynczej metody. Pusty **wyłącznie** wtedy, gdy zmiana nie dotyka kodu
  wykonywalnego (sama `docs/**`) — zmiana widoku Blade, klucza tłumaczenia, trasy albo pliku
  konfiguracyjnego **nie jest** pusta.
- **T2 — zależności**: T1 + testy klas i procesów, które z dotkniętych korzystają (wołający, test
  funkcjonalny przepływu). Domyślny, gdy zmiana rusza kontrakt używany gdzie indziej.
- **T3 — pełny pakiet** (`./test.sh`): fundamenty i wszystko, co leży na każdej ścieżce.

**T3 jest obowiązkowy, gdy zmiana dotyka czegokolwiek z tej listy:**

- `bootstrap/app.php` — w Laravelu 12 mieszkają tu middleware, routing i obsługa wyjątków
- `User`, role i uprawnienia Shielda, dowolna klasa w `app/Policies/`
- providery paneli (`AdminPanelProvider`, `OwnerPanelProvider`)
- `phpunit.xml`, `tests/TestCase.php`, `tests/Pest.php`, `test.sh`
- migracje, `composer.json`, `Dockerfile.dev`/`Dockerfile.prod`

⚠️ **Tier niższy niż T3 to odroczenie, nie zwolnienie** — pełny pakiet nadal ma się wykonać, tylko
**na koniec sesji** (Krok 1 `/review-implementation` albo ręcznie). Dlatego `/implement-task` **zawsze**
raportuje, co uruchomił **i czego nie**; brak tej informacji = podsumowanie niekompletne.

#### ADR czy rozstrzygnięcie w treści zadania

ADR powstaje, gdy spełnione są **wszystkie trzy** warunki: (1) **zasięg** — rozstrzygnięcie wiąże kod
poza tym zadaniem; (2) **koszt odwrócenia** — zmiana po fakcie wymaga migracji danych, przepisania wielu
miejsc albo złamania niezmiennika; (3) **uzasadnienie warte zapamiętania** — za rok ktoś zapyta
„dlaczego tak", a odpowiedź nie wynika z samego kodu.

⚠️ **„Więcej niż jedna sensowna opcja" NIE jest kryterium ADR** — spełnia je prawie każda decyzja.
Anti-sygnały → **rozstrzygnij w treści zadania** (sekcja `## Rozstrzygnięcia`), nie ADR-em: wygląd,
copy, ikona, kolejność w menu, wartość progu, nazwa pola/trasy/kolumny, umiejscowienie jednego
przycisku, wybór między dwoma równoważnymi zapisami tego samego wzorca — wszystko, co odwracasz jedną
linijką.

**Rozstrzygnięcia wiążą `/implement-task` dokładnie tak samo jak decyzje z ADR-ów** — różnią się
wyłącznie zasięgiem i miejscem zapisu, nie mocą.

#### Gdzie ląduje wiedza z zakończonego zadania

Żeby ten plik nie odrósł do rozmiaru, w którym reguły zawsze-obowiązujące toną w sytuacyjnych:

- **Nowy niezmiennik powierzchni → plik w `docs/conventions/`**, nie do `CLAUDE.md`. Do `CLAUDE.md`
  trafia wyłącznie to, co obowiązuje **niezależnie od tematu zadania** (workflow, tiery, model danych,
  bezpieczeństwo, komendy).
- **Wpis opisuje stan obowiązujący, nie przebieg zadania.** Historia („najpierw zrobiliśmy X, pękło,
  potem Y"), pomiary i archeologia zostają w `docs/tasks/NNN-*.md`; do konwencji wchodzi reguła
  + jednozdaniowe „nie wracaj do X", jeśli powrót jest realnym ryzykiem.
- **Reguła unieważniona jest PRZEPISYWANA, nie dopisywana obok korekty.** Nie zostawiaj w pliku wersji
  błędnej razem z „⚠️ nieaktualne od zadania NNN" — czytelnik musi wtedy przyswoić obie. Odwrócenie
  decyzji odnotowuj w ADR-ze (sekcja „Aktualizacja") i w zadaniu, nie w konwencjach.
- **Uzasadnienie zostaje w ADR-ze.** Konwencje niosą niezmiennik + odsyłacz; nie streszczaj ADR-a
  w całości.
- **Miękki limit ~40 KB na plik konwencji** — po przekroczeniu skonsoliduj albo podziel wzdłuż granicy
  tematycznej, zamiast dopisywać kolejną sekcję.

#### Gdzie żyje reszta dokumentacji

- **Dokumentacja operacyjna** — [`docs/operations/`](docs/operations/); dziś
  [`docker.md`](docs/operations/docker.md) (układ kontenerów, baza, uruchamianie).
- **Decyzje architektoniczne** — [`docs/adr/`](docs/adr/).
- **Zadania** — [`docs/tasks/`](docs/tasks/), zrealizowane w `docs/tasks/implemented/`.

## Komendy

Środowisko deweloperskie jest skonteneryzowane; host to Windows + PowerShell (podstawowy) lub Bash tool.
Szczegóły: [`docs/operations/docker.md`](docs/operations/docker.md).

```bash
docker compose up --build                      # app (Apache, host 8000) + queue + scheduler
docker compose exec app php artisan migrate    # migracje
docker compose exec app vendor/bin/pint        # formatowanie/lint — przed zakończeniem zadania
./test.sh                                      # pełny pakiet testów (Pest)
./test.sh --filter="NazwaKlasy"                # pojedyncza klasa / metoda
docker build --target prod -f Dockerfile.prod -t lowiska:prod .   # obraz produkcyjny
```

**Testy** biegną na **Pest 3**. `tests/Pest.php` rozszerza `Tests\TestCase` i dokłada `RefreshDatabase`
całemu katalogowi `Feature`, więc **każdy** test funkcjonalny czyści bazę, do której akurat wskazuje
połączenie.

## Bezpieczeństwo bazy danych — twarda zasada

⚠️ **Nigdy nie uruchamiaj `php artisan migrate:fresh` (ani `migrate:refresh`, `db:wipe`,
`migrate:fresh --seed`) bez wcześniejszego wprost zapytania użytkownika i uzyskania zgody** —
niezależnie od flag typu `--env=testing` czy `--database=…` i niezależnie od tego, jak bardzo kontekst
wygląda na bezpieczny. `--env=testing` **nie gwarantuje** trafienia w izolowaną bazę: przy braku pliku
`.env.testing` Laravel po cichu wraca do zwykłego `.env`, czyli do bazy deweloperskiej.

⛏️ **Znany słaby punkt izolacji testów — do naprawy osobnym zadaniem, nie przy okazji.** Dziś stan
jest taki:

- `phpunit.xml` deklaruje `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:`, ale **żaden wpis nie ma
  `force="true"`**, więc prawdziwa zmienna środowiskowa go bije.
- `docker-compose.yml` wstrzykuje `DB_CONNECTION`/`DB_DATABASE` przez listę `environment:`, czyli do
  `$_SERVER` — a `force="true"` i tak zapisuje wyłącznie `putenv()`/`$_ENV`. W kontenerze deklaracja
  z `phpunit.xml` nie ma więc szans wygrać nawet po dopisaniu `force`.
- `test.sh` eksportuje `DB_DATABASE=test` — i to jest jedyna warstwa, która realnie działa, bo `export`
  trafia do `$_SERVER`.

Wniosek praktyczny na dziś: **uruchamiaj testy wyłącznie przez `./test.sh`** i nie zakładaj, że
`php artisan test` trafi w bazę testową. Bliźniaczy projekt PunktySzczepień ma udokumentowaną historię
wyczyszczenia realnej bazy dokładnie tym mechanizmem.

## Konwencje kodu

- Kod i UI po angielsku; dokumentacja po polsku (patrz wyżej).
- **Autoryzacja idzie przez polityki i Shielda, nie przez warunki w widoku ani w zasobie.** Nowy model
  dostaje własną politykę; zasób Filamenta nie jest miejscem na regułę dostępu.
- **Każde ID i każda właściwość publiczna komponentu to dane od klienta.** Akcje na rekordach:
  pobranie z zakresem widoczności + jawna autoryzacja. Nigdy generyczny setter przyjmujący nazwę
  kolumny od klienta.
- **Logika obliczeniowa i walidacyjna ma jeden dom** — reguła idzie do `app/Rules/`, usługa do
  `app/Services/`, a nie do zasobu Filamenta czy kontrolera. Drugi literał tej samej stałej to defekt.
- ⚠️ **Eager-load wszędzie, gdzie renderujesz relacje po wielu wierszach** — tabele Filamenta nad
  zasobami z relacjami to najczęstsze źródło N+1 w projektach o tym kształcie.
- Nowa decyzja architektoniczna (wybór biblioteki/wzorca) → ADR wg kryterium wyżej, nie milcząco w kodzie.

⛏️ **Do uzupełnienia:** konwencje wynikające z realnych decyzji tego projektu — nazewnictwo, granica
między `Helper` a usługą, sposób obsługi słowników (`Country`/`State`/`Currency`), reguła widoczności
danych właściciela. Każda z nich powinna trafić do właściwego pliku w `docs/conventions/`, nie tutaj.
