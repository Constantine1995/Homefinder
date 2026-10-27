FROM php:8.3-fpm-trixie

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/

RUN install-php-extensions \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        redis \
        zip

# osmium filters the OpenStreetMap extract of the region down to the points of interest the catalog needs;
# rsvg-convert turns SVG floor plans into PNG for messengers, which show no SVG, with DejaVu for Cyrillic labels.
RUN apt-get update \
    && apt-get install -y --no-install-recommends osmium-tool librsvg2-bin fonts-dejavu-core \
    && rm -rf /var/lib/apt/lists/*

RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
COPY php.ini "$PHP_INI_DIR/conf.d/zz-app.ini"

# Match the host user's UID/GID so files created inside the bind-mounted project stay editable on Linux hosts.
ARG WWWUSER=1000
ARG WWWGROUP=1000

RUN groupmod --non-unique --gid "$WWWGROUP" www-data \
    && usermod --non-unique --uid "$WWWUSER" --gid "$WWWGROUP" www-data \
    && chown www-data:www-data /var/www

WORKDIR /var/www/html

USER www-data
