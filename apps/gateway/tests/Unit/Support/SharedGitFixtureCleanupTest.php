<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('cleans frozen shared Git seeds before the stock Pest run directory is removed', function (): void {
    $process = new Process([
        '../../bin/pest-plain', PHP_BINARY, 'vendor/bin/pest',
        'tests/Feature/GatewayReleases/GatewayReleaseFixtureTest.php',
        '--filter=traversal-only', '--compact', '--colors=never',
    ], dirname(__DIR__, 3));
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
});
