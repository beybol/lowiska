# Lokalne środowisko deweloperskie

Całe środowisko stoi w kontenerach i opisuje je **jeden plik** — `docker-compose.yml`.
Nie ma nakładek (`docker-compose.override.yml` został usunięty w zadaniu 004): efektywna
konfiguracja jest tym, co widać w pliku, bez scalania w tle.

```bash
docker compose up --build
```

Po wstaniu usług:

```bash
docker compose exec app php artisan migrate --seed   # schemat + dane startowe

# Konto administratora — argumenty są WYMAGANE: imię, nazwisko, adres e-mail.
docker compose exec app php artisan MakeAdmin Jan Kowalski jan@example.com
```

⚠️ **Nowemu użytkownikowi komenda ustawia hasło równe adresowi e-mail** (`MakeAdminCommand`), więc
z konta założonego tą drogą korzystaj wyłącznie lokalnie i zmień hasło, zanim gdziekolwiek trafi.
Podanie adresu istniejącego użytkownika nie tworzy konta, tylko **promuje** je do roli Super Admina
(hasło zostaje bez zmian).

---

## 1. Usługi i porty

Porty hosta są przesunięte o **+3000** względem portów kontenerów, żeby środowisko mogło chodzić
równocześnie z bliźniaczymi projektami (WorkSnap +1000, PunktySzczepień +2000).

| Usługa | Host | Kontener | Do czego |
|---|---|---|---|
| `app` | **11000** | 8000 | aplikacja — http://localhost:11000 |
| `vite` | **8173** | 8173 | serwer zasobów front-endu |
| `mysql` | **6306** | 3306 | baza (klient z hosta łączy się tu) |
| `mailpit` | **11025** | 8025 | skrzynka pocztowa — http://localhost:11025 |
| `mailpit` | **4025** | 1025 | SMTP dla aplikacji |

Usługi `queue` i `scheduler` nie wystawiają portów. Wszystkie usługi widzą się **po nazwach**
w domyślnej sieci Compose'a — stąd `DB_HOST=mysql` i `MAIL_HOST=mailpit` w `.env`.

### Usługi na profilach — nie startują domyślnie

`docker compose up` stawia **cztery** usługi: `app`, `queue`, `mysql`, `mailpit`. `vite`
i `scheduler` mają profile i uruchamia się je tylko wtedy, gdy są potrzebne — obie zjadały procesor
bez przerwy (patrz sekcja 7).

```bash
docker compose --profile scheduler up -d scheduler   # przy pracy nad zadaniami cyklicznymi
```

Przełączanie trybu pracy nad stylami opisuje **sekcja 5**.

⚠️ **`docker compose down` nie zatrzymuje usług profilowanych** — trzeba wymienić profile:

```bash
docker compose --profile vite --profile scheduler down
```

⚠️ **Port Vite musi być po obu stronach ten sam (8173).** Adres pochodzenia wstrzykiwany na stronę
bierze się z konfiguracji serwera Vite, nie z mapowania Compose'a — przy różnych portach
przeglądarka pukałaby pod adres, którego nikt nie słucha.

---

## 2. Wolumeny

| Wolumen | Zawartość | Dlaczego nazwany |
|---|---|---|
| `dbdata` | dane MySQL-a | przeżywa `docker compose down` |
| `vendor` | zależności PHP | ⚠️ tysiące plików czytanych przy każdym żądaniu |
| `node_modules` | zależności front-endu | jw. |

⚠️ **`vendor` i `node_modules` nie mogą leżeć na powiązaniu z dysku Windows.** Powiązania
katalogów w Docker Desktopie są wolne, a te dwa katalogi czyta się w całości przy każdym żądaniu —
na powiązaniu środowisko staje się bezużyteczne. Nie wracaj do montowania ich z hosta.

Usługa `vite` montuje `vendor` **tylko do odczytu**: `tailwind.config.js` ma w `content` ścieżkę
`./vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php`, więc bez tego
katalogu klasy paginacji wypadłyby z gotowego arkusza stylów.

⚠️ **Pierwsze uruchomienie trwa dłużej**, bo oba wolumeny startują puste: `dev-entrypoint` wykonuje
`composer install` w usłudze `app`, a usługa `vite` ma w poleceniu `npm install && npm run dev`.
Kolejne starty korzystają z zawartości wolumenów.

---

## 3. Baza danych

Obraz `mysql:8.4`, dwa schematy:

- **`lowiska`** — baza robocza, tworzona przez `MYSQL_DATABASE`;
- **`lowiska_test`** — baza pakietu testów, tworzona skryptem `docker/mysql/initdb/01-test-schema.sql`.

Konto aplikacji to `lowiska` / `lowiska` (konto `root` ma hasło `root`). Klientem z hosta łączysz się
na porcie **6306**:

```bash
mysql -h 127.0.0.1 -P 6306 -ulowiska -plowiska lowiska
docker compose exec mysql mysql -ulowiska -plowiska lowiska   # albo bez klienta na hoście
```

Skrypt startowy jest konieczny, bo obraz MySQL-a nadaje uprawnienia **wyłącznie** do schematu
z `MYSQL_DATABASE` — bez niego konto aplikacji nie zobaczyłoby drugiej bazy.

`app`, `queue` i `scheduler` czekają na bazę przez `depends_on: condition: service_healthy`
(kontrola stanu to `mysqladmin ping`).

### ⚠️ Skrypty startowe wykonują się tylko raz

Zawartość `docker-entrypoint-initdb.d` MySQL wykonuje **tylko przy tworzeniu pustego katalogu
danych**. Jeśli wolumen `dbdata` już istnieje, dodany albo zmieniony skrypt nie zadziała — trzeba
wykonać jego treść ręcznie:

```bash
docker compose exec mysql mysql -uroot -proot -e "
  CREATE DATABASE IF NOT EXISTS lowiska_test
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  GRANT ALL PRIVILEGES ON lowiska_test.* TO 'lowiska'@'%';
  GRANT ALL PRIVILEGES ON \`lowiska\\_test\\_%\`.* TO 'lowiska'@'%';
  FLUSH PRIVILEGES;
"
```

Alternatywa — skasowanie wolumenu i odtworzenie stanu od zera:

```bash
docker compose down -v
docker compose up --build
docker compose exec app php artisan migrate --seed
docker compose exec app php artisan MakeAdmin Jan Kowalski jan@example.com
```

⚠️ `docker compose down -v` **kasuje dane robocze**. Zgodnie z `CLAUDE.md` operacje czyszczące bazę
wykonuje się wyłącznie po wyraźnej zgodzie.

Grant na `lowiska\_test\_%` jest nadawany z góry, choć zrównoleglenie testów jest jeszcze poza
zakresem — dołożenie go później wymagałoby ręcznego wejścia do bazy w każdym istniejącym środowisku.
Backslash escapuje tu `_`, które w tym wzorcu jest inaczej znakiem „dowolny znak".

Sprawdzenie, czy uprawnienia są na miejscu:

```bash
docker compose exec mysql mysql -ulowiska -plowiska -e "SHOW GRANTS FOR CURRENT_USER();"
```

⚠️ `SHOW GRANTS` wyświetla ten wzorzec jako ``lowiska\\_test\\_%`` — **podwojony backslash to tylko
sposób wyświetlania**, nie błąd w skrypcie. Jeśli chcesz się upewnić, że wzorzec działa, utwórz
tymczasowo bazę `lowiska_test_1` i spróbuj się do niej odwołać kontem `lowiska`.

---

## 4. Izolacja pakietu testów — pięć warstw

Testy biegną na **MySQL-u, w schemacie `lowiska_test`** ([ADR-001](../adr/ADR-001-silnik-bazy-w-pakiecie-testow.md)).
Ponieważ `tests/Pest.php` dokłada `RefreshDatabase` całemu katalogowi `Feature`, **każdy** test
funkcjonalny czyści bazę, do której akurat wskazuje połączenie. Stąd pięć warstw — żadna nie jest
ozdobna:

1. **`phpunit.xml`** — komplet zmiennych `DB_*` z `force="true"`, w tym **`DB_URL` wymuszony
   pusty** (bez tego `DATABASE_URL` ze środowiska przesłania host i schemat).
2. **`docker-compose.yml`** — usługi `app`, `queue` i `scheduler` **nie dostają żadnych zmiennych
   `DB_*`** ani `env_file`. Powód: zmienne z listy `environment:` trafiają do `$_SERVER`,
   a `force="true"` zapisuje wyłącznie `putenv()`/`$_ENV` — czyli w kontenerze deklaracja
   z `phpunit.xml` przegrywałaby z Compose'em.
3. **`tests/TestCase.php`** — bramka w `createApplication()` sprawdzająca **rozwiązane** połączenie
   (nie same zmienne). Niezgodność przerywa **cały** pakiet przez `exit(1)`; nieudana asercja
   przerwałaby tylko jeden test i wpuściła następny na złą bazę.
4. **`tests/Unit/PhpunitConfigInvariantTest.php`** — czerwienieje, gdy ktoś zdejmie `force="true"`
   z dowolnej zmiennej `DB_*` albo zmieni schemat testowy. Dziedziczy po klasie bazowej PHPUnit,
   nie po `Tests\TestCase`, żeby działać także przy zepsutej konfiguracji.
5. **Uprawnienia w bazie** — skrypt startowy MySQL-a (sekcja 3).

Komenda testów:

```bash
docker compose exec app php artisan test
docker compose exec app php artisan test --filter="NazwaKlasy"
```

⚠️ **`test.sh` już nie istnieje.** Był jedyną realnie działającą ochroną w poprzednim układzie
(`export DB_DATABASE=test`) i został usunięty dopiero razem z kompletem warstw wyżej. Nie
przywracaj go — pięć warstw zastępuje go z nawiązką, a jeden `export` w skrypcie tworzył złudzenie,
że reszta układu jest bezpieczna.

---

## 5. Front-end — dwa tryby pracy

Środowisko chodzi w jednym z dwóch trybów. **Domyślny jest tryb A** — Vite nie startuje.

| | **A. Zasoby zbudowane** (domyślny) | **B. Serwer zasobów** (praca nad CSS/JS) |
|---|---|---|
| Skąd style i skrypty | `public/build` | serwer Vite na `localhost:8173` |
| Przeładowanie po zmianie | trzeba przebudować | natychmiast (HMR) |
| Koszt na biegu jałowym | zerowy | ~44% rdzenia (odpytywanie plików) |
| Kiedy | **codzienna praca nad PHP**, testy, panele | edycja `resources/css/**`, `resources/js/**` |

### Wejście w tryb B — praca nad stylami

```bash
docker compose --profile vite up -d vite
docker compose logs -f vite          # poczekaj na „VITE ready"
```

Od tej chwili Vite tworzy `public/hot`, a Laravel serwuje zasoby z serwera deweloperskiego —
zmiana w arkuszu jest widoczna bez przebudowywania.

### Powrót do trybu A — koniec pracy nad stylami

⚠️ **Trzy kroki, żadnego nie pomijaj.**

```bash
docker compose exec app npm run build              # 1. zbuduj zasoby
docker compose --profile vite rm -sf vite          # 2. zatrzymaj i usuń usługę
rm -f public/hot                                   # 3. usuń znacznik serwera
```

Krok 3 jest tym, o którym najłatwiej zapomnieć: **dopóki `public/hot` istnieje, Laravel szuka
serwera Vite**, którego już nie ma — strona ładuje się wtedy całkiem bez stylów. `rm -sf` w kroku 2
zatrzymuje kontener i usuwa go za jednym razem (`-s` = stop), ale pliku `hot` nie sprząta.

⚠️ **Wynik kroku 1 wchodzi do commita.** Katalog `public/build` jest wersjonowany (patrz niżej),
więc po pracy nad stylami przebudowane pliki trzeba dołożyć do zmian — inaczej reszta zespołu
i obraz produkcyjny zobaczą stary arkusz.

### `public/build` jest wersjonowany

Zbudowane zasoby **leżą w repozytorium** — tak samo jak w PunktachSzczepień. Powód: serwer Vite nie
startuje domyślnie, więc bez nich świeży klon wstałby bez styli, a `docker compose up` przestałby
być jedynym potrzebnym poleceniem.

Konsekwencja, o której trzeba pamiętać: **zmiana w `resources/css/**` lub `resources/js/**` jest
kompletna dopiero po przebudowaniu i zacommitowaniu wyniku.**

```bash
docker compose exec app npm run build
git add public/build
```

⚠️ `public/hot` pozostaje ignorowany i **nigdy** nie wchodzi do repozytorium — to znacznik
uruchomionego serwera deweloperskiego, wskazujący `localhost:8173`. Zacommitowany zepsułby
aplikację każdemu, kto nie ma uruchomionego Vite.

Obrazu produkcyjnego to nie dotyczy: cel `prod` buduje zasoby samodzielnie w etapie `assets`,
a `.dockerignore` wyklucza `public/build` z kontekstu budowania — do obrazu trafia zawsze świeży
build, nie kopia z repozytorium.

### Skąd wiem, w którym trybie jestem

```bash
ls public/hot 2>/dev/null && echo "tryb B (Vite)" || echo "tryb A (zbudowane)"
docker compose ps --services   # czy `vite` jest na liście
```

### Ustawienia wymuszone przez środowisko

`vite.config.js` niesie dwa ustawienia, których nie zmieniaj bez pomiaru:

- **`hmr.host = 'localhost'`** — przeglądarka łączy się z hosta, a `0.0.0.0` jest w niej blokowane;
- **`watch.usePolling = true`** z wykluczeniem `vendor`, `node_modules`, `storage`,
  `bootstrap/cache`, `.git` i `public/build`.

⚠️ Powiązanie katalogu z Windows **nie przekazuje zdarzeń systemu plików**, więc bez odpytywania
przeładowanie nie działa. Bez wykluczeń odpytywanie całego drzewa zatyka serwer — obie rzeczy naraz,
nie jedna z nich.

⚠️ **Front-end jest w stanie mieszanym:** `package.json` ma jednocześnie `tailwindcss` 3
i `@tailwindcss/vite` 4, a arkusz używa składni v3 (`@tailwind base`) przez PostCSS. Zadanie 004
świadomie tego nie porządkowało. Jeśli budowanie zasobów zacznie się sypać — to jest pierwsze
miejsce do sprawdzenia.

---

## 6. Poczta

Cała poczta deweloperska idzie do Mailpita (`MAIL_HOST=mailpit`, `MAIL_PORT=1025`). Skrzynka:
**http://localhost:11025**.

Aplikacja wysyła maile weryfikacyjne i resetu hasła (Breeze), a odnośniki w nich muszą być
klikalne — dlatego `APP_URL` wskazuje **port hosta** (`http://localhost:11000`), nie port
kontenera.

---

## 7. Wydajność — co już zrobiono i gdzie jest sufit

Kod leży na **powiązaniu katalogu z dysku Windows**, a każdy `stat` przez tę warstwę kosztuje
wielokrotnie więcej niż na systemie plików kontenera. Zmierzone na tym projekcie:

| Operacja | Powiązanie z Windows (`app/`) | Wolumen nazwany (`vendor/`) |
|---|---|---|
| `stat` 109 plików PHP | **~2700 ms** (~25 ms/plik) | ~700 ms (~6,5 ms/plik) |

Stąd trzy ustawienia, których **nie wolno cofnąć**:

- **`opcache.revalidate_freq = 2`** (`docker/php/dev.ini`) — przy `0` OPcache sprawdza znaczniki
  czasu przy **każdym** żądaniu; zmierzone: ~4 s na żądanie zamiast ~0,03 s.
- **`interval: 1000`** w `vite.config.js` — przy 300 ms samo odpytywanie zjadało ~44% rdzenia.
- **profile na `vite` i `scheduler`** — patrz sekcja 1.

⚠️ **Czasy odpowiedzi pozostaną nierówne** (od ~0,02 s przy gęstych żądaniach do kilku sekund, gdy
minie okno rewalidacji). To sufit tego układu, nie błąd konfiguracji: `revalidate_freq` przesuwa
koszt w czasie, ale go nie usuwa. Trwałe rozwiązanie to przeniesienie repozytorium na system plików
Linuksa (WSL2, `\\wsl$\...`), gdzie powiązanie katalogu przestaje przechodzić przez most
Windows↔Linux.

---

## 8. Obraz produkcyjny

Cel `prod` z tego samego `Dockerfile` opisuje [`obraz-produkcyjny.md`](obraz-produkcyjny.md).
