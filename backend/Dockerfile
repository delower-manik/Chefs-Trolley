# syntax=docker/dockerfile:1
# Chefs Trolley API — PHP 8.3 FPM. One image, three roles (fpm / queue worker / scheduler) chosen by the command.
# BUILD CONTEXT IS THE REPO ROOT:  docker build -f backend/Dockerfile .   (compose does this for you)
# NOT built in the authoring sandbox (no Docker daemon there) — see docs/DEPLOYMENT.md "What was verified".

FROM composer:2 AS vendor
WORKDIR /app
COPY backend/composer.json ./
# composer.lock is not committed yet: generate it once on a networked machine and commit it (the glob keeps this COPY working without it).
COPY backend/composer.loc[k] ./
RUN if [ -f composer.lock ]; then composer install; else composer update; fi \
    --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY backend/ .
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts

FROM php:8.3-fpm-alpine AS app
RUN apk add --no-cache icu-libs libzip mariadb-client tini \
 && apk add --no-cache --virtual .build $PHPIZE_DEPS icu-dev libzip-dev \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql intl zip bcmath pcntl opcache \
 && pecl install redis >/dev/null && docker-php-ext-enable redis \
 && apk del .build
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
WORKDIR /var/www/html
COPY --from=vendor /app /var/www/html
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/app/backups bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache
USER www-data
EXPOSE 9000
HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD php -r 'exit(@fsockopen("127.0.0.1", 9000) ? 0 : 1);'
ENTRYPOINT ["/sbin/tini", "--"]
CMD ["php-fpm"]
