FROM composer:2.8 AS vendor

WORKDIR /var/www/api

COPY apps/api/composer.json ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

COPY apps/api/ ./
RUN composer dump-autoload --no-dev --optimize --no-interaction

FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
        icu-dev \
        libpq-dev \
        libzip-dev \
        oniguruma-dev \
        unzip \
    && docker-php-ext-install -j"$(nproc)" bcmath intl opcache pdo_pgsql zip \
    && pecl install redis \
    && docker-php-ext-enable redis

WORKDIR /var/www/api

COPY --from=vendor /var/www/api /var/www/api
COPY apps/api/docker/php-production.ini /usr/local/etc/php/conf.d/99-clientele-production.ini
COPY apps/api/docker/entrypoint.sh /usr/local/bin/clientele-entrypoint

RUN chmod 0755 /usr/local/bin/clientele-entrypoint \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data

ENTRYPOINT ["/usr/local/bin/clientele-entrypoint"]
CMD ["php-fpm"]
