<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('publishes and imports main CI quality cache refs only when they fit', function (): void {
    $repository = dirname(__DIR__, 5);
    // Keep the fixture's repositories on the worktree filesystem, like the TIA cache fixture.
    $temporary = $repository.'/orbit_ci_ref_'.bin2hex(random_bytes(8));
    $files = new Filesystem;
    $files->makeDirectory($temporary, 0700, true);
    $environment = ['TMPDIR' => $temporary, 'PYTHONDONTWRITEBYTECODE' => '1', 'GITHUB_RUN_ID' => false, 'GITHUB_RUN_ATTEMPT' => false];

    $process = new Process(
        [
            'python3',
            dirname(__DIR__, 2).'/Fixtures/ci-cache-ref-tests.py',
            $repository.'/bin/ci-cache-ref',
        ],
        $repository,
        $environment,
    );
    $process->setTimeout(120);
    try {
        $process->run();
        expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());
    } finally {
        $files->deleteDirectory($temporary);
    }
});
