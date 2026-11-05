# syntax=docker/dockerfile:1

# Imagen de la API (Laravel 13 + PHP 8.5-FPM + Nginx) en un solo contenedor sin root.
#
#   docker build -t farma-sistema-api .
#   docker run -p 8000:8000 -e APP_KEY=base64:... -e DB_URL=postgres://... farma-sistema-api
#
# Con Podman, añade `--format docker` para que se conserve el HEALTHCHECK.

ARG PHP_VERSION=8.5

# ---------- dependencias de Composer (sin dev) ----------
FROM docker.io/library/composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
# Sin scripts ni autoloader: el código aún no está; la imagen de composer no tiene las extensiones
# del runtime (pdo_pgsql), de ahí --ignore-platform-reqs (el runtime sí las cumple).
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --no-progress \
        --prefer-dist --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts

# ---------- runtime ----------
FROM docker.io/library/php:${PHP_VERSION}-fpm-alpine AS runtime

LABEL org.opencontainers.image.title="farma-sistema-api" \
      org.opencontainers.image.description="API REST de dispensación e inventario (Laravel, PHP-FPM + Nginx, sin root)"

# Extensiones: pdo_pgsql (PostgreSQL), pcntl (señales en schedule:work), intl (Number/locale de Laravel).
# OPcache viene incluido en PHP 8.5.
COPY --from=ghcr.io/mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql pcntl intl \
 && apk add --no-cache nginx tini \
 && rm /usr/local/bin/install-php-extensions \
 && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && rm -f /usr/local/etc/php-fpm.d/www.conf.default /usr/local/etc/php-fpm.d/zz-docker.conf

COPY docker/api/php.ini $PHP_INI_DIR/conf.d/zz-farma.ini
COPY docker/api/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/api/nginx.conf.template /etc/nginx/farma.conf.template
COPY --chmod=755 docker/api/entrypoint.sh /usr/local/bin/farma-entrypoint

WORKDIR /var/www/html
# Código y vendor con dueño root (solo lectura para www-data); solo storage y bootstrap/cache son suyos.
COPY . .
COPY --from=vendor /app/vendor ./vendor

# storage/ no entra en el contexto (.dockerignore): se crea vacío y escribible.
RUN mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache \
 && php artisan package:discover --ansi \
 && chown -R www-data:www-data storage bootstrap/cache

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    LOG_STDERR_FORMATTER=Monolog\\Formatter\\JsonFormatter \
    LOG_LEVEL=info \
    SESSION_DRIVER=array \
    QUEUE_CONNECTION=sync \
    CACHE_STORE=database \
    DB_CONNECTION=pgsql \
    PORT=8000

USER www-data:www-data
EXPOSE 8000

HEALTHCHECK --interval=15s --timeout=3s --start-period=40s --retries=3 \
  CMD wget -q -O /dev/null "http://127.0.0.1:${PORT}/ready" || exit 1

ENTRYPOINT ["/sbin/tini", "--", "farma-entrypoint"]
CMD ["serve"]
