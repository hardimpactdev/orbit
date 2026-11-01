<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('preserves native policy scopes, runtime history and credential file transport', function (): void {
    $process = new Process(['python3', dirname(__DIR__, 2).'/Fixtures/topology_swamp_test.py'], env: ['PYTHONDONTWRITEBYTECODE' => '1']);
    $process->mustRun();
    expect($process->getErrorOutput())->toContain('Ran 8 tests', 'OK');
});
