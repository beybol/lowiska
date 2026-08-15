# Łowiska

Aplikacja Laravel 13 z dwoma panelami Filament 5, obsługująca katalog i zarządzanie łowiskami
wędkarskimi. Adresowana do dwóch grup: **właścicieli i operatorów** łowisk komercyjnych (zarządzanie
obiektem, sprzedaż pozwoleń online) oraz **wędkarzy** (wykupienie pozwolenia, rezerwacja stanowiska).

> Aplikacja jest w fazie tworzenia i nie działa jeszcze produkcyjnie.

## Uruchomienie

Całe środowisko stoi w kontenerach — wymagany jest wyłącznie Docker.

```bash
docker compose up --build
docker compose exec app php artisan migrate --seed   # schemat + dane startowe

# Konto administratora — imię, nazwisko i adres e-mail są wymagane.
# Komenda od razu generuje i nadaje komplet uprawnień Shielda, nie tylko flagę is_admin —
# uruchomiona ponownie na tym samym e-mailu jest bezpieczna (dopisze tylko brakujące uprawnienia).
# ⚠️ Nowemu użytkownikowi hasło jest ustawiane na jego adres e-mail.
docker compose exec app php artisan MakeAdmin Jan Kowalski jan@example.com
```

To wszystko — zbudowane zasoby front-endu są w repozytorium, więc aplikacja od razu ma style.

## Praca nad stylami

Domyślnie serwer zasobów **nie działa** — Laravel serwuje zbudowane pliki z `public/build`.
Odpytywanie plików przez most Windows↔Linux kosztuje ~44% rdzenia bez przerwy, więc Vite włącza się
tylko na czas pracy nad CSS-em i JavaScriptem:

```bash
# włącz przeładowanie na żywo
docker compose --profile vite up -d vite

# wyłącz — wszystkie trzy kroki
docker compose exec app npm run build
docker compose --profile vite rm -sf vite
rm -f public/hot
```

⚠️ Nie pomijaj `rm -f public/hot` — dopóki ten plik istnieje, Laravel szuka serwera Vite, którego
już nie ma, i strona ładuje się bez stylów.

⚠️ **`public/build` jest wersjonowany**, więc przebudowane zasoby dołóż do commita
(`git add public/build`) — inaczej reszta zespołu zobaczy stary arkusz. Szczegóły:
[`docs/operations/docker.md`](docs/operations/docker.md), sekcja 5.

## Adresy

| Co | Gdzie |
|---|---|
| Aplikacja | http://localhost:11000 |
| Panel administratora | http://localhost:11000/admin |
| Panel właściciela | http://localhost:11000/owner |
| Skrzynka pocztowa (Mailpit) | http://localhost:11025 |
| Serwer zasobów (Vite) | http://localhost:8173 |
| Baza (MySQL 8.4) | `localhost:6306`, schemat `lowiska` |

Porty hosta są przesunięte o +3000 względem portów kontenerów, żeby środowisko mogło chodzić
równocześnie z bliźniaczymi projektami.

## Uploady

Mapy i galerie łowisk lokalnie zapisują się na dysku `public` (`FILESYSTEM_DISK=public`) —
zero dodatkowej konfiguracji. Na Cloud Run przełącza się na bucket GCS:

```bash
FILESYSTEM_DISK=gcs
FILAMENT_FILESYSTEM_DISK=gcs
GOOGLE_CLOUD_STORAGE_BUCKET=<terraform -chdir=environments/{staging,prod} output lowiska → storage_bucket>
```

Szczegóły: [`docs/operations/obraz-produkcyjny.md`](docs/operations/obraz-produkcyjny.md), sekcja 9.

## Testy

```bash
docker compose exec app php artisan test
docker compose exec app php artisan test --filter="NazwaKlasy"
```

Pakiet biegnie na MySQL-u w osobnym schemacie `lowiska_test`, chronionym pięcioma warstwami
izolacji — szczegóły w [`docs/operations/docker.md`](docs/operations/docker.md).

## Formatowanie

```bash
docker compose exec app vendor/bin/pint
```

## Bezpieczeństwo

Wdrożenie jest bramkowane w CI: job `security` (SCA + analiza statyczna + skan sekretów) musi
przejść, zanim `deploy` w ogóle wystartuje. Lokalnie warto raz włączyć hook, który skanuje
zakolejkowane zmiany przed commitem:

```bash
git config core.hooksPath .githooks
```

Hook wymaga [gitleaks](https://github.com/gitleaks/gitleaks) w `PATH`; bez niego **przepuszcza**
commit (twardą bramką jest CI, nie hook). Analiza statyczna lokalnie:

```bash
docker compose exec app vendor/bin/phpstan analyse
```

Szczegóły trzech warstw bramki:
[`docs/operations/obraz-produkcyjny.md`](docs/operations/obraz-produkcyjny.md), sekcja 10.

## Dokumentacja

| Temat | Gdzie |
|---|---|
| Środowisko deweloperskie, porty, baza, izolacja testów | [`docs/operations/docker.md`](docs/operations/docker.md) |
| Obraz produkcyjny (FrankenPHP, Cloud Run) | [`docs/operations/obraz-produkcyjny.md`](docs/operations/obraz-produkcyjny.md) |
| Decyzje architektoniczne | [`docs/adr/`](docs/adr/) |
| Niezmienniki powierzchni aplikacji | [`docs/conventions/`](docs/conventions/) |
| Zadania | [`docs/tasks/`](docs/tasks/) |
| Zasady pracy nad projektem | [`CLAUDE.md`](CLAUDE.md) |

Dokumentacja projektu jest po polsku; kod i interfejs aplikacji — po angielsku.
