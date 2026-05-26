# =====================================================================
#  PVModern Magento 2 — PHP-FPM application image
# =====================================================================
#  Two-stage build:
#    1. composer install in a builder stage (heavy toolchain, thrown away)
#    2. slim runtime stage with only what php-fpm needs at boot
#
#  The Magento source tree is COPIED into the image so the container is
#  self-contained — no bind-mount of /var/www/Magento needed in
#  production. Mutable runtime dirs (var/, pub/media, generated/) come
#  from named volumes mounted by docker-compose.
# =====================================================================

# --- Stage 1: composer install ---------------------------------------
FROM php:8.3-fpm-bookworm AS builder

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1

# System packages required for the PHP extensions Magento needs at build time.
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip libicu-dev libxml2-dev libxslt1-dev libzip-dev libonig-dev \
    libpng-dev libjpeg-dev libfreetype6-dev libwebp-dev libsodium-dev \
    libcurl4-openssl-dev libssl-dev libmagickwand-dev libgd-dev \
    autoconf gcc g++ make pkg-config zip \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions — exactly the set Magento 2.4.7+ requires.
# - ftp is required by Magento's composer.json (file uploads in admin).
# - redis ≥ 6.1 is required because symfony/cache v7.4+ conflicts with
#   older redis builds (it uses the new pipeline API added in 6.1).
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath curl ftp gd intl mbstring opcache pdo_mysql soap sockets sodium xsl zip \
    && pecl install redis-6.2.0 \
    && docker-php-ext-enable redis

# Get composer from its official image so we always have a recent version.
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

WORKDIR /srv/magento

# Copy the entire project BEFORE composer install. Magento's
# magento/magento-composer-installer plugin writes app/etc/vendor_path.php
# during install, so app/etc/ must already exist on disk — the cache-only
# COPY-manifests-first pattern doesn't work here. The .dockerignore
# excludes mutable runtime dirs (var/, generated/, pub/static/frontend/*)
# from the COPY so this stays reasonably fast.
COPY . .

# Install production dependencies only (no dev tools, no scripts).
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --no-interaction --no-progress --prefer-dist \
    --optimize-autoloader --no-scripts

# Re-run autoload dump now that everything is in place (registers modules).
RUN composer dump-autoload --optimize --no-dev


# --- Stage 2: runtime ------------------------------------------------
FROM php:8.3-fpm-bookworm AS runtime

ENV DEBIAN_FRONTEND=noninteractive \
    PHP_MEMORY_LIMIT=2G \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0

# Slim runtime deps — no compilers, just the .so libraries the extensions link against.
RUN apt-get update && apt-get install -y --no-install-recommends \
    libicu72 libxml2 libxslt1.1 libzip4 libonig5 \
    libpng16-16 libjpeg62-turbo libfreetype6 libwebp7 \
    libsodium23 libmagickwand-6.q16-6 libcurl4 \
    cron supervisor mariadb-client tini \
    && rm -rf /var/lib/apt/lists/*

# Copy the prebuilt PHP extensions from the builder so we don't reinstall them.
COPY --from=builder /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=builder /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/

# Production PHP config — opcache tuned for Magento.
COPY docker/php-fpm/php.ini /usr/local/etc/php/conf.d/zz-magento.ini
COPY docker/php-fpm/www.conf /usr/local/etc/php-fpm.d/zz-magento.conf

# Magento source from builder.
WORKDIR /srv/magento
COPY --from=builder --chown=www-data:www-data /srv/magento /srv/magento

# Magento's bin/magento needs to be executable.
RUN chmod +x bin/magento

# Entrypoint handles first-boot setup (waits for DB, runs setup:upgrade,
# deploys static content if missing, then exec's php-fpm).
COPY docker/php-fpm/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Magento crontab — used by the `cron` service in docker-compose. The
# main `app` service ignores this file because its CMD is php-fpm; only
# the cron service overrides CMD to `cron -f` and picks this up.
RUN printf '%s\n' \
    '* * * * * www-data /usr/local/bin/php /srv/magento/bin/magento cron:run 2>&1 | tee -a /proc/1/fd/1' \
    '* * * * * www-data /usr/local/bin/php /srv/magento/update/cron.php 2>&1 | tee -a /proc/1/fd/1' \
    '* * * * * www-data /usr/local/bin/php /srv/magento/bin/magento setup:cron:run 2>&1 | tee -a /proc/1/fd/1' \
    > /etc/cron.d/magento \
    && chmod 0644 /etc/cron.d/magento \
    && crontab -u www-data /etc/cron.d/magento

# Magento's frontend needs to write to var/, pub/media, generated/. Make those
# writable by www-data even before the first run mounts volumes over them.
RUN mkdir -p var pub/media pub/static generated \
    && chown -R www-data:www-data var pub/media pub/static generated

EXPOSE 9000
ENTRYPOINT ["tini", "--", "/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm", "-F"]
