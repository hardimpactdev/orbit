<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('keeps repository serving isolated from product Caddy and refuses conflicting resources', function (): void {
    $process = new Process(['python3', '-B', dirname(__DIR__, 2).'/Fixtures/topology_repositories_test.py']);

    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getErrorOutput())->toContain('Ran 4 tests', 'OK');
});
