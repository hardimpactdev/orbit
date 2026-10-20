<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('keeps ordinary and privileged stock Pest shards complete', function (): void {
    $repository = dirname(__DIR__, 5);
    $process = new Process(
        ['python3', dirname(__DIR__, 2).'/Fixtures/check-pest-shards-tests.py', $repository.'/bin/check-pest-shards'],
        $repository,
        ['PYTHONDONTWRITEBYTECODE' => '1', 'GITHUB_OUTPUT' => false, 'ORBIT_TIA_DIRECTORY' => false],
    );
    $process->setTimeout(180);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());
});
