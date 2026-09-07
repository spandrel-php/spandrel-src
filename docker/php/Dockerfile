# syntax=docker/dockerfile:1

# php:8.5-cli-alpine — pinned by digest; bumped by Dependabot
FROM php:8.5-cli-alpine@sha256:aa66ee4275e0472bafcd18e35142499fcbda6ac90e641e751418e052e6a0d812

# git: box.json's git-version needs `git describe`/`git log` at compile time
RUN apk add --no-cache git

# Install build dependencies for pecl extensions, then clean up
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && apk del .build-deps

COPY xdebug.ini /usr/local/etc/php/conf.d/xdebug.ini

# Composer, from composer:2 — pinned by digest; bumped by Dependabot
COPY --from=composer:2@sha256:4d71c3c2109c61d5415544264b59ad4087e4c5b7244481723664138fd36d5040 /usr/bin/composer /usr/bin/composer

WORKDIR /app
