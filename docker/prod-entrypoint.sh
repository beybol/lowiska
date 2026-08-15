#!/bin/sh
set -e

# Cloud Run wstrzykuje $PORT i oczekuje, że kontener się do niego dostosuje.
# Caddyfile czyta ten adres przez {$SERVER_NAME}.
export SERVER_NAME=":${PORT:-8080}"

# Dockerfile buduje autoloader z --no-scripts (post-autoload-dump budowałby
# obraz z pustym środowiskiem i wywalałby guard z zadania 005 w
# AppServiceProvider::boot()), więc package:discover domykamy tutaj — dopiero
# teraz mamy prawdziwe zmienne środowiskowe z `gcloud run deploy`.
php artisan package:discover --ansi

# ⚠️ config:cache unieszkodliwia env() poza katalogiem config/ — konfiguracja
# musi być czytana przez config(). Niezmiennik pilnuje NoEnvInAppTest
# (zadanie 001, docs/conventions/integracje.md).
php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
