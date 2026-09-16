FROM composer:2 AS composer
FROM php:8.4-fpm-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates git unzip libicu-dev libonig-dev libzip-dev \
    libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j2 pdo_mysql mbstring intl gd zip pcntl opcache \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY php.ini /usr/local/etc/php/conf.d/zz-brnmail.ini
COPY php-fpm.conf /usr/local/etc/php-fpm.d/zzz-brnmail.conf
WORKDIR /srv/brnmail
