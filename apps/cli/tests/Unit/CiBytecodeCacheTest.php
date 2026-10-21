<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

pest()->group('subprocess');

it('limits the private bytecode cache and PCOV validation to CLI change jobs', function (): void {
    $steps = cli_bytecode_cache_steps();
    $step = cli_bytecode_cache_step($steps);
    $names = array_column($steps, 'name');
    $index = array_search($step['name'], $names, true);
    $phpSetup = $steps[array_search('Set up PHP', $names, true)];
    $validationIndex = array_search('Validate CLI bytecode coverage', $names, true);

    expect($step['if'])->toBe("matrix.project.directory == 'apps/cli' && env.CHANGE_RUN == 'true'")
        ->and($steps[$index + 1]['name'])->toBe('Run full PR test suite')
        ->and($steps[$index + 1]['run'])->toBe('vendor/bin/pest --parallel --processes=${{ runner.environment == \'self-hosted\' && 6 || 4 }} --no-tia --compact')
        ->and($phpSetup['with']['coverage'])->toBe('pcov')
        ->and($validationIndex)->toBe($index + 2)
        ->and($steps[$validationIndex]['if'])->toBe($step['if'])
        ->and($steps[$validationIndex]['run'])->toBe('vendor/bin/pest tests/Ci/BytecodeCacheCoverageTest.php --no-tia --compact --fail-on-empty-test-suite');
});

it('reuses bytecode and observes rewritten source without a coverage dependency', function (): void {
    $program = <<<'PHP'
        $cached = opcache_is_script_cached_in_file_cache($argv[1]);
        $value = require $argv[1];
        echo json_encode(['cached_before' => $cached, 'value' => $value], JSON_THROW_ON_ERROR);
        PHP;

    assert_cli_bytecode_cache($program, ['value' => 'initial'], ['value' => 'changed']);
});

it('runs the cache checks with absent or disabled coverage drivers', function (string $driver): void {
    $directory = sys_get_temp_dir().'/orbit-cli-local-ini-'.Str::uuid();
    $filesystem = new Filesystem;
    $filesystem->makeDirectory($directory);

    try {
        file_put_contents($directory.'/php.ini', '');
        $files = array_filter([
            php_ini_loaded_file(),
            ...array_map('trim', explode(',', (string) php_ini_scanned_files())),
        ]);
        foreach (array_values($files) as $index => $file) {
            $contents = file_get_contents($file);
            if ($driver === 'none') {
                $contents = preg_replace('/^\h*(?:zend_)?extension\h*=.*(?:pcov|xdebug).*$/mi', '', $contents);
            }
            file_put_contents($directory.'/'.sprintf('%03d.ini', $index), $contents);
        }
        file_put_contents($directory.'/999-local.ini', "pcov.enabled=0\nxdebug.mode=off\nopcache.enable_cli=0\n");
        $environment = ['PHPRC' => $directory.'/php.ini', 'PHP_INI_SCAN_DIR' => $directory];
        $arguments = [PHP_BINARY, '-c', $directory.'/php.ini'];
        $probe = new Process([...$arguments, '-r', 'echo json_encode([extension_loaded("pcov"), extension_loaded("xdebug"), (bool) ini_get("pcov.enabled")]);'], env: $environment);
        $probe->mustRun();
        $state = json_decode($probe->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        expect($state[2])->toBeFalse();
        if ($driver === 'none') {
            expect($state)->toBe([false, false, false]);
        }

        $process = new Process([
            ...$arguments,
            dirname(__DIR__, 2).'/vendor/bin/pest',
            'tests/Unit/CiBytecodeCacheTest.php',
            '--filter=reuses bytecode and observes rewritten source without a coverage dependency',
            '--no-tia', '--compact', '--colors=never', '--fail-on-empty-test-suite',
        ], cwd: dirname(__DIR__, 2), env: $environment);
        $process->mustRun();
        expect($process->getOutput())->toContain('1 passed')->not->toContain('skipped');
    } finally {
        $filesystem->deleteDirectory($directory);
    }
})->with(['no coverage driver' => 'none', 'coverage disabled' => 'disabled']);
