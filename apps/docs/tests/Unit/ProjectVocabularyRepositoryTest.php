<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

pest()->group('subprocess');

it('accepts this repository', function (): void {
    $process = new Process([PHP_BINARY, dirname(__DIR__, 4).'/bin/project-vocabulary']);
    $process->setTimeout(60);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
});
