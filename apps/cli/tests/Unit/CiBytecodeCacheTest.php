<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

pest()->group('subprocess');

function cli_bytecode_cache_steps(): array
{
    $workflow = Yaml::parseFile(dirname(__DIR__, 4).'/.github/workflows/ci.yml');

    return $workflow['jobs']['project']['steps'];
}

function cli_bytecode_cache_step(array $steps): array
{
    $matches = array_values(array_filter($steps, static fn (array $step): bool => $step['name'] === 'Prepare CLI test bytecode cache'));
    expect($matches)->toHaveCount(1);

    return $matches[0];
}

it('limits the private bytecode cache to the CLI full PR test step', function (): void {
    $steps = cli_bytecode_cache_steps();
    $step = cli_bytecode_cache_step($steps);
    $names = array_column($steps, 'name');
    $index = array_search($step['name'], $names, true);
    $phpSetup = $steps[array_search('Set up PHP', $names, true)];

    expect($step['if'])->toBe("matrix.project.directory == 'apps/cli' && env.CHANGE_RUN == 'true'")
        ->and($steps[$index + 1]['name'])->toBe('Run full PR test suite')
        ->and($steps[$index + 1]['run'])->toBe('vendor/bin/pest --parallel --processes=${{ runner.environment == \'self-hosted\' && 6 || 4 }} --no-tia --compact')
        ->and($phpSetup['with']['coverage'])->toBe('pcov');
});

it('keeps real bytecode cache hits covered and observes rewritten source', function (): void {
    $step = cli_bytecode_cache_step(cli_bytecode_cache_steps());
    $directory = sys_get_temp_dir().'/orbit-cli-bytecode-'.Str::uuid();
    $filesystem = new Filesystem;
    $filesystem->makeDirectory($directory);

    try {
        $environmentFile = $directory.'/environment';
        $setup = new Process(['bash', '-euo', 'pipefail', '-c', $step['run']], env: [
            'RUNNER_TEMP' => $directory,
            'GITHUB_ENV' => $environmentFile,
        ]);
        $setup->mustRun();
        $assignment = trim(file_get_contents($environmentFile));
        expect($assignment)->toStartWith('PHP_INI_SCAN_DIR=');
        $scanDirectories = substr($assignment, strlen('PHP_INI_SCAN_DIR='));
        expect($scanDirectories)->toStartWith((string) getenv('PHP_INI_SCAN_DIR').':');
        $iniDirectory = substr($scanDirectories, strrpos($scanDirectories, ':') + 1);
        $cacheDirectory = dirname($iniDirectory).'/bytecode';
        expect($filesystem->allFiles($cacheDirectory))->toBe([])
            ->and(parse_ini_file($iniDirectory.'/99-bytecode.ini', false, INI_SCANNER_RAW))->toBe([
                'opcache.enable_cli' => '1',
                'opcache.file_cache' => $cacheDirectory,
                'opcache.file_cache_only' => '1',
                'opcache.validate_timestamps' => '1',
                'opcache.revalidate_freq' => '0',
            ]);

        $source = $directory.'/source.php';
        file_put_contents($source, "<?php\nreturn 'initial';\n");
        touch($source, time() - 60);
        $program = <<<'PHP'
            $cached = opcache_is_script_cached_in_file_cache($argv[1]);
            \pcov\start();
            $value = require $argv[1];
            $coverage = \pcov\collect();
            echo json_encode(['cached_before' => $cached, 'value' => $value, 'coverage' => $coverage[$argv[1]]], JSON_THROW_ON_ERROR);
            PHP;
        $execute = static function () use ($directory, $source, $program, $scanDirectories): array {
            $process = new Process([
                PHP_BINARY,
                '-d', 'pcov.directory='.$directory,
                '-r', $program, $source,
            ], env: ['PHP_INI_SCAN_DIR' => $scanDirectories]);
            $process->mustRun();

            return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        };

        $compiled = $execute();
        expect($compiled)->toBe(['cached_before' => false, 'value' => 'initial', 'coverage' => [2 => 1]])
            ->and(glob($cacheDirectory.'/*'.$source.'.bin'))->toHaveCount(1);
        expect($execute())->toBe(['cached_before' => true, 'value' => 'initial', 'coverage' => [2 => 1]]);

        file_put_contents($source, "<?php\nreturn 'changed';\n");
        $changed = $execute();
        expect($changed['value'])->toBe('changed')->and($changed['coverage'])->toBe([2 => 1]);
    } finally {
        $filesystem->deleteDirectory($directory);
    }
});
