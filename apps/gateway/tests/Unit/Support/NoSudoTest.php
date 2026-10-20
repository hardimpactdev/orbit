<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\LinuxHost;

pest()->group('subprocess');

it('fails ordinary cases that call sudo even when they ignore the process failure', function (bool $cleanEnvironment): void {
    $arguments = ['tests/Fixtures/no-sudo.php', '--no-tia', '--compact', '--colors=never'];
    $process = new Process($cleanEnvironment
        ? ['bash', '-c', LinuxHost::testCommand($arguments)]
        : [PHP_BINARY, 'vendor/bin/pest', ...$arguments], dirname(__DIR__, 3), timeout: 60);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toContain('9 failed', 'A non-privileged Gateway test called sudo:');
})->with(['inherited environment' => false, 'Linux host clean environment' => true]);
