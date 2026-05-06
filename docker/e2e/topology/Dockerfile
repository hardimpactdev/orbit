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
        openssh-client \
        openssh-server \
        supervisor \
        sqlite3 \
        sudo \
        tini \
        unzip \
    && docker-php-ext-install mbstring pdo_sqlite zip \
    && mkdir -p /run/sshd \
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

RUN printf '%s\n' \
        '[supervisord]' \
        'nodaemon=true' \
        'logfile=/var/log/supervisor/supervisord.log' \
        'pidfile=/run/supervisord.pid' \
        '' \
        '[program:sshd]' \
        'command=/usr/sbin/sshd -D' \
        'autorestart=true' \
        'stdout_logfile=/var/log/supervisor/sshd.log' \
        'stderr_logfile=/var/log/supervisor/sshd.err.log' \
        '' \
        '[program:orbit_scheduler]' \
        'directory=/home/orbit/orbit' \
        'command=/bin/bash -lc "php artisan orbit-scheduler --sleep-seconds=60"' \
        'user=orbit' \
        'autostart=true' \
        'autorestart=true' \
        'stdout_logfile=/home/orbit/.config/orbit/logs/orbit_scheduler.log' \
        'stderr_logfile=/home/orbit/.config/orbit/logs/orbit_scheduler.err.log' \
        > /etc/supervisor/conf.d/orbit-e2e.conf \
    && printf '%s\n' \
        '#!/usr/bin/env bash' \
        'set -e' \
        'mkdir -p /run/sshd /var/log/supervisor /home/orbit/.config/orbit/logs' \
        'chown -R orbit:orbit /home/orbit/.config' \
        'exec /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf' \
        > /usr/local/bin/orbit-e2e-container \
    && chmod 755 /usr/local/bin/orbit-e2e-container

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

ENTRYPOINT ["/usr/bin/tini", "--"]
CMD ["/usr/local/bin/orbit-e2e-container"]
