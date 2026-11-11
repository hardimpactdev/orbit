<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('runs every Docs Python contract', function (): void {
    $process = new Process(['python3', '-m', 'unittest', 'discover', '-s', base_path('tests'), '-p', '*_test.py']);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput())
        ->and($process->getErrorOutput())->toMatch('/Ran [1-9][0-9]* tests?/');
});
