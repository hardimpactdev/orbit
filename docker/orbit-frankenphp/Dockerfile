ARG PHP_VERSION=8.5

FROM dunglas/frankenphp:1-php${PHP_VERSION}-bookworm

RUN install-php-extensions \
    bcmath \
    exif \
    gd \
    intl \
    pcntl \
    pdo_mysql \
    pdo_pgsql \
    redis \
    sockets \
    zip
