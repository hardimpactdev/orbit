<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    Process::preventStrayProcesses();
});

it('documents docker runtime image preparation without force', function (): void {
    Process::fake();

    $this->artisan('e2e:prepare-docker-runtime')
        ->expectsOutputToContain('orbit-e2e-topology-runtime:prepared-current')
        ->expectsOutputToContain('orbit-runtime:prepared-current')
        ->expectsOutputToContain('caddy:2-alpine')
        ->expectsOutputToContain('dunglas/frankenphp:1-php8.5-bookworm')
        ->expectsOutputToContain('dunglas/frankenphp:1-php8.4-bookworm')
        ->expectsOutputToContain('dunglas/frankenphp:1-php8.3-bookworm')
        ->expectsOutputToContain('composer:2')
        ->expectsOutputToContain('Dry run')
        ->assertExitCode(0);

    Process::assertNothingRan();
});

it('builds the orbit runtime images and pulls the official Caddy image when forced', function (): void {
    Process::fake([
        '*' => Process::result(),
    ]);

    $this->artisan('e2e:prepare-docker-runtime', ['--force' => true])
        ->expectsOutputToContain('Built orbit-e2e-topology-runtime:prepared-current.')
        ->expectsOutputToContain('Built orbit-runtime:prepared-current.')
        ->expectsOutputToContain('Pulled caddy:2-alpine.')
        ->expectsOutputToContain('Pulled dunglas/frankenphp:1-php8.5-bookworm.')
        ->expectsOutputToContain('Pulled dunglas/frankenphp:1-php8.4-bookworm.')
        ->expectsOutputToContain('Pulled dunglas/frankenphp:1-php8.3-bookworm.')
        ->expectsOutputToContain('Pulled composer:2.')
        ->assertSuccessful();

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'docker build')
        && str_contains($process->command, 'docker/e2e/topology/Dockerfile')
        && str_contains($process->command, 'orbit-e2e-topology-runtime:prepared-current')
        && str_contains($process->command, base_path()));

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'docker build')
        && str_contains($process->command, 'docker/orbit-runtime/Dockerfile')
        && str_contains($process->command, 'orbit-runtime:prepared-current')
        && str_contains($process->command, base_path()));

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'docker pull')
        && str_contains($process->command, "'caddy:2-alpine'"));

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'docker pull')
        && str_contains($process->command, "'dunglas/frankenphp:1-php8.5-bookworm'"));

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'docker pull')
        && str_contains($process->command, "'dunglas/frankenphp:1-php8.4-bookworm'"));

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'docker pull')
        && str_contains($process->command, "'dunglas/frankenphp:1-php8.3-bookworm'"));

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'docker pull')
        && str_contains($process->command, "'composer:2'"));

    Process::assertNotRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'docker build')
        && str_contains($process->command, 'caddy:2-alpine'));
});

it('keeps the Caddy image local so docker run --pull never can start the container on a fresh node', function (): void {
    Process::fake([
        '*' => Process::result(),
    ]);

    $this->artisan('e2e:prepare-docker-runtime', ['--force' => true])
        ->expectsOutputToContain('Pulled caddy:2-alpine.')
        ->expectsOutputToContain('Pulled dunglas/frankenphp:1-php8.5-bookworm.')
        ->expectsOutputToContain('Pulled dunglas/frankenphp:1-php8.4-bookworm.')
        ->expectsOutputToContain('Pulled dunglas/frankenphp:1-php8.3-bookworm.')
        ->expectsOutputToContain('Pulled composer:2.')
        ->assertSuccessful();

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'docker pull')
        && str_contains($process->command, "'caddy:2-alpine'"));

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'docker pull')
        && str_contains($process->command, "'dunglas/frankenphp:1-php8.5-bookworm'"));

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'docker pull')
        && str_contains($process->command, "'dunglas/frankenphp:1-php8.4-bookworm'"));

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'docker pull')
        && str_contains($process->command, "'dunglas/frankenphp:1-php8.3-bookworm'"));
});

it('keeps the Docker topology runtime image source-less without a baked orbit launcher', function (): void {
    $dockerfile = file_get_contents(base_path('docker/e2e/topology/Dockerfile'));

    expect($dockerfile)
        ->toContain('LABEL org.orbit.e2e.source="prepared-checkout"')
        ->not->toContain('> /usr/local/bin/orbit \\')
        ->not->toContain('sudo docker exec')
        ->not->toContain('sudo docker network connect')
        ->not->toContain('--env "ORBIT_HOST_CWD=$PWD"')
        ->not->toContain('--env "ORBIT_SOURCE_PATH=$PWD"')
        ->not->toContain('--env "ORBIT_RUNTIME_CONTAINER=$runtime_container"')
        ->not->toContain('${ORBIT_RUNTIME_CONTAINER:-orbit-runtime}')
        ->not->toContain('exec php "$PWD/artisan" "$@"')
        ->not->toContain('exec php "$HOME/orbit/artisan" "$@"');
});

it('starts sshd for gateway to app node remote shell coverage', function (): void {
    $dockerfile = file_get_contents(base_path('docker/e2e/topology/Dockerfile'));

    expect($dockerfile)
        ->toContain('openssh-client')
        ->toContain('openssh-server')
        ->toContain('/usr/sbin/sshd -D')
        ->toContain('CMD ["/usr/local/bin/orbit-e2e-container"]')
        ->not->toContain('[program:sshd]');
});

it('uses a Docker CLI host substrate without host PHP Composer Caddy PHP-FPM or Supervisor', function (): void {
    $dockerfile = file_get_contents(base_path('docker/e2e/topology/Dockerfile'));

    expect($dockerfile)
        ->toContain('docker.io')
        ->not->toContain('FROM php:')
        ->not->toContain('docker-php-ext-install')
        ->not->toContain('COPY --from=composer')
        ->not->toContain('composer install')
        ->not->toContain('supervisor')
        ->not->toContain('php-fpm')
        ->not->toContain('caddy')
        ->not->toContain('[program:orbit_scheduler]')
        ->not->toContain('command=/bin/bash -lc "php artisan orbit-scheduler --sleep-seconds=60"');
});

it('fails clearly when the docker build fails', function (): void {
    Process::fake([
        '*' => Process::result(errorOutput: 'docker build failed', exitCode: 1),
    ]);

    $this->artisan('e2e:prepare-docker-runtime', ['--force' => true])
        ->expectsOutputToContain('docker build failed')
        ->assertFailed();
});
