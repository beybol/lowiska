# Łowiska

Aplikacja Laravel 12 z dwoma panelami Filament 3.3, obsługująca katalog i zarządzanie łowiskami
wędkarskimi. Adresowana do dwóch grup: **właścicieli i operatorów** łowisk komercyjnych (zarządzanie
obiektem, sprzedaż pozwoleń online) oraz **wędkarzy** (wykupienie pozwolenia, rezerwacja stanowiska).

> Aplikacja jest w fazie tworzenia i nie działa jeszcze produkcyjnie.

## Uruchomienie

Całe środowisko stoi w kontenerach — wymagany jest wyłącznie Docker.

```bash
docker compose up --build
docker compose exec app php artisan migrate --seed   # schemat + dane startowe

# Konto administratora — imię, nazwisko i adres e-mail są wymagane.
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
