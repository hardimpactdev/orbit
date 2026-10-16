<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

pest()->group('subprocess');

it('merges independent ADR and endpoint changes in both orders with fresh generated contracts', function (): void {
    $process = new Process(['python3', base_path('tests/generated_merge_test.py')]);
    $process->setTimeout(600);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
});
