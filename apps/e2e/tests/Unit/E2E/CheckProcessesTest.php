<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('budgets workers from available resources and keeps suite shares internal', function (): void {
    $repository = dirname(__DIR__, 5);
    $process = new Process(['python3', dirname(__DIR__, 2).'/Fixtures/check-processes-tests.py', $repository], $repository, ['PYTHONDONTWRITEBYTECODE' => '1']);
    $process->mustRun();

    expect($process->getErrorOutput())->toContain('OK');
});

it('delegates the PHP worker count and resource failures to the shared helper', function (): void {
    $files = new Filesystem;
    $temporary = sys_get_temp_dir().'/orbit-check-processes-'.bin2hex(random_bytes(8));
    $files->makeDirectory($temporary.'/tools', 0700, true);
    $files->makeDirectory($temporary.'/bin', 0700, true);
    $files->copy(dirname(__DIR__, 5).'/tools/check-processes.php', $temporary.'/tools/check-processes.php');

    try {
        $files->put($temporary.'/bin/check-processes', "#!/usr/bin/env bash\nprintf '7\\n'\n");
        chmod($temporary.'/bin/check-processes', 0700);
        expect(require $temporary.'/tools/check-processes.php')->toBe(7);

        $files->put($temporary.'/bin/check-processes', "#!/usr/bin/env bash\nprintf 'Cannot read available memory\\n' >&2\nexit 2\n");
        expect(fn (): mixed => require $temporary.'/tools/check-processes.php')
            ->toThrow(RuntimeException::class, 'Cannot read available memory');
    } finally {
        $files->deleteDirectory($temporary);
    }
});
