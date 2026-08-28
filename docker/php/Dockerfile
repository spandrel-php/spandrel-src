# syntax=docker/dockerfile:1

# php:8.5-cli-alpine — pinned by digest; bumped by Dependabot
FROM php:8.5-cli-alpine@sha256:0554eb53778b5316f6b9a3447c9dfa3cf2141c0c02ff816c42cdc9aa240a34aa

# Install build dependencies for pecl extensions, then clean up
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && apk del .build-deps

COPY xdebug.ini /usr/local/etc/php/conf.d/xdebug.ini

# Composer, from composer:2 — pinned by digest; bumped by Dependabot
COPY --from=composer:2@sha256:4d71c3c2109c61d5415544264b59ad4087e4c5b7244481723664138fd36d5040 /usr/bin/composer /usr/bin/composer

WORKDIR /app
