<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    Process::preventStrayProcesses();
});

it('documents docker runtime image preparation without force', function (): void {
    Process::fake();

    $this
        ->artisan('e2e:prepare-docker-runtime')
        ->expectsOutputToContain('orbit-e2e-topology-runtime:prepared-current')
        ->expectsOutputToContain('orbit-gateway:prepared-current')
        ->expectsOutputToContain('orbit-reverb:current')
        ->expectsOutputToContain('Orbit CLI binary artifact')
        ->expectsOutputToContain('caddy:2-alpine')
        ->expectsOutputToContain('ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm')
        ->expectsOutputToContain('ghcr.io/hardimpactdev/orbit-frankenphp:1-php8.4-bookworm')
        ->expectsOutputToContain('ghcr.io/hardimpactdev/orbit-frankenphp:1-php8.3-bookworm')
        ->expectsOutputToContain('composer:2')
        ->expectsOutputToContain('Dry run')
        ->assertExitCode(0);

    Process::assertNothingRan();
});

it('builds the topology and gateway images and pulls the official Caddy image when forced', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = (string) $process->command;

        return Process::result();
    });

    $this
        ->artisan('e2e:prepare-docker-runtime', ['--force' => true])
        ->expectsOutputToContain('Built orbit-e2e-topology-runtime:prepared-current.')
        ->expectsOutputToContain('Built orbit-gateway:prepared-current.')
        ->expectsOutputToContain('Built orbit-reverb:current.')
        ->expectsOutputToContain('Prepared Orbit CLI binary artifact')
        ->expectsOutputToContain('Pulled caddy:2-alpine.')
        ->expectsOutputToContain('Pulled ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm.')
        ->expectsOutputToContain('Pulled ghcr.io/hardimpactdev/orbit-frankenphp:1-php8.4-bookworm.')
        ->expectsOutputToContain('Pulled ghcr.io/hardimpactdev/orbit-frankenphp:1-php8.3-bookworm.')
        ->expectsOutputToContain('Pulled composer:2.')
        ->assertSuccessful();

    Process::assertRan(
        fn ($process): bool => (
            is_string($process->command)
            && str_contains($process->command, 'docker build')
            && str_contains($process->command, 'docker/e2e/topology/Dockerfile')
            && str_contains($process->command, 'orbit-e2e-topology-runtime:prepared-current')
            && str_contains($process->command, repo_path())
        ),
    );

    Process::assertRan(
        fn ($process): bool => (
            is_string($process->command)
            && str_contains($process->command, 'docker build')
            && str_contains($process->command, 'docker/orbit-gateway/Dockerfile')
            && str_contains($process->command, 'orbit-gateway:prepared-current')
            && str_contains($process->command, repo_path())
        ),
    );

    Process::assertRan(
        fn ($process): bool => (
            is_string($process->command)
            && str_contains($process->command, 'docker build')
            && str_contains($process->command, 'docker/orbit-reverb/Dockerfile')
            && str_contains($process->command, 'orbit-reverb:current')
            && str_contains($process->command, repo_path())
        ),
    );

    $retiredRuntimeContext = 'docker/orbit'.'-runtime';

    Process::assertNotRan(
        fn ($process): bool => is_string($process->command) && str_contains($process->command, $retiredRuntimeContext),
    );

    Process::assertRan(
        fn ($process): bool => (
            is_string($process->command)
            && str_contains($process->command, 'docker pull')
            && str_contains($process->command, "'caddy:2-alpine'")
        ),
    );

    Process::assertRan(
        fn ($process): bool => (
            is_string($process->command)
            && str_contains($process->command, 'docker pull')
            && str_contains($process->command, "'ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm'")
        ),
    );

    Process::assertRan(
        fn ($process): bool => (
            is_string($process->command)
            && str_contains($process->command, 'docker pull')
            && str_contains($process->command, "'ghcr.io/hardimpactdev/orbit-frankenphp:1-php8.4-bookworm'")
        ),
    );

    Process::assertRan(
        fn ($process): bool => (
            is_string($process->command)
            && str_contains($process->command, 'docker pull')
            && str_contains($process->command, "'ghcr.io/hardimpactdev/orbit-frankenphp:1-php8.3-bookworm'")
        ),
    );

    Process::assertRan(
        fn ($process): bool => (
            is_string($process->command)
            && str_contains($process->command, 'docker pull')
            && str_contains($process->command, "'composer:2'")
        ),
    );

    Process::assertNotRan(
        fn ($process): bool => (
            is_string($process->command)
            && str_contains($process->command, 'docker build')
            && str_contains($process->command, 'caddy:2-alpine')
        ),
    );

    $gatewayBuildCommand = collect($commands)->first(
        fn (string $command): bool => str_contains($command, 'docker/orbit-gateway/Dockerfile'),
    );
    $webSocketBuildCommand = collect($commands)->first(
        fn (string $command): bool => str_contains($command, 'docker/orbit-reverb/Dockerfile'),
    );

    expect($gatewayBuildCommand)
        ->toContain('orbit-gateway:prepared-current')
        ->not->toContain('apps/cli/orbit')->and($webSocketBuildCommand)->toContain('orbit-reverb:current')->and(implode(
            "\n",
            $commands,
        ))
        ->not->toContain('orbit'.'-runtime')
        ->not->toContain('apps/cli/orbit');
});

it('records the production artifact manifest with gateway image and cli hash metadata', function (): void {
    $binary = repo_path('apps/cli/builds/e2e-artifact-prod/orbit-binary');

    if (! is_dir(dirname($binary))) {
        mkdir(dirname($binary), 0755, true);
    }

    file_put_contents($binary, 'fake-linux-binary');
    chmod($binary, 0755);

    Process::fake(function ($process) {
        if (
            str_contains((string) $process->command, 'docker image inspect')
            && str_contains((string) $process->command, 'orbit-gateway:prepared-current')
        ) {
            return Process::result(output: 'sha256:'.str_repeat('a', 64)."\n");
        }

        if (str_contains((string) $process->command, 'git rev-parse')) {
            return Process::result(output: "abc1234\n");
        }

        return Process::result();
    });

    $this->withoutMockingConsoleOutput();

    $exitCode = Artisan::call('e2e:prepare-docker-runtime', [
        '--force' => true,
        '--json' => true,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0);

    $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

    expect($payload['success']['data']['artifacts'])->toMatchArray([
        'version' => 'abc1234',
        'gateway_image' => 'orbit-gateway:prepared-current',
        'gateway_image_id' => 'sha256:'.str_repeat('a', 64),
        'gateway_image_archive' => 'orbit-gateway-current.tar',
        'cli_binary' => $binary,
        'cli_binary_sha256' => hash_file('sha256', $binary),
    ]);

    @unlink($binary);
});

it('keeps the Caddy image local so docker run --pull never can start the container on a fresh node', function (): void {
    Process::fake([
        '*' => Process::result(),
    ]);

    $this
        ->artisan('e2e:prepare-docker-runtime', ['--force' => true])
        ->expectsOutputToContain('Pulled caddy:2-alpine.')
        ->expectsOutputToContain('Pulled ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm.')
        ->expectsOutputToContain('Pulled ghcr.io/hardimpactdev/orbit-frankenphp:1-php8.4-bookworm.')
        ->expectsOutputToContain('Pulled ghcr.io/hardimpactdev/orbit-frankenphp:1-php8.3-bookworm.')
        ->expectsOutputToContain('Pulled composer:2.')
        ->assertSuccessful();

    Process::assertRan(
        fn ($process): bool => (
            is_string($process->command)
            && str_contains($process->command, 'docker pull')
            && str_contains($process->command, "'caddy:2-alpine'")
        ),
    );

    Process::assertRan(
        fn ($process): bool => (
            is_string($process->command)
            && str_contains($process->command, 'docker pull')
            && str_contains($process->command, "'ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm'")
        ),
    );

    Process::assertRan(
        fn ($process): bool => (
            is_string($process->command)
            && str_contains($process->command, 'docker pull')
            && str_contains($process->command, "'ghcr.io/hardimpactdev/orbit-frankenphp:1-php8.4-bookworm'")
        ),
    );

    Process::assertRan(
        fn ($process): bool => (
            is_string($process->command)
            && str_contains($process->command, 'docker pull')
            && str_contains($process->command, "'ghcr.io/hardimpactdev/orbit-frankenphp:1-php8.3-bookworm'")
        ),
    );
});

it('keeps the Docker topology runtime image source-less without a baked orbit launcher', function (): void {
    $dockerfile = file_get_contents(repo_path('docker/e2e/topology/Dockerfile'));

    expect($dockerfile)
        ->toContain('LABEL org.orbit.e2e.source="prepared-checkout"')
        ->not->toContain('> /usr/local/bin/orbit \\')
        ->not->toContain('sudo docker exec')
        ->not->toContain('sudo docker network connect')
        ->not->toContain('--env "ORBIT_HOST_CWD=$PWD"')
        ->not->toContain('--env "ORBIT_SOURCE_PATH=$PWD"')
        ->not->toContain('--env "ORBIT_'.'RUNTIME_CONTAINER=$runtime_container"')
        ->not->toContain('${ORBIT_'.'RUNTIME_CONTAINER:-orbit'.'-runtime}')
        ->not->toContain('exec php "$PWD/artisan" "$@"')
        ->not->toContain('exec php "$HOME/orbit/artisan" "$@"');
});

it('starts sshd for gateway to app node remote shell coverage', function (): void {
    $dockerfile = file_get_contents(repo_path('docker/e2e/topology/Dockerfile'));

    expect($dockerfile)
        ->toContain('openssh-client')
        ->toContain('openssh-server')
        ->toContain('/usr/sbin/sshd -D')
        ->toContain('CMD ["/usr/local/bin/orbit-e2e-container"]')
        ->not->toContain('[program:sshd]');
});

it('uses a Docker CLI host substrate without host PHP Composer Caddy PHP-FPM or Supervisor', function (): void {
    $dockerfile = file_get_contents(repo_path('docker/e2e/topology/Dockerfile'));

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

    $this
        ->artisan('e2e:prepare-docker-runtime', ['--force' => true])
        ->expectsOutputToContain('docker build failed')
        ->assertFailed();
});
