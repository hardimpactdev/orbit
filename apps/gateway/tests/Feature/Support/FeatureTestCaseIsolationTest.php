<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

pest()->group('subprocess');

it('gives native PHPUnit cases the same Feature isolation and cleanup as Pest', function (): void {
    $process = new Process([
        PHP_BINARY, 'vendor/bin/pest', 'tests/Fixtures/NativeFeatureIsolationTest.php',
        '--no-tia', '--fail-on-risky', '--colors=never',
    ], base_path());
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
    $summary = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
    expect($summary)->toMatchArray(['tool' => 'pest', 'result' => 'passed', 'tests' => 1, 'passed' => 1]);
});
