FROM dunglas/frankenphp:1-php8.5-bookworm AS base

ENV DEBIAN_FRONTEND=noninteractive \
    APP_ENV=production \
    APP_DEBUG=false \
    COMPOSER_ALLOW_SUPERUSER=1 \
    ORBIT_CONFIG_ROOT=/home/orbit/.config/orbit

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        bash \
        ca-certificates \
        curl \
        git \
        iputils-ping \
        openssh-client \
        procps \
        sudo \
        tini \
        unzip \
        zip \
        gnupg \
    && install -m 0755 -d /etc/apt/keyrings \
    && curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc \
    && chmod a+r /etc/apt/keyrings/docker.asc \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/debian bookworm stable" > /etc/apt/sources.list.d/docker.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        docker-ce-cli \
        docker-compose-plugin \
    && curl -fsSL https://getcomposer.org/download/latest-stable/composer.phar -o /usr/bin/composer \
    && chmod 755 /usr/bin/composer \
    && install-php-extensions bcmath curl intl mbstring pcntl pdo_sqlite zip \
    && groupadd --system orbit \
    && useradd --system --gid orbit --create-home --home-dir /home/orbit --shell /bin/bash orbit \
    && printf '%s\n' 'max_execution_time=0' > /usr/local/etc/php/conf.d/orbit-gateway.ini \
    && install -d -m 700 -o orbit -g orbit /home/orbit/.config/orbit \
    && rm -rf /var/lib/apt/lists/*

FROM base AS vendor

WORKDIR /srv/orbit/apps/gateway

COPY apps/gateway /srv/orbit/apps/gateway
COPY packages/core /srv/orbit/packages/core
COPY bin/install-orbit /srv/orbit/bin/install-orbit

RUN chmod 755 /srv/orbit/bin/install-orbit \
    && install -d "$ORBIT_CONFIG_ROOT" \
    && cp .env.example "$ORBIT_CONFIG_ROOT/.env" \
    && touch "$ORBIT_CONFIG_ROOT/gateway.sqlite" \
    && sed -i "s|^DB_DATABASE=.*|DB_DATABASE=$ORBIT_CONFIG_ROOT/gateway.sqlite|" "$ORBIT_CONFIG_ROOT/.env" \
    && composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && rm -rf "$ORBIT_CONFIG_ROOT" /root/.composer/cache

FROM base AS gateway

COPY --from=vendor --chown=orbit:orbit /srv/orbit /srv/orbit
COPY docker/orbit-gateway/Caddyfile /etc/caddy/Caddyfile
COPY docker/orbit-gateway/entrypoint.sh /usr/local/bin/orbit-gateway-entrypoint
COPY docker/orbit-gateway/healthcheck.sh /usr/local/bin/orbit-gateway-healthcheck

RUN chmod 755 /usr/local/bin/orbit-gateway-entrypoint /usr/local/bin/orbit-gateway-healthcheck \
    && ln -s /usr/local/bin/orbit-gateway-entrypoint /usr/local/bin/orbit \
    && install -d -m 700 -o orbit -g orbit "$ORBIT_CONFIG_ROOT" "$ORBIT_CONFIG_ROOT/certs"

WORKDIR /srv/orbit/apps/gateway

EXPOSE 8080

HEALTHCHECK --interval=10s --timeout=5s --start-period=20s --retries=6 CMD ["orbit-gateway-healthcheck"]

ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/orbit-gateway-entrypoint"]
CMD ["serve"]
