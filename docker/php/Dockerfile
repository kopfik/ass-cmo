FROM php:8.5-fpm-alpine

RUN apk add --no-cache libpq-dev oniguruma-dev \
    && docker-php-ext-install pdo_pgsql mbstring
