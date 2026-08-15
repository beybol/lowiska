# Obraz produkcyjny

Cel `prod` w `Dockerfile` buduje obraz zdolny działać na **Cloud Run**. Serwerem jest **FrankenPHP
w trybie classic** — decyzja i alternatywy w [ADR-002](../adr/ADR-002-frankenphp-jako-serwer-obrazu-produkcyjnego.md).

⚠️ **Ten dokument opisuje obraz, nie wdrożenie.** Potok wdrożeniowy, konfiguracja projektu w chmurze
i sposób wykonywania kolejek na tej platformie (zadanie 003) są poza zakresem zadania 004.

---

## 1. Budowanie i uruchomienie lokalne

```bash
docker build --target prod -t lowiska:prod .
docker run --rm -e PORT=8080 -p 8080:8080 lowiska:prod
curl http://localhost:8080/up
```

Obraz nie ma własnego pliku Compose — `docker-compose.prod.yml` został usunięty w zadaniu 004.
Powód: opisywał uruchomienie produkcji na Apache'u przez lokalny Compose, a po przejściu na Cloud
Run jedynym potrzebnym sprawdzeniem jest `docker run` powyżej.

---

## 2. Etapy budowania

Jeden wieloetapowy `Dockerfile` obsługuje oba środowiska:

```
base ──┬─→ vendor ──┐
       │            ├─→ prod
       ├─→ assets ──┘
       └─→ dev
```

| Etap | Rola |
|---|---|
| `base` | PHP 8.3 + rozszerzenia (`pdo_mysql`, `mbstring`, `exif`, `pcntl`, `bcmath`, `gd`, `zip`, `intl`, **`soap`**) + Composer |
| `vendor` | `composer install --no-dev --optimize-autoloader` |
| `assets` | Node 22, `npm ci`, `npm run build` |
| `dev` | `base` + `pcov` + Node + klient MySQL-a; kod wchodzi **powiązaniem katalogu** |
| `prod` | FrankenPHP + `tini`; zasoby i zależności **kopiowane z etapów**, nie budowane tutaj |

`soap` jest wymagany przez `gusapi/gusapi` (`CSOService`) — bez niego wyszukiwanie firmy po NIP-ie
przestaje działać. `pcov` żyje wyłącznie w celu `dev`, bo bez sterownika pokrycia nie zadziała etap
testów mutacyjnych z `/review-implementation`.

⚠️ Zasoby front-endu i zależności PHP **nie są budowane w warstwie produkcyjnej**. Poprzedni
`Dockerfile.prod` robił `npm run build || true` — nieudane budowanie zasobów przechodziło po cichu,
a obraz i tak powstawał. Nie wracaj do tego wzorca.

---

## 3. Czego wymaga Cloud Run

| Wymaganie | Jak jest spełnione |
|---|---|
| Nasłuch na wstrzykiwanym `$PORT` | `prod-entrypoint.sh` ustawia `SERVER_NAME=":$PORT"`, `Caddyfile` czyta `{$SERVER_NAME}` |
| Jeden proces | FrankenPHP w trybie classic — serwer i PHP w jednym procesie |
| Czysta obsługa `SIGTERM` | `tini` jako `PID 1`; platforma wysyła ten sygnał przy skalowaniu w dół |
| Kontrola stanu | trasa `/up` (`bootstrap/app.php`, `health: '/up'`) |

⚠️ **Port nie może być wpisany na sztywno.** Poprzedni obraz miał `EXPOSE 80`
i `<VirtualHost *:80>` — na Cloud Run nie wystartowałby.

---

## 4. Entrypoint i pamięć podręczna konfiguracji

`docker/prod-entrypoint.sh` wykonuje przy starcie:

```
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

⚠️ **`config:cache` unieszkodliwia `env()` poza katalogiem `config/`** — po zbudowaniu pamięci
podręcznej framework przestaje ładować `.env`, a `env()` wywołane z `app/` zwraca `null`, **bez
żadnego błędu przy starcie**. Cała konfiguracja musi być czytana przez `config()`; niezmiennik
opisuje [`docs/conventions/integracje.md`](../conventions/integracje.md), a pilnuje go test
`tests/Unit/NoEnvInAppTest.php` (zadanie 001).

---

## 5. Ustawienia PHP

`docker/php/prod.ini` — OPcache z **`validate_timestamps=0`** (kod jest wpieczony w obraz i nie
zmienia się w trakcie życia instancji, więc sprawdzanie znaczników czasu byłoby czystym kosztem),
`expose_php=Off`, `memory_limit=256M`.

Odpowiednik deweloperski (`docker/php/dev.ini`) ma odwrotne ustawienia: `validate_timestamps=1`,
`revalidate_freq=0` i `enable_cli=1` — `artisan serve` działa na interfejsie wiersza poleceń, więc
bez `enable_cli` OPcache w ogóle nie obejmowałby serwowanej aplikacji.

---

## 6. Tryb worker (Octane) — świadomie odroczony

Obraz stoi na trybie **classic**, nie worker. Tryb worker trzyma aplikację w pamięci między
żądaniami i wymaga przeglądu stanu współdzielonego (statyczne właściwości, singletony, kontener
usług) — to osobne zadanie z własnym sprawdzeniem. Przejście nie wymaga zmiany serwera ani obrazu,
więc nic nie jest tu zamknięte.
