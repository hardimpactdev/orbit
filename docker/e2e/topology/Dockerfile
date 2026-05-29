FROM ubuntu:24.04

LABEL org.orbit.e2e.substrate="docker-first"
LABEL org.orbit.e2e.source="prepared-checkout"

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        software-properties-common \
    && add-apt-repository ppa:ondrej/php -y \
    && apt-get purge -y --auto-remove software-properties-common \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        bash \
        curl \
        docker.io \
        git \
        iproute2 \
        openssh-client \
        openssh-server \
        php8.5-cli \
        php8.5-mbstring \
        php8.5-curl \
        php8.5-sqlite3 \
        php8.5-xml \
        redis-server \
        sudo \
    && update-alternatives --set php /usr/bin/php8.5 \
    && php --version | grep -q '^PHP 8.5.' \
    && php -r 'foreach (["pdo_sqlite", "openssl", "curl", "mbstring", "json", "xml"] as $extension) { if (! extension_loaded($extension)) { fwrite(STDERR, $extension.PHP_EOL); exit(1); } }' \
    && mkdir -p /run/sshd \
    && rm -rf /var/lib/apt/lists/*

RUN useradd -m -s /bin/bash orbit \
    && usermod -aG docker orbit \
    && echo 'orbit ALL=(ALL) NOPASSWD:ALL' > /etc/sudoers.d/orbit \
    && chmod 440 /etc/sudoers.d/orbit

RUN printf '%s\n' \
        '#!/usr/bin/env bash' \
        'set -euo pipefail' \
        'if [ -S /var/run/docker.sock ]; then' \
        '  socket_gid="$(stat -c %g /var/run/docker.sock 2>/dev/null || stat -f %g /var/run/docker.sock 2>/dev/null || true)"' \
        '  if [ -n "$socket_gid" ]; then' \
        '    socket_group="$(getent group "$socket_gid" | cut -d: -f1 || true)"' \
        '    if [ -z "$socket_group" ] && getent group docker >/dev/null; then' \
        '      groupmod -g "$socket_gid" docker 2>/dev/null || true' \
        '      socket_group="$(getent group "$socket_gid" | cut -d: -f1 || true)"' \
        '    fi' \
        '    if [ -n "$socket_group" ]; then' \
        '      usermod -aG "$socket_group" orbit 2>/dev/null || true' \
        '    fi' \
        '  fi' \
        'fi' \
        'redis-server --daemonize yes --bind 0.0.0.0 --protected-mode no' \
        'mkdir -p /run/sshd /home/orbit/.config/orbit/logs' \
        'chown -R orbit:orbit /home/orbit/.config' \
        'exec /usr/sbin/sshd -D -e' \
        > /usr/local/bin/orbit-e2e-container \
    && chmod 755 /usr/local/bin/orbit-e2e-container

RUN install -d -o orbit -g orbit /home/orbit/orbit

CMD ["/usr/local/bin/orbit-e2e-container"]
