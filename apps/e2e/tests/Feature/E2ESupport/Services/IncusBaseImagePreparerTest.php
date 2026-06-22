<?php

declare(strict_types=1);

use App\E2E\Support\IncusHost;
use App\Services\E2E\IncusBaseImagePreparationOptions;
use App\Services\E2E\IncusBaseImagePreparer;
use Illuminate\Contracts\Process\ProcessResult;
use Mockery as m;

afterEach(function (): void {
    m::close();
});

function baseImageOptions(bool $force = false): IncusBaseImagePreparationOptions
{
    return new IncusBaseImagePreparationOptions(
        force: $force,
        sourceImage: 'images:ubuntu/26.04',
        baseImageAlias: 'orbit-base-ubuntu-26.04-runtime',
        bootstrapUser: 'provisioner',
        cpus: 2,
        memory: '2GiB',
        timeoutSeconds: 600,
        depsScriptPath: repo_path('bin/_e2e-deps.sh'),
    );
}

function fakeProcessResult(bool $successful = true, string $output = '', string $errorOutput = ''): ProcessResult
{
    $result = m::mock(ProcessResult::class);
    $result->shouldReceive('successful')->andReturn($successful);
    $result->shouldReceive('failed')->andReturn(! $successful);
    $result->shouldReceive('output')->andReturn($output);
    $result->shouldReceive('errorOutput')->andReturn($errorOutput);
    $result->shouldReceive('exitCode')->andReturn($successful ? 0 : 1);

    return $result;
}

it('returns dry-run plan when force is false', function (): void {
    $host = m::mock(IncusHost::class);
    $preparer = new IncusBaseImagePreparer($host);

    $result = $preparer->build(baseImageOptions(force: false));

    expect($result)->toBe([
        'role' => 'base',
        'alias' => 'orbit-base-ubuntu-26.04-runtime',
        'action' => 'planned',
    ]);
});

it('builds the base image with the expected incus call sequence', function (): void {
    $host = m::mock(IncusHost::class);

    $launches = [];
    $publishes = [];
    $stops = [];

    $host->shouldReceive('run')
        ->andReturnUsing(function (string $command) use (&$launches, &$publishes, &$stops): ProcessResult {
            if (str_contains($command, 'mktemp -d')) {
                return fakeProcessResult(output: "/tmp/orbit-prep-base\n");
            }

            if (str_contains($command, 'incus image info')) {
                return fakeProcessResult();
            }

            if (str_contains($command, 'ssh-keygen')) {
                return fakeProcessResult();
            }

            if (str_starts_with(ltrim($command), 'cat ')) {
                return fakeProcessResult(output: "ssh-ed25519 AAAA fake-key\n");
            }

            if (str_contains($command, 'incus launch')) {
                $launches[] = $command;

                return fakeProcessResult();
            }

            if (str_contains($command, 'ip -o -4 addr show scope global')) {
                return fakeProcessResult(output: "10.0.0.5\n");
            }

            if (str_starts_with(ltrim($command), 'ssh ')) {
                return fakeProcessResult();
            }

            if (str_contains($command, 'incus exec')) {
                return fakeProcessResult();
            }

            if (str_contains($command, 'incus stop')) {
                $stops[] = $command;

                return fakeProcessResult();
            }

            if (str_contains($command, 'incus publish')) {
                $publishes[] = $command;

                return fakeProcessResult();
            }

            if (str_contains($command, 'incus delete')) {
                return fakeProcessResult();
            }

            if (str_contains($command, 'rm -rf')) {
                return fakeProcessResult();
            }

            return fakeProcessResult();
        });

    $preparer = new IncusBaseImagePreparer($host);
    $result = $preparer->build(baseImageOptions(force: true));

    expect($result['role'])->toBe('base');
    expect($result['alias'])->toBe('orbit-base-ubuntu-26.04-runtime');
    expect($result['action'])->toBe('built');
    expect($launches)->toHaveCount(1);
    expect($launches[0])->toContain("'images:ubuntu/26.04'");
    expect($launches[0])->toContain("'orbit-e2e-");
    expect($stops)->toHaveCount(1);
    expect($publishes)->toHaveCount(1);
    expect($publishes[0])->toContain("--alias 'orbit-base-ubuntu-26.04-runtime'");
});

it('bootstraps the runtime image without guest user-data', function (): void {
    $host = m::mock(IncusHost::class);

    $bootstrapScript = null;

    $host->shouldReceive('run')
        ->andReturnUsing(function (string $command) use (&$bootstrapScript): ProcessResult {
            if (str_contains($command, 'mktemp -d')) {
                return fakeProcessResult(output: "/tmp/orbit-prep-base\n");
            }

            if (str_contains($command, 'incus image info')) {
                return fakeProcessResult();
            }

            if (str_contains($command, 'ssh-keygen')) {
                return fakeProcessResult();
            }

            if (str_starts_with(ltrim($command), 'cat ')) {
                return fakeProcessResult(output: "ssh-ed25519 AAAA fake-key\n");
            }

            if (str_contains($command, 'bash -lc') && str_contains($command, 'docker.io') && str_contains($command, 'orbit-e2e-docker-swarm-init.service')) {
                $bootstrapScript = $command;

                return fakeProcessResult();
            }

            if (str_contains($command, 'ip -o -4 addr show scope global')) {
                return fakeProcessResult(output: "10.0.0.5\n");
            }

            return fakeProcessResult();
        });

    $preparer = new IncusBaseImagePreparer($host);
    $preparer->build(baseImageOptions(force: true));

    expect($bootstrapScript)->not->toBeNull();
    expect($bootstrapScript)->toContain('nameserver 1.1.1.1');
    expect($bootstrapScript)->toContain('getent hosts archive.ubuntu.com');
    expect($bootstrapScript)->toContain('Acquire::ForceIPv4');
    expect($bootstrapScript)->toContain('composer');
    expect($bootstrapScript)->toContain('bind9-dnsutils');
    expect($bootstrapScript)->toContain('ufw');
    expect($bootstrapScript)->toContain('wireguard');
    expect($bootstrapScript)->toContain('php8.5-cli');
    expect($bootstrapScript)->toContain('php8.5-bcmath');
    expect($bootstrapScript)->toContain('docker.io');
    expect($bootstrapScript)->toContain('static_php_arch=');
    expect($bootstrapScript)->toContain('https://dl.static-php.dev/static-php-cli/bulk/php-$php_patch-cli-linux-$static_php_arch.tar.gz');
    expect($bootstrapScript)->toContain('/opt/orbit/php/$php_minor/bin');
    expect($bootstrapScript)->toContain('ln -sf /opt/orbit/php/8.5/bin/php /usr/local/bin/php');
    expect($bootstrapScript)->toContain('https://getcomposer.org/installer');
    expect($bootstrapScript)->toContain('php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer');
    expect($bootstrapScript)->toContain('https://cli.github.com/packages/githubcli-archive-keyring.gpg');
    expect($bootstrapScript)->toContain('apt-get install -y -qq gh');
    expect($bootstrapScript)->toContain('composer global require laravel/installer');
    expect($bootstrapScript)->toContain('ln -sf /home/orbit/.config/composer/vendor/bin/laravel /usr/local/bin/laravel');
    expect($bootstrapScript)->toContain('docker swarm init');
    expect($bootstrapScript)->toContain('docker pull "$image"');
    expect($bootstrapScript)->toContain('caddy:2-alpine');
    expect($bootstrapScript)->toContain('ghcr.io/hardimpactdev/orbit-frankenphp:1-php8.5-bookworm');
    expect($bootstrapScript)->toContain('orbit-frankenphp-source-artisan:prepared-current');
    expect($bootstrapScript)->toContain('orbit-reverb:current');
    expect($bootstrapScript)->toContain('apt-get install -y --no-install-recommends openssh-client');
    expect($bootstrapScript)->toContain('php8.5-redis');
    expect($bootstrapScript)->toContain('docker build --pull=false');
    expect($bootstrapScript)->toContain('ghcr.io/wg-easy/wg-easy:15');
    expect($bootstrapScript)->toContain('bootstrap_user=');
    expect($bootstrapScript)->toContain('id -u orbit');
    expect($bootstrapScript)->toContain('install -d -m 700 -o orbit -g orbit /home/orbit/.ssh');
    expect($bootstrapScript)->toContain('/home/orbit/.config/composer');
    expect($bootstrapScript)->toContain('/home/orbit/.config/orbit');
    expect($bootstrapScript)->toContain('/opt/orbit/php/8.5/bin/php -r "echo PHP_VERSION;" >/dev/null');
    expect($bootstrapScript)->toContain('/usr/local/bin/composer --version >/dev/null');
    expect($bootstrapScript)->toContain('gh --version >/dev/null');
    expect($bootstrapScript)->toContain('cd /home/orbit && /usr/local/bin/laravel --version >/dev/null');
});

it('throws when the source image is not available', function (): void {
    $host = m::mock(IncusHost::class);

    $host->shouldReceive('run')
        ->andReturnUsing(function (string $command): ProcessResult {
            if (str_contains($command, 'mktemp -d')) {
                return fakeProcessResult(output: "/tmp/orbit-prep-base\n");
            }

            if (str_contains($command, 'incus image info')) {
                return fakeProcessResult(successful: false, errorOutput: 'not found');
            }

            return fakeProcessResult();
        });

    $preparer = new IncusBaseImagePreparer($host);

    expect(fn () => $preparer->build(baseImageOptions(force: true)))
        ->toThrow(RuntimeException::class, 'Source image [images:ubuntu/26.04]');
});

it('throws when the deps helper is missing', function (): void {
    $host = m::mock(IncusHost::class);

    $host->shouldReceive('run')
        ->andReturnUsing(function (string $command): ProcessResult {
            if (str_contains($command, 'mktemp -d')) {
                return fakeProcessResult(output: "/tmp/orbit-prep-base\n");
            }

            if (str_contains($command, 'incus image info')) {
                return fakeProcessResult();
            }

            if (str_contains($command, 'ssh-keygen')) {
                return fakeProcessResult();
            }

            if (str_starts_with(ltrim($command), 'cat ')) {
                return fakeProcessResult(output: "ssh-ed25519 AAAA fake-key\n");
            }

            return fakeProcessResult();
        });

    $options = new IncusBaseImagePreparationOptions(
        force: true,
        sourceImage: 'images:ubuntu/26.04',
        baseImageAlias: 'orbit-base-ubuntu-26.04-runtime',
        bootstrapUser: 'provisioner',
        cpus: 2,
        memory: '2GiB',
        timeoutSeconds: 600,
        depsScriptPath: '/tmp/does-not-exist',
    );

    $preparer = new IncusBaseImagePreparer($host);

    expect(fn () => $preparer->build($options))
        ->toThrow(RuntimeException::class, 'Deps helper not executable');
});
