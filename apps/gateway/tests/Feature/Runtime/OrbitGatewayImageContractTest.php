<?php

declare(strict_types=1);

it('packages the gateway app in a FrankenPHP image without relying on host PHP source mounts', function (): void {
    $dockerfile = file_get_contents(repo_path('docker/orbit-gateway/Dockerfile'));

    expect($dockerfile)
        ->toContain('FROM dunglas/frankenphp:')
        ->toContain('FROM base AS dependencies')
        ->toContain('FROM dependencies AS application')
        ->toContain(
            'COPY apps/gateway/artisan apps/gateway/composer.json apps/gateway/composer.lock apps/gateway/.env.example /srv/orbit/apps/gateway/',
        )
        ->toContain('COPY packages/core/composer.json packages/core/composer.lock /srv/orbit/packages/core/')
        ->toContain('composer install --no-dev --no-interaction --prefer-dist --no-autoloader --no-scripts')
        ->toContain('COPY apps/gateway/app /srv/orbit/apps/gateway/app')
        ->toContain('COPY apps/gateway/resources/css /srv/orbit/apps/gateway/resources/css')
        ->toContain('COPY apps/gateway/resources/js /srv/orbit/apps/gateway/resources/js')
        ->toContain('COPY apps/gateway/resources/node-scripts /srv/orbit/apps/gateway/resources/node-scripts')
        ->toContain('COPY apps/gateway/resources/views /srv/orbit/apps/gateway/resources/views')
        ->toContain('COPY bin/install-orbit /srv/orbit/bin/install-orbit')
        ->toContain('COPY docker/orbit-gateway /srv/orbit/docker/orbit-gateway')
        ->toContain('chmod 755 /srv/orbit/bin/install-orbit')
        ->toContain('COPY packages/core/src /srv/orbit/packages/core/src')
        ->toContain('rm -f bootstrap/cache/*.php')
        ->toContain('composer dump-autoload --no-dev --no-interaction --optimize --no-scripts')
        ->toContain('docker-ce-cli')
        ->toContain('docker-compose-plugin')
        ->toContain('iputils-ping')
        ->toContain('getcomposer.org/download/latest-stable/composer.phar')
        ->toContain('pdo_mysql')
        ->toContain('pdo_pgsql')
        ->toContain('pdo_sqlite')
        ->toContain('WORKDIR /srv/orbit/apps/gateway')
        ->toContain('composer install')
        ->toContain('ORBIT_CONFIG_ROOT=/home/orbit/.config/orbit')
        ->toContain('max_execution_time=0')
        ->toContain('useradd')
        ->toContain('/home/orbit')
        ->toContain('orbit-gateway-entrypoint')
        ->toContain('orbit-gateway-healthcheck')
        ->toContain('COPY --from=application --chown=orbit:orbit /srv/orbit /srv/orbit')
        ->toContain('HEALTHCHECK')
        ->not->toContain('COPY apps/gateway /srv/orbit/apps/gateway')
        ->not->toContain('COPY packages/core /srv/orbit/packages/core')
        ->not->toContain('COPY apps/gateway /app')
        ->not->toContain('COPY apps/reverb')
        ->not->toContain('COPY --from=composer')
        ->not->toContain('COPY --from=docker')
        ->not->toContain('VOLUME ["/opt/orbit"]');
});

it('keeps the orbit gateway image build context free of host secrets and generated state', function (): void {
    $dockerignore = file_get_contents(repo_path('docker/orbit-gateway/Dockerfile.dockerignore'));

    expect($dockerignore)
        ->toContain('**/.env')
        ->toContain('**/.env.*')
        ->toContain('**/vendor')
        ->toContain('**/node_modules')
        ->toContain('.git')
        ->toContain('apps/gateway/database/*.sqlite')
        ->toContain('apps/gateway/database/*.sqlite-*')
        ->toContain('apps/gateway/storage/logs')
        ->toContain('apps/gateway/storage/framework')
        ->toContain('apps/gateway/bootstrap/cache')
        ->toContain('!apps/gateway/.env.example')
        ->toContain('!apps/gateway/app/**')
        ->toContain('!apps/gateway/resources/views/**')
        ->toContain('!bin/install-orbit')
        ->toContain('!packages/core/src/**')
        ->toContain('**/tests')
        ->toContain('**/build')
        ->toContain('**/builds')
        ->not->toContain('!apps/gateway/**')
        ->not->toContain('!apps/reverb/**')
        ->not->toContain('!packages/core/**')->toContain('!docker/orbit-gateway/**');
});

it('packages the Reverb runtime as a self-contained image', function (): void {
    $dockerfile = file_get_contents(repo_path('docker/orbit-reverb/Dockerfile'));
    $dockerignore = file_get_contents(repo_path('docker/orbit-reverb/Dockerfile.dockerignore'));

    expect($dockerfile)
        ->toContain('COPY apps/reverb /app')
        ->toContain('composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts')
        ->toContain('LABEL orbit.websocket.self_contained="true"')
        ->toContain('CMD ["php", "artisan", "reverb:start"')
        ->and($dockerignore)
        ->toContain('!apps/reverb/**')
        ->toContain('**/vendor')
        ->not->toContain('!apps/gateway/**');
});

it('builds the managed FrankenPHP runtime images from upstream with Orbit baseline extensions', function (): void {
    $dockerfile = file_get_contents(repo_path('docker/orbit-frankenphp/Dockerfile'));

    expect($dockerfile)
        ->toContain('ARG PHP_VERSION=8.5')
        ->toContain('FROM node:22-bookworm-slim AS node')
        ->toContain('FROM dunglas/frankenphp:1-php${PHP_VERSION}-bookworm')
        ->toContain('ARG SQLITE_VERSION=3.44.6')
        ->toContain('ARG SQLITE_SOURCE_ID=863c171b76cd36e0c71662d4a50d84da531cc7fbead893d02459577febdf6396')
        ->toContain('ARG SQLITE_ARCHIVE_SHA256=c25cd42f803d5fb0af5f1a2863c9f529a8fd35177cb24b0f6e970b1cc96f00f0')
        ->toContain('https://www.sqlite.org/src/tarball/${SQLITE_SOURCE_ID}/sqlite.tar.gz')
        ->toContain('sha256sum -c -')
        ->toContain('sqlite/manifest.uuid')
        ->toContain('COPY --from=sqlite /opt/sqlite /usr/local')
        ->toContain('ENV LD_LIBRARY_PATH=/usr/local/lib')
        ->toContain('ldconfig')
        ->toContain('SQLite3::version()')
        ->toContain('new PDO("sqlite::memory:")')
        ->toContain('select sqlite_version()')
        ->toContain('COPY --from=node /usr/local/bin/node /usr/local/bin/node')
        ->toContain('COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules')
        ->toContain('apt-get install -y --no-install-recommends chromium')
        ->toContain('ENV PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium')
        ->toContain('RUN install-php-extensions')
        ->toContain('bcmath')
        ->toContain('exif')
        ->toContain('gd')
        ->toContain('intl')
        ->toContain('pcntl')
        ->toContain('pdo_mysql')
        ->toContain('pdo_pgsql')
        ->toContain('redis')
        ->toContain('sockets')
        ->toContain('zip');
});

it('runs FrankenPHP on the internal gateway port and exposes a local health probe', function (): void {
    $caddyfile = file_get_contents(repo_path('docker/orbit-gateway/Caddyfile'));
    $entrypoint = file_get_contents(repo_path('docker/orbit-gateway/entrypoint.sh'));
    $healthcheck = file_get_contents(repo_path('docker/orbit-gateway/healthcheck.sh'));

    expect($caddyfile)
        ->toContain('frankenphp')
        ->toContain(':8080')
        ->toContain('path /app/*')
        ->toContain('reverse_proxy @operations_reverb orbit-operations-reverb:8080')
        ->toContain('php_server')
        ->toContain('root * /srv/orbit/apps/gateway/public')
        ->and($entrypoint)
        ->toContain('ORBIT_CONFIG_ROOT')
        ->toContain('exec frankenphp run --config /etc/caddy/Caddyfile --adapter caddyfile')
        ->and($healthcheck)
        ->toContain('http://127.0.0.1:8080/up');
});
