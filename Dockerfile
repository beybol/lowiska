# syntax=docker/dockerfile:1
#
# Jeden obraz, dwa cele: base → vendor → assets → dev / prod.
# Cel `dev` obsługuje lokalny Compose, cel `prod` — Cloud Run (ADR-002).

# ---------------------------------------------------------------------------
# base — wspólny fundament PHP dla etapów budujących i dla celu `dev`
# ---------------------------------------------------------------------------
FROM php:8.3-cli-bookworm AS base

COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/

# soap jest wymagany przez gusapi/gusapi (CSOService), reszta to zestaw
# używany przez Laravela, Filamenta i obsługę obrazów.
RUN install-php-extensions \
        pdo_mysql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        intl \
        soap \
    && rm -rf /tmp/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

# ---------------------------------------------------------------------------
# vendor — zależności PHP bez pakietów deweloperskich, dla celu `prod`
# ---------------------------------------------------------------------------
FROM base AS vendor

COPY composer.json composer.lock ./

# --no-scripts, bo skrypty post-autoload-dump wołają artisana, a kodu aplikacji
# jeszcze tu nie ma. Autoloader domyka prod-entrypoint.
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-scripts \
        --no-progress \
        --prefer-dist \
        --optimize-autoloader

# ---------------------------------------------------------------------------
# assets — build front-endu, wynik trafia do celu `prod` jako public/build
# ---------------------------------------------------------------------------
FROM node:22-bookworm-slim AS assets

WORKDIR /var/www/html

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

# tailwind.config.js skanuje vendor/laravel/framework/**/Pagination/**, więc bez
# katalogu vendor klasy paginacji wypadłyby z gotowego arkusza.
COPY --from=vendor /var/www/html/vendor ./vendor
COPY vite.config.js tailwind.config.js postcss.config.js ./
COPY resources ./resources

RUN npm run build

# ---------------------------------------------------------------------------
# dev — lokalne środowisko; kod wchodzi powiązaniem katalogu, nie COPY
# ---------------------------------------------------------------------------
FROM base AS dev

# pcov jest sterownikiem pokrycia dla testów mutacyjnych z /review-implementation.
RUN install-php-extensions pcov \
    && rm -rf /tmp/*

# Node zostaje w obrazie `dev`, żeby dało się wołać npm z kontenera aplikacji;
# klient MySQL-a — żeby dało się zajrzeć do bazy bez wychodzenia na hosta.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        gnupg \
        default-mysql-client \
        unzip \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php/dev.ini /usr/local/etc/php/conf.d/zz-lowiska.ini
COPY docker/dev-entrypoint.sh /usr/local/bin/dev-entrypoint
RUN chmod +x /usr/local/bin/dev-entrypoint

ENTRYPOINT ["dev-entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]

# ---------------------------------------------------------------------------
# prod — FrankenPHP w trybie classic, pod Cloud Run (ADR-002)
# ---------------------------------------------------------------------------
FROM dunglas/frankenphp:1-php8.3 AS prod

COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN install-php-extensions \
        pdo_mysql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        intl \
        soap \
        opcache \
    && rm -rf /tmp/*

# tini jako PID 1 — Cloud Run wysyła SIGTERM przy skalowaniu w dół, a bez
# poprawnej propagacji sygnału żądania w locie zostałyby urwane.
RUN apt-get update \
    && apt-get install -y --no-install-recommends tini \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY docker/php/prod.ini /usr/local/etc/php/conf.d/zz-lowiska.ini
COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/caddy/ /etc/caddy/snippets/

COPY . .
COPY --from=vendor /var/www/html/vendor ./vendor
COPY --from=assets /var/www/html/public/build ./public/build

COPY docker/prod-entrypoint.sh /usr/local/bin/prod-entrypoint
RUN chmod +x /usr/local/bin/prod-entrypoint \
    && composer dump-autoload --no-dev --optimize --no-interaction --no-scripts \
    && chown -R www-data:www-data storage bootstrap/cache

ENV SERVER_NAME=":8080"
ENV PORT=8080

EXPOSE 8080

ENTRYPOINT ["/usr/bin/tini", "--", "prod-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
