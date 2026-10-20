<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

pest()->group('subprocess');

it('fails ordinary cases that call sudo even when they ignore the process failure', function (): void {
    $process = new Process([
        PHP_BINARY, 'vendor/bin/pest', 'tests/Fixtures/no-sudo.php',
        '--no-tia', '--compact', '--colors=never',
    ], dirname(__DIR__, 3), timeout: 60);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toContain('6 failed', 'A non-privileged Gateway test called sudo:');
});
