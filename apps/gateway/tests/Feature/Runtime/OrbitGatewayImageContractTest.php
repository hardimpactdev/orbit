<?php

declare(strict_types=1);

it('packages the gateway app in a FrankenPHP image without relying on host PHP source mounts', function (): void {
    $dockerfile = file_get_contents(repo_path('docker/orbit-gateway/Dockerfile'));

    expect($dockerfile)
        ->toContain('FROM dunglas/frankenphp:')
        ->toContain('COPY apps/gateway /srv/orbit/apps/gateway')
        ->toContain('COPY packages/core /srv/orbit/packages/core')
        ->toContain('docker-ce-cli')
        ->toContain('docker-compose-plugin')
        ->toContain('iputils-ping')
        ->toContain('getcomposer.org/download/latest-stable/composer.phar')
        ->toContain('WORKDIR /srv/orbit/apps/gateway')
        ->toContain('composer install')
        ->toContain('ORBIT_CONFIG_ROOT=/home/orbit/.config/orbit')
        ->toContain('max_execution_time=0')
        ->toContain('useradd')
        ->toContain('/home/orbit')
        ->toContain('orbit-gateway-entrypoint')
        ->toContain('orbit-gateway-healthcheck')
        ->toContain('HEALTHCHECK')
        ->not->toContain('COPY apps/gateway /app')
        ->not->toContain('COPY bin/install-orbit')
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
        ->toContain('!apps/gateway/.env.example')
        ->toContain('!apps/gateway/**')
        ->toContain('!packages/core/**')
        ->toContain('!docker/orbit-gateway/**');
});

it('runs FrankenPHP on the internal gateway port and exposes a local health probe', function (): void {
    $caddyfile = file_get_contents(repo_path('docker/orbit-gateway/Caddyfile'));
    $entrypoint = file_get_contents(repo_path('docker/orbit-gateway/entrypoint.sh'));
    $healthcheck = file_get_contents(repo_path('docker/orbit-gateway/healthcheck.sh'));

    expect($caddyfile)
        ->toContain('frankenphp')
        ->toContain(':8080')
        ->toContain('php_server')
        ->toContain('root * /srv/orbit/apps/gateway/public')
        ->and($entrypoint)
        ->toContain('ORBIT_CONFIG_ROOT')
        ->toContain('exec frankenphp run --config /etc/caddy/Caddyfile --adapter caddyfile')
        ->and($healthcheck)
        ->toContain('http://127.0.0.1:8080/up');
});
