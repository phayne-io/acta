# syntax=docker/dockerfile:1

# One image for the API (PHP-FPM) and for the console (workers, migrations): the same
# code and the same extensions, started with a different command.
#
#     docker build --target prod -t app .
#
# docs/operations.md says how to run it.

ARG PHP_VERSION=8.5

FROM php:${PHP_VERSION}-fpm-alpine AS base

COPY --from=ghcr.io/mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

# pdo_pgsql: the event store and the read models. bcmath: exact amounts (moneyphp/money).
# pcntl: lets the workers finish their batch when they are asked to stop. redis: the
# shared cache (CACHE_DSN), on Valkey or Redis.
RUN install-php-extensions pdo_pgsql bcmath pcntl redis

WORKDIR /app


# --- Development: the sources are mounted, Composer is at hand -------------------------
FROM base AS dev

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"


# --- The Acta libraries, when they are not on Packagist ---------------------------------
# Empty, except inside the Acta monorepo, where the libraries are next to this directory
# and outside what Docker may read. There, give them as a second build context:
#
#     docker build --target prod --build-context libraries=.. -t app .
FROM scratch AS libraries


# --- Dependencies of the production image ----------------------------------------------
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
# Without a lock file, as in the monorepo where it is not committed, versions are resolved.
COPY composer.json composer.lock* ./

# Path repositories of composer.json point to ../<library>, / from here: each library of
# the context above is put there, and copied to vendor/ rather than linked, since the
# next stage only takes vendor/.
RUN --mount=type=bind,from=libraries,target=/libraries \
    for library in /libraries/*/composer.json; do \
        [ -e "$library" ] && ln -s "$(dirname "$library")" "/$(basename "$(dirname "$library")")"; \
    done; \
    COMPOSER_MIRROR_PATH_REPOS=1 composer install --no-dev --no-interaction --no-progress --no-scripts --prefer-dist --no-autoloader

# The class map is the only way classes are found in production: it is built once the
# sources are there, or the application would not find its own. Installing above and
# mapping here keeps the installed packages cached when only the sources change.
COPY src src
RUN composer dump-autoload --no-dev --no-interaction --no-scripts --classmap-authoritative


# --- Production ------------------------------------------------------------------------
FROM base AS prod

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/production.ini "$PHP_INI_DIR/conf.d/zz-app.ini"

COPY --from=vendor /app/vendor vendor
COPY bin bin
COPY config config
COPY openapi openapi
COPY public public
COPY src src

# The compiled contract and the cached keys of the identity provider are written here.
RUN mkdir -p data/cache && chown -R www-data:www-data data

ENV APP_DEBUG=0 \
    HTTP_RESPONSE_VALIDATION=log \
    LOG_STREAM=php://stderr

USER www-data

# PHP-FPM by default. Workers override the command:
#     bin/console projection:run banking_accounts
#     bin/console webhook:deliver --watch
CMD ["php-fpm"]
