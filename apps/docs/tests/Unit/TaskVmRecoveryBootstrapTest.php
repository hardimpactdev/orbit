<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

pest()->group('subprocess');

it('checks the isolated recovery launcher admission and native locks', function (): void {
    $root = dirname(__DIR__, 4);
    $process = new Process(['python3', '-m', 'unittest', 'discover', '-s', $root.'/bin/tests', '-p', 'task_vm_recovery_bootstrap_test.py', '-v']);
    $process->setTimeout(30);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getErrorOutput())->toContain('OK');
});
