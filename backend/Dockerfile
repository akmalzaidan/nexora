# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# NEXORA API - production image for Render (Docker runtime).
#
# Build context MUST be `backend/` (Render "Root Directory" = `backend`,
# Dockerfile path = `Dockerfile`). The image never contains a `.env` file or
# any other credential: every value is injected as a runtime environment
# variable by the service's environment.
# ---------------------------------------------------------------------------

# --- Stage 1: production PHP dependencies (no dev) --------------------------
# The full source tree is required here to build a classmap-authoritative
# autoloader that matches the runtime image exactly. `--no-dev` also keeps the
# autoload-dev `Tests\` namespace out of the classmap, and `.dockerignore`
# excludes /tests/ from the context, so the two trees stay in sync. Composer
# itself never enters the published image: only /app/vendor is copied over.
FROM composer:2.8 AS vendor

WORKDIR /app

COPY . .

RUN composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --prefer-dist \
        --no-progress \
    && composer dump-autoload --optimize --classmap-authoritative --no-dev

# --- Stage 2: runtime ------------------------------------------------------
FROM php:8.3-apache AS runtime

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1

# pdo_pgsql/pgsql are required for Neon. bcmath, intl and zip are Laravel
# runtime expectations.
#
# The build headers are purged afterwards, but WITHOUT --auto-remove: the
# extensions link against libpq5/libicu/libzip, which apt had installed
# automatically as -dev dependencies. `--auto-remove` would take those shared
# libraries with it and every extension would fail to load at runtime, so the
# purge is deliberately limited to the -dev packages and the libraries are left
# in place as orphans.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libicu-dev \
        libpq-dev \
        libzip-dev \
        unzip; \
    docker-php-ext-configure intl; \
    docker-php-ext-install -j"$(nproc)" bcmath intl pdo_pgsql pgsql zip; \
    apt-get purge -y libicu-dev libpq-dev libzip-dev; \
    rm -rf /var/lib/apt/lists/*; \
    sed -ri -e 's/^ServerTokens .*/ServerTokens Prod/' \
           -e 's/^ServerSignature .*/ServerSignature Off/' \
        /etc/apache2/conf-available/security.conf

# Guard against a silently broken image: a PHP extension that fails to load
# only shows up as a fatal error on the first request, so assert it at build
# time instead. (This is what catches a missing shared library such as
# libpq.so.5 after a careless apt purge.)
RUN php -r 'foreach (["pdo_pgsql", "pgsql", "intl", "bcmath", "zip", "mbstring", "openssl", "PDO"] as $ext) { if (! extension_loaded($ext)) { fwrite(STDERR, "FATAL: PHP extension not loaded: {$ext}\n"); exit(1); } }'

COPY docker/php-production.ini /usr/local/etc/php/conf.d/zz-nexora-production.ini

COPY docker/apache-production.conf /etc/apache2/sites-available/nexora.conf
RUN a2dissite 000-default \
    && a2ensite nexora \
    && a2enmod rewrite headers

WORKDIR /var/www/html

COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor

# `.dockerignore` strips the contents of these directories, so they are
# recreated explicitly. The application performs no filesystem writes, so every
# one of them is safe to let live on Render's ephemeral disk; all durable state
# belongs to PostgreSQL/Neon.
RUN set -eux; \
    mkdir -p \
        bootstrap/cache/packages \
        bootstrap/cache/services \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs; \
    chown -R www-data:www-data bootstrap/cache storage; \
    chmod -R ug+rwX bootstrap/cache storage

# Render forwards HTTPS traffic to this container over plain HTTP on
# 0.0.0.0:$PORT. Render only sets $PORT once the service is scheduled, so the
# listen port is written at container start rather than baked into the image.
#
# ports.conf is rewritten rather than sed-patched. `sh -c` has no preceding
# shell, so a `$$` end-of-line anchor would expand to the shell's PID, the
# pattern would match nothing, and Apache would stay on :80 while Render
# expects $PORT — an unreachable container with no build-time warning.
ENV PORT=10000

EXPOSE 10000

CMD ["sh", "-c", "set -e; printf 'Listen %s\\n' \"${PORT:-10000}\" > /etc/apache2/ports.conf; exec apache2-foreground"]
