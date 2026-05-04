FROM php:8.4-cli-bookworm

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        bash \
        ca-certificates \
        curl \
        git \
        iproute2 \
        libonig-dev \
        libsqlite3-dev \
        libzip-dev \
        sqlite3 \
        sudo \
        unzip \
    && docker-php-ext-install mbstring pdo_sqlite zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN useradd -m -s /bin/bash control \
    && useradd -m -s /bin/bash orbit \
    && echo 'control ALL=(ALL) NOPASSWD:ALL' > /etc/sudoers.d/control \
    && echo 'orbit ALL=(ALL) NOPASSWD:ALL' > /etc/sudoers.d/orbit \
    && chmod 440 /etc/sudoers.d/control /etc/sudoers.d/orbit

RUN printf '%s\n' \
        '#!/usr/bin/env bash' \
        'set -e' \
        'if [ -f "$PWD/artisan" ]; then' \
        '    exec php "$PWD/artisan" "$@"' \
        'fi' \
        'if [ -f "$HOME/orbit/artisan" ]; then' \
        '    exec php "$HOME/orbit/artisan" "$@"' \
        'fi' \
        'exec php /home/orbit/orbit/artisan "$@"' \
        > /usr/local/bin/orbit \
    && chmod 755 /usr/local/bin/orbit

WORKDIR /opt/orbit-source
COPY . /opt/orbit-source

RUN install -d -o control -g control /home/control/orbit \
    && install -d -o orbit -g orbit /home/orbit/orbit \
    && cp -a /opt/orbit-source/. /home/control/orbit/ \
    && cp -a /opt/orbit-source/. /home/orbit/orbit/ \
    && chown -R control:control /home/control/orbit \
    && chown -R orbit:orbit /home/orbit/orbit \
    && sudo -iu control bash -lc 'cd /home/control/orbit && composer install --no-interaction --no-progress --no-dev' \
    && sudo -iu orbit bash -lc 'cd /home/orbit/orbit && composer install --no-interaction --no-progress --no-dev'

CMD ["sleep", "infinity"]
