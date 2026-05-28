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
        sourceImage: 'images:ubuntu/26.04/cloud',
        baseImageAlias: 'orbit-base-ubuntu-26.04',
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
        'alias' => 'orbit-base-ubuntu-26.04',
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

            if (str_starts_with(ltrim($command), 'cat > ')) {
                return fakeProcessResult();
            }

            if (str_starts_with(ltrim($command), 'cat ')) {
                return fakeProcessResult(output: "ssh-ed25519 AAAA fake-key\n");
            }

            if (str_contains($command, 'incus launch')) {
                $launches[] = $command;

                return fakeProcessResult();
            }

            if (str_contains($command, 'cloud-init status')) {
                return fakeProcessResult(output: "status: done\n");
            }

            if (str_contains($command, 'incus list') && str_contains($command, '-c 4')) {
                return fakeProcessResult(output: "10.0.0.5\n");
            }

            if (str_starts_with(ltrim($command), 'ssh ')) {
                return fakeProcessResult();
            }

            if (str_contains($command, 'incus exec') && str_contains($command, 'cloud-init clean')) {
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
    expect($result['alias'])->toBe('orbit-base-ubuntu-26.04');
    expect($result['action'])->toBe('built');
    expect($launches)->toHaveCount(1);
    expect($launches[0])->toContain("'images:ubuntu/26.04/cloud'");
    expect($launches[0])->toContain("'orbit-e2e-");
    expect($stops)->toHaveCount(1);
    expect($publishes)->toHaveCount(1);
    expect($publishes[0])->toContain("--alias 'orbit-base-ubuntu-26.04'");
});

it('writes cloud-init that installs the deps helper packages and the orbit user', function (): void {
    $host = m::mock(IncusHost::class);

    $cloudInitYaml = null;

    $host->shouldReceive('run')
        ->andReturnUsing(function (string $command) use (&$cloudInitYaml): ProcessResult {
            if (str_contains($command, 'mktemp -d')) {
                return fakeProcessResult(output: "/tmp/orbit-prep-base\n");
            }

            if (str_contains($command, 'incus image info')) {
                return fakeProcessResult();
            }

            if (str_contains($command, 'ssh-keygen')) {
                return fakeProcessResult();
            }

            if (str_starts_with(ltrim($command), 'cat > ')) {
                $cloudInitYaml = $command;

                return fakeProcessResult();
            }

            if (str_starts_with(ltrim($command), 'cat ')) {
                return fakeProcessResult(output: "ssh-ed25519 AAAA fake-key\n");
            }

            if (str_contains($command, 'incus list') && str_contains($command, '-c 4')) {
                return fakeProcessResult(output: "10.0.0.5\n");
            }

            if (str_contains($command, 'cloud-init status')) {
                return fakeProcessResult(output: "status: done\n");
            }

            return fakeProcessResult();
        });

    $preparer = new IncusBaseImagePreparer($host);
    $preparer->build(baseImageOptions(force: true));

    expect($cloudInitYaml)->not->toBeNull();
    expect($cloudInitYaml)->toContain('package_update: true');
    expect($cloudInitYaml)->toContain('uri: http://mirror.leaseweb.com/ubuntu');
    expect($cloudInitYaml)->toContain('Acquire::ForceIPv4');
    expect($cloudInitYaml)->toContain('Acquire::http::Timeout');
    expect($cloudInitYaml)->toContain('disable_suites:');
    expect($cloudInitYaml)->toContain('    - security');
    expect($cloudInitYaml)->toContain('  - composer');
    expect($cloudInitYaml)->toContain('  - bind9-dnsutils');
    expect($cloudInitYaml)->toContain('  - ufw');
    expect($cloudInitYaml)->toContain('  - wireguard');
    expect($cloudInitYaml)->toContain('  - php8.5-cli');
    expect($cloudInitYaml)->toContain('  - php8.5-bcmath');
    expect($cloudInitYaml)->toContain('name: provisioner');
    expect($cloudInitYaml)->toContain('name: orbit');
    expect($cloudInitYaml)->toContain('install -d -m 700 -o orbit -g orbit /home/orbit/.ssh');
    expect($cloudInitYaml)->toContain('install -d -m 755 -o orbit -g orbit /home/orbit/.config/orbit');
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
        ->toThrow(RuntimeException::class, 'Source image [images:ubuntu/26.04/cloud]');
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
        sourceImage: 'images:ubuntu/26.04/cloud',
        baseImageAlias: 'orbit-base-ubuntu-26.04',
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
