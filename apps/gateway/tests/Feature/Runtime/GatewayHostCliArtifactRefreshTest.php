<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

it('executes the host CLI install script and exposes the exact artifact to a real gateway container', function (): void {
    $docker = new Process(['docker', 'info']);
    $docker->run();

    if (! $docker->isSuccessful()) {
        $this->markTestSkipped('Docker is required for the gateway host CLI artifact regression.');
    }

    $image = getenv('ORBIT_GATEWAY_TEST_IMAGE');
    $image = is_string($image) && $image !== ''
        ? $image
        : 'orbit-gateway:prepared-current';
    $inspect = new Process(['docker', 'image', 'inspect', $image]);
    $inspect->run();

    if (! $inspect->isSuccessful()) {
        $this->markTestSkipped("Gateway test image '{$image}' is unavailable.");
    }

    $workspace = storage_path('framework/testing/orbit-gateway-host-cli-'.bin2hex(random_bytes(6)));
    $homeDirectory = "{$workspace}/home/orbit";
    $installRoot = "{$homeDirectory}/orbit";
    $artifact = "{$installRoot}/candidate";
    $container = 'orbit-gateway-host-cli-'.bin2hex(random_bytes(6));

    File::ensureDirectoryExists($installRoot);
    File::put($artifact, "#!/usr/bin/env sh\necho candidate-artifact\n");

    $sha256 = hash_file('sha256', $artifact);

    try {
        gateway_host_cli_install_artifact(
            image: $image,
            artifactUrl: 'file:///mnt/orbit-install/candidate',
            sha256: $sha256,
            installRoot: $installRoot,
            homeDirectory: $homeDirectory,
        );

        gateway_host_cli_start_container($container, $image, "{$installRoot}/bin/orbit-binary");

        expect(gateway_host_cli_container_sha($container))
            ->toBe($sha256);
    } finally {
        gateway_host_cli_remove_container($container);
        File::deleteDirectory($workspace);
    }
})->group('slow', 'gateway-container');

function gateway_host_cli_install_artifact(
    string $image,
    string $artifactUrl,
    string $sha256,
    string $installRoot,
    string $homeDirectory,
): void {
    $binPath = "{$homeDirectory}/.local/bin/orbit";
    $relativeBinPath = substr($binPath, offset: strlen($homeDirectory) + 1);
    $shaPrefix = substr($sha256, offset: 0, length: 12);
    $versionedHostPath = "{$installRoot}/bin/orbit-binary-{$shaPrefix}";
    $containerBinPath = '/mnt/orbit-home/'.$relativeBinPath;
    $script = implode("\n", [
        'set -euo pipefail',
        'artifact="$(mktemp /tmp/orbit-host-cli.XXXXXX)"',
        'trap \'rm -f "$artifact"\' EXIT',
        'curl -fksSL '.escapeshellarg($artifactUrl).' -o "$artifact"',
        'printf "%s  %s\\n" '.escapeshellarg($sha256).' "$artifact" | sha256sum -c -',
        'install -d -m 0755 /mnt/orbit-install/bin '.escapeshellarg(dirname($containerBinPath)),
        'install -m 0755 "$artifact" '.escapeshellarg("/mnt/orbit-install/bin/orbit-binary-{$shaPrefix}"),
        'ln -sfnT '.escapeshellarg($versionedHostPath).' /mnt/orbit-install/bin/orbit-binary',
        'ln -sfnT '.escapeshellarg($versionedHostPath).' '.escapeshellarg($containerBinPath),
        'test "$(readlink /mnt/orbit-install/bin/orbit-binary)" = '.escapeshellarg($versionedHostPath),
        'test "$(readlink '.escapeshellarg($containerBinPath).')" = '.escapeshellarg($versionedHostPath),
        '',
    ]);
    $install = new Process([
        'docker',
        'run',
        '--rm',
        '--interactive',
        '--entrypoint',
        'bash',
        '--mount',
        "type=bind,source={$installRoot},target=/mnt/orbit-install",
        '--mount',
        "type=bind,source={$homeDirectory},target=/mnt/orbit-home",
        $image,
        '-s',
    ]);
    $install->setInput($script);
    $install->setTimeout(60);
    $install->run();

    expect($install->isSuccessful())
        ->toBeTrue($install->getErrorOutput().$install->getOutput());
}

function gateway_host_cli_start_container(string $container, string $image, string $binary): void
{
    $resolvedBinary = realpath($binary);

    expect($resolvedBinary)
        ->toBeString();

    $start = new Process([
        'docker',
        'run',
        '--rm',
        '--detach',
        '--name',
        $container,
        '--mount',
        "type=bind,source={$resolvedBinary},target=/usr/local/bin/orbit-cli,readonly",
        '--entrypoint',
        'tail',
        $image,
        '-f',
        '/dev/null',
    ]);
    $start->setTimeout(60);
    $start->run();

    expect($start->isSuccessful())
        ->toBeTrue($start->getErrorOutput());
}

function gateway_host_cli_container_sha(string $container): string
{
    $checksum = new Process(['docker', 'exec', $container, 'sha256sum', '/usr/local/bin/orbit-cli']);
    $checksum->run();

    expect($checksum->isSuccessful())
        ->toBeTrue($checksum->getErrorOutput());

    return explode(' ', trim($checksum->getOutput()))[0];
}

function gateway_host_cli_remove_container(string $container): void
{
    $remove = new Process(['docker', 'rm', '--force', $container]);
    $remove->run();
}
