<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('keeps ordinary stock Pest shards complete and enforces the no-sudo boundary', function (): void {
    $repository = dirname(__DIR__, 5);
    $process = new Process(
        ['python3', '-I', dirname(__DIR__, 2).'/Fixtures/check-pest-shards-tests.py'],
        $repository,
        ['PYTHONDONTWRITEBYTECODE' => '1', 'GITHUB_OUTPUT' => false, 'ORBIT_TIA_DIRECTORY' => false],
    );
    $process->setTimeout(180);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());
});
