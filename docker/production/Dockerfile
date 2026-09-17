# syntax=docker/dockerfile:1

FROM node:24-bookworm-slim AS frontend

WORKDIR /var/www/html

COPY package.json package-lock.json .npmrc ./
RUN npm ci --ignore-scripts --no-audit --no-fund

COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
RUN npm run build


FROM php:8.4-fpm-bookworm AS php-base

ENV COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libfcgi-bin \
        libicu-dev \
        libonig-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-install -j"$(nproc)" bcmath intl mbstring opcache pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/php/production.ini /usr/local/etc/php/conf.d/99-pinky-production.ini
COPY docker/php/production-fpm.conf /usr/local/etc/php-fpm.d/zz-pinky-production.conf

WORKDIR /var/www/html


FROM php-base AS app

COPY --chown=www-data:www-data . ./
COPY --from=frontend --chown=www-data:www-data /var/www/html/public/build ./public/build

RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --optimize-autoloader \
    && mkdir -p \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000

CMD ["php-fpm"]


FROM nginx:stable-alpine AS web

COPY docker/nginx/production.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public

EXPOSE 80
