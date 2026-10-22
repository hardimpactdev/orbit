<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('renders real guidance with unusable inherited cache paths', function (string $app): void {
    $repository = dirname(__DIR__, 5);
    $fixture = sys_get_temp_dir().'/orbit-guidance-test-'.bin2hex(random_bytes(8));
    $files = new Filesystem;
    $files->makeDirectory($fixture, 0700);
    // A file cannot be used as a compile directory, regardless of the runner's UID.
    $files->put($fixture.'/unusable', 'not a directory');
    $environment = [
        'APP_ENV' => false,
        'VIEW_COMPILED_PATH' => $fixture.'/unusable',
        'TMPDIR' => $fixture,
    ];
    foreach (array_keys($_SERVER + $_ENV) as $name) {
        if (is_string($name) && (str_starts_with($name, 'PEST_') || in_array($name, ['PARATEST', 'TEST_TOKEN', 'UNIQUE_TEST_TOKEN'], true))) {
            $environment[$name] = false;
        }
    }

    try {
        // One real render proves launcher isolation; the Gateway job owns the rest of its guidance suite.
        $options = $app === 'gateway' ? ['--', '--filter=regenerates strict guidance'] : [];
        $process = new Process(['composer', 'guidance:check', ...$options], $repository.'/apps/'.$app, $environment);
        $process->setTimeout(120);
        $process->run();

        expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());
        expect($process->getOutput())->toContain('passed')->not->toContain('No tests found');
        expect($files->get($fixture.'/unusable'))->toBe('not a directory');
        expect(glob($fixture.'/orbit-guidance.*'))->toBe([]);
    } finally {
        $files->deleteDirectory($fixture);
    }
})->with(['gateway', 'cli', 'e2e']);

it('uses private runtime directories and cleans them on success or failure', function (int $exitCode): void {
    $repository = dirname(__DIR__, 5);
    $fixture = sys_get_temp_dir().'/orbit-guidance-runner-'.bin2hex(random_bytes(8));
    $files = new Filesystem;
    $files->makeDirectory($fixture.'/vendor/bin', 0700, true);
    $files->makeDirectory($fixture.'/tmp', 0700);
    $files->put($fixture.'/vendor/bin/pest', <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
[[ "$*" == '--configuration=phpunit.guidance.xml --compact --filter=example' ]]
[[ -d "$VIEW_COMPILED_PATH" ]]
[[ "$(stat -c %a "$VIEW_COMPILED_PATH")" == 700 ]]
printf 'compiled' > "$VIEW_COMPILED_PATH/view.php"
exit "$FIXTURE_EXIT_CODE"
BASH);
    chmod($fixture.'/vendor/bin/pest', 0700);

    try {
        $process = new Process([$repository.'/bin/guidance-check', '--filter=example'], $fixture, [
            'TMPDIR' => $fixture.'/tmp',
            'FIXTURE_EXIT_CODE' => (string) $exitCode,
        ]);
        $process->run();

        expect($process->getExitCode())->toBe($exitCode, $process->getOutput().$process->getErrorOutput());
        expect(glob($fixture.'/tmp/*'))->toBe([]);
    } finally {
        $files->deleteDirectory($fixture);
    }
})->with([0, 19]);
