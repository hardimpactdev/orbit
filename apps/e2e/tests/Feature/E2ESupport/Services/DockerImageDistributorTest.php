<?php

declare(strict_types=1);

use App\Services\E2E\DockerImageDistributor;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    Process::preventStrayProcesses();
});

it('exports docker images from the build host and imports them on target hosts', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        if (
            str_contains($process->command, 'mktemp -d')
            && str_contains($process->command, 'orbit-e2e-docker-image-export')
        ) {
            return Process::result(output: "/tmp/beast-export\n");
        }

        if (
            str_contains($process->command, 'mktemp -d')
            && str_contains($process->command, 'orbit-e2e-docker-image-import')
        ) {
            $host = str_contains($process->command, 'sidecar2') ? 'sidecar2' : 'sidecar1';

            return Process::result(output: "/tmp/{$host}-import\n");
        }

        return Process::result();
    });

    $distributor = new DockerImageDistributor('beast', timeoutSeconds: 600);

    $result = $distributor->distribute([
        ['role' => 'runtime', 'image' => 'orbit-e2e-topology-runtime:current'],
        ['role' => 'gateway', 'image' => 'orbit-e2e:gateway_base'],
    ], ['sidecar1', 'sidecar2']);

    expect($result)->toBe([
        [
            'host' => 'sidecar1',
            'role' => 'runtime',
            'image' => 'orbit-e2e-topology-runtime:current',
            'action' => 'imported',
        ],
        [
            'host' => 'sidecar1',
            'role' => 'gateway',
            'image' => 'orbit-e2e:gateway_base',
            'action' => 'imported',
        ],
        [
            'host' => 'sidecar2',
            'role' => 'runtime',
            'image' => 'orbit-e2e-topology-runtime:current',
            'action' => 'imported',
        ],
        [
            'host' => 'sidecar2',
            'role' => 'gateway',
            'image' => 'orbit-e2e:gateway_base',
            'action' => 'imported',
        ],
    ]);

    expect(collect($commands)->contains(
        fn (string $command): bool => (
            str_contains($command, 'ssh')
            && str_contains($command, 'beast')
            && str_contains($command, 'docker save')
        ),
    ))
        ->toBeTrue()
        ->and(collect($commands)->contains(
            fn (string $command): bool => (
                str_contains($command, 'docker save')
                && str_contains($command, 'orbit-e2e-topology-runtime:current')
                && str_contains($command, 'orbit-e2e:gateway_base')
            ),
        ))
        ->toBeTrue()
        ->and(collect($commands)->contains(
            fn (string $command): bool => (
                str_contains($command, 'scp')
                && str_contains($command, 'beast:')
                && str_contains($command, 'images.tar.gz')
            ),
        ))
        ->toBeTrue()
        ->and(collect($commands)->contains(
            fn (string $command): bool => (
                str_contains($command, 'scp')
                && str_contains($command, 'sidecar1:')
                && str_contains($command, 'images.tar.gz')
            ),
        ))
        ->toBeTrue()
        ->and(collect($commands)->contains(
            fn (string $command): bool => (
                str_contains($command, 'ssh')
                && str_contains($command, 'sidecar2')
                && str_contains($command, 'docker load')
            ),
        ))
        ->toBeTrue()
        ->and(collect($commands)->contains(fn (string $command): bool => str_contains($command, 'IdentitiesOnly=yes')))
        ->toBeFalse();
});

it('does not distribute images to the source host', function (): void {
    Process::fake(function ($process) {
        if (str_contains($process->command, 'mktemp -d')) {
            return Process::result(output: "/tmp/beast-export\n");
        }

        return Process::result();
    });

    $distributor = new DockerImageDistributor('beast', timeoutSeconds: 600);

    expect($distributor->distribute([
        ['role' => 'runtime', 'image' => 'orbit-e2e-topology-runtime:current'],
    ], ['beast']))->toBe([]);

    Process::assertNotRan(fn ($process): bool => str_contains($process->command, 'docker save'));
});
