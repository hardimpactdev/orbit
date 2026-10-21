<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('removes preview output after both successful and failed contract runs', function (int $exit): void {
    $fixture = sys_get_temp_dir().'/cli-contract-'.bin2hex(random_bytes(6));
    $filesystem = new Filesystem;
    $filesystem->makeDirectory($fixture.'/bin', 0o755, true);
    $filesystem->makeDirectory($fixture.'/apps/cli', 0o755, true);
    $filesystem->makeDirectory($fixture.'/temporary');
    $filesystem->copy(dirname(__DIR__, 4).'/bin/cli-contract', $fixture.'/bin/cli-contract');
    file_put_contents($fixture.'/bin/pest-plain', <<<'PYTHON'
#!/usr/bin/env python3
import os,pathlib,sys
pathlib.Path(os.environ['PROBE_RECORD']).write_text(os.environ['ORBIT_EXPECTED_DIR'])
pathlib.Path(os.environ['ORBIT_EXPECTED_DIR'], 'output').write_text('preview')
sys.exit(int(os.environ['PROBE_EXIT']))
PYTHON);
    chmod($fixture.'/bin/pest-plain', 0o755);
    $process = new Process(['python3', $fixture.'/bin/cli-contract', '--preview'], env: [
        'TMPDIR' => $fixture.'/temporary',
        'PROBE_RECORD' => $fixture.'/record',
        'PROBE_EXIT' => (string) $exit,
    ]);

    try {
        $process->run();
        $directory = file_get_contents($fixture.'/record');

        expect($process->getExitCode())->toBe(1)
            ->and($directory)->toStartWith($fixture.'/temporary/cli-contract-preview-')
            ->and(is_dir($directory))->toBeFalse();
    } finally {
        $filesystem->deleteDirectory($fixture);
    }
})->with([0, 7]);
