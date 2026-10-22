<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('checks fixture retirement together with its replay tests', function (string $state, int $exitCode): void {
    $root = sys_get_temp_dir().'/cli-contract-retirement-'.bin2hex(random_bytes(6));
    $files = new Filesystem;
    foreach (['bin', 'packages/php-sdk/fixtures/retired', 'apps/cli/tests'] as $directory) {
        $files->ensureDirectoryExists($root.'/'.$directory);
    }
    $files->copy(dirname(__DIR__, 4).'/bin/cli-contract', $root.'/bin/cli-contract');
    $files->put($root.'/packages/php-sdk/fixtures/retired/default.json', '{}');

    try {
        foreach ([['git', 'init', '-q'], ['git', 'add', '.'],
            ['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.test', '-c', 'commit.gpgsign=false', 'commit', '-qm', 'fixture'],
        ] as $command) {
            new Process($command, $root)->mustRun();
        }
        if ($state !== 'orphan') {
            $files->delete($root.'/packages/php-sdk/fixtures/retired/default.json');
        }
        if ($state === 'orphan') {
            $files->put($root.'/packages/php-sdk/fixtures/retired/default.json', '{"changed":true}');
        }
        if ($state === 'referenced') {
            $files->put($root.'/apps/cli/tests/ReplayTest.php', "<?php 'retired/default';");
            $files->put($root.'/bin/pest-plain', "#!/bin/sh\nexit 17\n");
            chmod($root.'/bin/pest-plain', 0755);
        }

        $process = new Process(['python3', $root.'/bin/cli-contract', '--changed', 'HEAD'], $root);
        $process->run();

        expect($process->getExitCode())->toBe($exitCode, $process->getOutput().$process->getErrorOutput());
    } finally {
        $files->deleteDirectory($root);
    }
})->with(['retired together' => ['retired', 0], 'orphan fixture' => ['orphan', 1], 'stale replay' => ['referenced', 17]]);
