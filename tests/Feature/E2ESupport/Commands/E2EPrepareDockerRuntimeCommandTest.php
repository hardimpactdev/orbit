<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    Process::preventStrayProcesses();
});

it('documents docker runtime image preparation without force', function (): void {
    Process::fake();

    $this->artisan('e2e:prepare-docker-runtime')
        ->expectsOutputToContain('orbit-e2e-topology-runtime:current')
        ->expectsOutputToContain('orbit-runtime:current')
        ->expectsOutputToContain('Dry run')
        ->assertExitCode(0);

    Process::assertNothingRan();
});

it('builds the docker runtime image when forced', function (): void {
    Process::fake([
        '*' => Process::result(),
    ]);

    $this->artisan('e2e:prepare-docker-runtime', ['--force' => true])
        ->expectsOutputToContain('Built orbit-e2e-topology-runtime:current.')
        ->expectsOutputToContain('Built orbit-runtime:current.')
        ->assertSuccessful();

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'docker build')
        && str_contains($process->command, 'docker/e2e/topology/Dockerfile')
        && str_contains($process->command, 'orbit-e2e-topology-runtime:current')
        && str_contains($process->command, base_path()));

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'docker build')
        && str_contains($process->command, 'docker/orbit-runtime/Dockerfile')
        && str_contains($process->command, 'orbit-runtime:current')
        && str_contains($process->command, base_path()));
});

it('installs a Docker-first orbit launcher without a host PHP fallback', function (): void {
    $dockerfile = file_get_contents(base_path('docker/e2e/topology/Dockerfile'));

    expect($dockerfile)
        ->toContain('/usr/local/bin/orbit')
        ->toContain('sudo docker exec')
        ->toContain('sudo docker network connect')
        ->toContain('--env "ORBIT_HOST_CWD=$PWD"')
        ->toContain('--env "ORBIT_SOURCE_PATH=$PWD"')
        ->toContain('${ORBIT_RUNTIME_CONTAINER:-orbit-runtime}')
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
