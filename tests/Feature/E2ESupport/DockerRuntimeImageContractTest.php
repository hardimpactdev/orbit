<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

pest()->group('e2e', 'e2e-docker-image-contract');

it('does not ship persisted orbit certificate material in the runtime image', function (): void {
    $availability = new Process([
        'docker',
        'image',
        'inspect',
        'orbit-e2e-topology-runtime:current',
    ]);

    $availability->run();

    if ($availability->getExitCode() !== 0) {
        test()->markTestSkipped('Docker runtime image orbit-e2e-topology-runtime:current is not available.');
    }

    $forbiddenPaths = [
        '/opt/orbit-source/storage/app/orbit/ca',
        '/opt/orbit-source/storage/app/orbit/certs',
        '/opt/orbit-source/storage/app/orbit/keys',
        '/home/control/orbit/storage/app/orbit/ca',
        '/home/control/orbit/storage/app/orbit/certs',
        '/home/control/orbit/storage/app/orbit/keys',
        '/home/orbit/orbit/storage/app/orbit/ca',
        '/home/orbit/orbit/storage/app/orbit/certs',
        '/home/orbit/orbit/storage/app/orbit/keys',
    ];

    $assertions = collect($forbiddenPaths)
        ->map(fn (string $path): string => sprintf('test ! -e %s || { echo "FORBIDDEN PATH PRESENT: %s"; exit 1; }', escapeshellarg($path), $path))
        ->implode('; ');

    $process = new Process([
        'docker',
        'run',
        '--rm',
        'orbit-e2e-topology-runtime:current',
        'bash',
        '-c',
        sprintf('set -e; %s; echo OK', $assertions),
    ]);

    $process->run();

    expect($process->getExitCode())
        ->toBe(0, $process->getOutput().$process->getErrorOutput())
        ->and($process->getOutput())
        ->toContain('OK');
});
