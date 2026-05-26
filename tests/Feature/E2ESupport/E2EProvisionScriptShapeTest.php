<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    Process::preventStrayProcesses();
});

function provisionScript(): string
{
    return base_path('bin/e2e-provision-node');
}

function depsScript(): string
{
    return base_path('bin/_e2e-deps.sh');
}

function installerScript(): string
{
    return base_path('bin/install-orbit');
}

it('ships an executable provisioner script', function (): void {
    $script = provisionScript();

    expect(is_file($script))->toBeTrue();
    expect(is_executable($script))->toBeTrue();
});

it('ships an executable deps helper', function (): void {
    $script = depsScript();

    expect(is_file($script))->toBeTrue();
    expect(is_executable($script))->toBeTrue();
});

it('prints help with --help', function (): void {
    $result = Process::run([provisionScript(), '--help']);

    expect($result->successful())->toBeTrue();
    expect($result->output())->toContain('usage: bin/e2e-provision-node');
    expect($result->output())->toContain('--role=operator|gateway|app');
    expect($result->output())->toContain('--source-archive=PATH');
    expect($result->output())->toContain('--runtime-image-archive=PATH');
    expect($result->output())->toContain('--caddy-image-archive=PATH');
    expect($result->output())->toContain('--dnsmasq-image-archive=PATH');
    expect($result->output())->toContain('Topology role being installed');
});

it('runs install-orbit without role semantics', function (): void {
    $provisioner = file_get_contents(provisionScript());
    $installer = file_get_contents(installerScript());

    expect($provisioner)->not->toContain('"--role=${ROLE}"')
        ->and($provisioner)->not->toContain('--skip-prerequisites')
        ->and($provisioner)->toContain('COMPOSER_HOME=')
        ->and($installer)->toContain('ORBIT_INSTALL_SKIP_PREREQUISITES')
        ->and($installer)->toContain('--skip-prerequisites');
});

it('installs E2E base dependencies before running install-orbit', function (): void {
    $provisioner = file_get_contents(provisionScript());

    expect($provisioner)
        ->toContain('install_e2e_dependencies')
        ->toContain('"${SCRIPT_DIR}/_e2e-deps.sh" --base')
        ->toContain('systemctl enable --now supervisor.service');
});

it('keeps the E2E dependency helper off the SQLite CLI', function (): void {
    $basePackages = Process::run([depsScript(), '--base']);
    $phpPackages = Process::run([depsScript(), '--php']);

    expect($basePackages->successful())->toBeTrue()
        ->and($basePackages->output())->not->toMatch('/^sqlite3$/m')
        ->and($phpPackages->successful())->toBeTrue()
        ->and($phpPackages->output())->toContain('php8.5-sqlite3');
});

it('forwards staged Docker image archives without duplicating them in the guest tmp directory', function (): void {
    $provisioner = file_get_contents(provisionScript());

    expect($provisioner)
        ->toContain('runtime_image_args=(--runtime-image-archive="$RUNTIME_IMAGE_ARCHIVE")')
        ->toContain('caddy_image_args=(--caddy-image-archive="$CADDY_IMAGE_ARCHIVE")')
        ->toContain('dnsmasq_image_args=(--dnsmasq-image-archive="$DNSMASQ_IMAGE_ARCHIVE")')
        ->not->toContain('/tmp/orbit-runtime-image.tar')
        ->not->toContain('/tmp/orbit-caddy-image.tar');
});

it('fails when --role is missing', function (): void {
    $result = Process::run([provisionScript()]);

    expect($result->successful())->toBeFalse();
    expect($result->errorOutput())->toContain('--role is required');
});

it('fails when --role is invalid', function (): void {
    $result = Process::run([provisionScript(), '--role=invalid', '--source-archive=/tmp/missing']);

    expect($result->successful())->toBeFalse();
    expect($result->errorOutput())->toContain('--role must be: operator, gateway, or app');
});

it('fails when --source-archive is missing', function (): void {
    $result = Process::run([provisionScript(), '--role=operator']);

    expect($result->successful())->toBeFalse();
    expect($result->errorOutput())->toContain('--source-archive is required');
});

it('fails when source archive does not exist', function (): void {
    $result = Process::run([provisionScript(), '--role=operator', '--source-archive=/tmp/orbit-does-not-exist.tar.gz']);

    expect($result->successful())->toBeFalse();
    expect($result->errorOutput())->toContain('source archive not found');
});

it('fails when caddy image archive does not exist', function (): void {
    $source = tempnam(sys_get_temp_dir(), 'orbit-provision-source-');

    try {
        $result = Process::run([
            provisionScript(),
            '--role=operator',
            "--source-archive={$source}",
            '--caddy-image-archive=/tmp/orbit-caddy-does-not-exist.tar',
        ]);

        expect($result->successful())->toBeFalse();
        expect($result->errorOutput())->toContain('caddy image archive not found');
    } finally {
        @unlink($source);
    }
});

it('fails when runtime image archive does not exist', function (): void {
    $source = tempnam(sys_get_temp_dir(), 'orbit-provision-source-');

    try {
        $result = Process::run([
            provisionScript(),
            '--role=operator',
            "--source-archive={$source}",
            '--runtime-image-archive=/tmp/orbit-runtime-does-not-exist.tar',
        ]);

        expect($result->successful())->toBeFalse();
        expect($result->errorOutput())->toContain('runtime image archive not found');
    } finally {
        @unlink($source);
    }
});

it('fails when dnsmasq image archive does not exist', function (): void {
    $source = tempnam(sys_get_temp_dir(), 'orbit-provision-source-');

    try {
        $result = Process::run([
            provisionScript(),
            '--role=operator',
            "--source-archive={$source}",
            '--dnsmasq-image-archive=/tmp/orbit-dnsmasq-does-not-exist.tar',
        ]);

        expect($result->successful())->toBeFalse();
        expect($result->errorOutput())->toContain('dnsmasq image archive not found');
    } finally {
        @unlink($source);
    }
});

it('fails for unknown options', function (): void {
    $result = Process::run([provisionScript(), '--role=operator', '--source-archive=/tmp/x', '--mystery=1']);

    expect($result->successful())->toBeFalse();
    expect($result->errorOutput())->toContain('unknown option: --mystery=1');
});

it('deps helper prints all packages by default', function (): void {
    $result = Process::run([depsScript()]);

    expect($result->successful())->toBeTrue();

    $lines = array_filter(array_map('trim', explode("\n", $result->output())));

    expect($lines)->toContain('ca-certificates', 'composer', 'git', 'supervisor', 'wireguard');
    expect($lines)->toContain('php8.5-cli', 'php8.5-bcmath', 'php8.5-zip');
});

it('deps helper prints only base packages with --base', function (): void {
    $result = Process::run([depsScript(), '--base']);

    expect($result->successful())->toBeTrue();

    $lines = array_filter(array_map('trim', explode("\n", $result->output())));

    expect($lines)->toContain('ca-certificates');
    expect($lines)->not->toContain('php8.5-cli');
});

it('deps helper prints only php packages with --php', function (): void {
    $result = Process::run([depsScript(), '--php']);

    expect($result->successful())->toBeTrue();

    $lines = array_filter(array_map('trim', explode("\n", $result->output())));

    expect($lines)->toContain('php8.5-cli');
    expect($lines)->not->toContain('ca-certificates');
});

it('deps helper rejects unknown selectors', function (): void {
    $result = Process::run([depsScript(), '--mystery']);

    expect($result->successful())->toBeFalse();
    expect($result->errorOutput())->toContain('usage:');
});
