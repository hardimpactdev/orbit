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
        openssh-client \
        openssh-server \
        php8.4-cli \
        php8.4-mbstring \
        php8.4-curl \
        php8.4-sqlite3 \
        php8.4-xml \
        sudo \
    && update-alternatives --set php /usr/bin/php8.4 \
    && php --version | grep -q '^PHP 8.4.' \
    && php -r 'foreach (["pdo_sqlite", "openssl", "curl", "mbstring", "json", "xml"] as $extension) { if (! extension_loaded($extension)) { fwrite(STDERR, $extension.PHP_EOL); exit(1); } }' \
    && mkdir -p /run/sshd \
    && rm -rf /var/lib/apt/lists/*

RUN useradd -m -s /bin/bash control \
    && useradd -m -s /bin/bash orbit \
    && usermod -aG docker control \
    && usermod -aG docker orbit \
    && echo 'control ALL=(ALL) NOPASSWD:ALL' > /etc/sudoers.d/control \
    && echo 'orbit ALL=(ALL) NOPASSWD:ALL' > /etc/sudoers.d/orbit \
    && chmod 440 /etc/sudoers.d/control /etc/sudoers.d/orbit

RUN printf '%s\n' \
        '#!/usr/bin/env bash' \
        'set -euo pipefail' \
        'mkdir -p /run/sshd /home/orbit/.config/orbit/logs' \
        'chown -R orbit:orbit /home/orbit/.config' \
        'exec /usr/sbin/sshd -D -e' \
        > /usr/local/bin/orbit-e2e-container \
    && chmod 755 /usr/local/bin/orbit-e2e-container

RUN install -d -o control -g control /home/control/orbit \
    && install -d -o orbit -g orbit /home/orbit/orbit

CMD ["/usr/local/bin/orbit-e2e-container"]
