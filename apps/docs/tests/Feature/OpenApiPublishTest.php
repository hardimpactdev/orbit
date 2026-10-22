<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('generates required publish fields within an optional pull request', function (): void {
    $process = new Process(['python3', '-m', 'unittest', 'discover', '-s', base_path('tests'), '-p', 'openapi_publish_test.py']);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
});
