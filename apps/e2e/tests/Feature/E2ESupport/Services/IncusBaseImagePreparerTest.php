<?php

declare(strict_types=1);

use App\E2E\Support\IncusHost;
use App\Services\E2E\IncusBaseImagePreparationOptions;
use App\Services\E2E\IncusBaseImagePreparer;
use Illuminate\Contracts\Process\ProcessResult;
use Mockery as m;
use Orbit\Core\Php\PhpCliArtifactCatalog;

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

            if (
                str_contains($command, 'bash -lc')
                && str_contains($command, 'docker.io')
                && str_contains($command, 'orbit-e2e-docker-swarm-init.service')
            ) {
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
    // Matrix cutover: shared base installs Orbit-owned standard artifacts (no bulk, no PCOV).
    expect($bootstrapScript)->not->toContain('dl.static-php.dev/static-php-cli/bulk');
    expect($bootstrapScript)->toContain('https://s3.hardimpact.dev/orbit/runtimes/php-cli/sqlite-3.44.6/');
    expect($bootstrapScript)->toContain('php-8.5.8-cli-standard-linux-');
    expect($bootstrapScript)->toContain('php-8.4.21-cli-standard-linux-');
    expect($bootstrapScript)->toContain('php-8.3.31-cli-standard-linux-');
    expect($bootstrapScript)->toContain('40a7d8144d5e90a7ce8d2cd12fc86758acef8dedc4f95025dee56d1b3a6ddf15');
    expect($bootstrapScript)->toContain('cfc0a7a9c22280a2eda800b3f3bf6f674b0cbe8e74c6d677d2e6ac21c3461859');
    expect($bootstrapScript)->toContain('524db47fbfae402a338dd63ab0c16064e34054790fd892a022fef22539030bf1');
    expect($bootstrapScript)->toContain('/opt/orbit/php/');
    expect($bootstrapScript)->toContain('ln -sf /opt/orbit/php/8.5/bin/php /usr/local/bin/php');
    expect($bootstrapScript)->toContain('exit(extension_loaded("pcov") ? 1 : 0)');
    expect($bootstrapScript)->toContain('shared orbit base image must not expose pcov');
    expect($bootstrapScript)->not->toContain('php-8.5.8-cli-coverage-');
    expect($bootstrapScript)->not->toContain('exit(extension_loaded("pcov") ? 0 : 1)');
    expect($bootstrapScript)->not->toContain('pcov.enabled');
    expect($bootstrapScript)->toContain('https://getcomposer.org/installer');
    expect($bootstrapScript)->toContain('php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer');
    expect($bootstrapScript)->toContain('https://cli.github.com/packages/githubcli-archive-keyring.gpg');
    expect($bootstrapScript)->toContain('apt-get install -y -qq gh');
    expect($bootstrapScript)->toContain('composer global require laravel/installer');
    expect($bootstrapScript)->toContain(
        'ln -sf /home/orbit/.config/composer/vendor/bin/laravel /usr/local/bin/laravel',
    );
    expect($bootstrapScript)->toContain('docker swarm init');
    expect($bootstrapScript)->toContain('docker pull "$image"');
    expect($bootstrapScript)->toContain('caddy:2-alpine');
    expect($bootstrapScript)->toContain('ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm');
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

it('switches the shared base to Orbit standard matrix artifacts after matrix promotion', function (): void {
    $runtime = json_decode(
        (string) file_get_contents(repo_path('packages/core/resources/php-cli/artifact-catalog.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $sha = str_repeat('22', 32);
    foreach ($runtime['matrix']['artifacts'] as $patch => $variants) {
        foreach ($variants as $variant => $platforms) {
            foreach (array_keys($platforms) as $platform) {
                $runtime['matrix']['artifacts'][$patch][$variant][$platform] = $sha;
            }
        }
    }
    $runtime['install_contract'] = 'matrix';
    $runtime['publication'] = [
        'status' => 'published',
        'published_count' => 9,
        'total_count' => 9,
    ];

    $path = sys_get_temp_dir().'/e2e-php-cli-matrix-'.bin2hex(random_bytes(3)).'.json';
    file_put_contents($path, json_encode($runtime, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

    $catalog = PhpCliArtifactCatalog::load($path);
    $preparer = new IncusBaseImagePreparer(m::mock(IncusHost::class));
    $method = new ReflectionMethod($preparer, 'phpCliInstallBootstrapFragment');
    $method->setAccessible(true);
    $script = $method->invoke($preparer, $catalog);

    expect($script)
        ->toContain('php-8.5.8-cli-standard-linux-')
        ->toContain('php-8.4.21-cli-standard-linux-')
        ->toContain('php-8.3.31-cli-standard-linux-')
        ->toContain($sha)
        ->toContain('https://s3.hardimpact.dev/orbit/runtimes/php-cli/sqlite-3.44.6/')
        ->toContain('shared orbit base image must not expose pcov')
        ->not->toContain('php-8.5.8-cli-coverage-')
        ->not->toContain('dl.static-php.dev/static-php-cli/bulk');
});

it('does not import gateway tool definitions across the e2e architecture boundary', function (): void {
    $path = app_path('Services/E2E/IncusBaseImagePreparer.php');
    expect($path)->toBeFile();
    $source = (string) file_get_contents($path);

    expect($source)
        ->not->toMatch('/^use\s+App\\\\Tools\\\\/m')
        ->not->toMatch('/\\\\App\\\\Tools\\\\PhpCliTool\b/')
        ->not->toMatch('/\bnew\s+\\\\?App\\\\Tools\\\\PhpCliTool\b/')
        ->not->toContain('apps/gateway/app/Tools')->toContain('Orbit\\Core\\Php\\PhpCliArtifactCatalog')->toContain(
            'PhpCliVariant::Standard',
        );
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
