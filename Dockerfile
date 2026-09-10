# syntax=docker/dockerfile:1

ARG PHP_VERSION=8.5

 #############################################################################
 # Composer dependencies (production only)
 #############################################################################
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --prefer-dist \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --no-autoloader

COPY artisan ./
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY public ./public
COPY resources ./resources
COPY routes ./routes
COPY storage ./storage

RUN composer dump-autoload --optimize

 #############################################################################
 # Frontend assets (Node LTS builds them; the Vite dev server never ships)
 #############################################################################
FROM node:22-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json .npmrc ./
RUN npm ci --no-audit --no-fund

COPY vite.config.js ./
COPY resources ./resources
COPY app ./app

# Tailwind sources classes from these vendor paths (see resources/css/app.css).
COPY --from=vendor /app/vendor/laravel/framework ./vendor/laravel/framework
COPY --from=vendor /app/vendor/livewire/flux ./vendor/livewire/flux

RUN npm run build

 #############################################################################
 # Production image: one image, three roles (web, queue, scheduler)
 #############################################################################
FROM php:${PHP_VERSION}-fpm-alpine AS production

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/install-php-extensions

RUN install-php-extensions pdo_pgsql pgsql intl bcmath opcache pcntl \
    && apk add --no-cache nginx supervisor \
    && rm -rf /var/cache/apk/*

COPY docker/php.ini "$PHP_INI_DIR/conf.d/99-lafiel.ini"
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/lafiel.conf

WORKDIR /var/www/html

COPY --from=vendor /app ./
COPY --from=assets /app/public/build ./public/build
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh \
    && chown -R www-data:www-data storage bootstrap/cache

ENV ROLE=web \
    RUN_MIGRATIONS=false

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
