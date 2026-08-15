#!/bin/sh
set -e

# Kod aplikacji wchodzi do kontenera powiązaniem katalogu, więc zależności
# i podstawowa konfiguracja muszą powstać przy starcie, nie przy budowaniu.

if [ ! -f vendor/autoload.php ]; then
    echo "[dev-entrypoint] Brak vendor/autoload.php — instaluję zależności Composera."
    composer install --no-interaction --no-progress --prefer-dist
fi

if [ ! -f .env ]; then
    echo "[dev-entrypoint] Brak .env — tworzę z .env.example."
    cp .env.example .env
fi

if ! grep -qE '^APP_KEY=.+' .env; then
    echo "[dev-entrypoint] APP_KEY nie jest ustawiony — generuję."
    php artisan key:generate --force
fi

exec "$@"
