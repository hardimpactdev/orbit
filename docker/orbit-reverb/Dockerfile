FROM ubuntu:26.04

ENV DEBIAN_FRONTEND=noninteractive \
    APP_ENV=production \
    APP_DEBUG=false \
    COMPOSER_ALLOW_SUPERUSER=1 \
    ORBIT_WEBSOCKET_APPS_CONFIG=/etc/orbit/websocket/apps.php

RUN printf '%s\n' 'Acquire::ForceIPv4 "true";' 'Acquire::http::Timeout "10";' 'Acquire::https::Timeout "10";' 'Acquire::Retries "3";' > /etc/apt/apt.conf.d/99orbit-e2e-network \
    && apt-get update -qq \
    && apt-get install -y -qq --no-install-recommends \
        ca-certificates \
        curl \
        git \
        php8.5-bcmath \
        php8.5-cli \
        php8.5-common \
        php8.5-curl \
        php8.5-intl \
        php8.5-mbstring \
        php8.5-redis \
        php8.5-sqlite3 \
        php8.5-xml \
        php8.5-zip \
        unzip \
    && curl -fsSL https://getcomposer.org/download/latest-stable/composer.phar -o /usr/local/bin/composer \
    && chmod 755 /usr/local/bin/composer \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY apps/reverb /app

RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts \
    && install -d bootstrap/cache storage/framework/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && rm -rf /root/.composer/cache \
    && chmod 0755 /app/artisan

LABEL orbit.websocket.self_contained="true"

CMD ["php", "artisan", "reverb:start", "--host=0.0.0.0", "--port=8080"]
