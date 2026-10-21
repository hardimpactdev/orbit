<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

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

function assert_cli_bytecode_cache(string $program, array $initial, array $rewritten, array $settings = []): void
{
    $directives = [];
    foreach ($settings as $key => $value) {
        $directives[] = '-d';
        $directives[] = $key.'='.$value;
    }

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
        $execute = static function () use ($source, $program, $scanDirectories, $directives): array {
            $process = new Process([
                PHP_BINARY,
                ...$directives,
                '-r', $program, $source,
            ], env: ['PHP_INI_SCAN_DIR' => $scanDirectories]);
            $process->mustRun();

            return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        };

        $compiled = $execute();
        expect($compiled)->toBe(['cached_before' => false, ...$initial])
            ->and(glob($cacheDirectory.'/*'.$source.'.bin'))->toHaveCount(1);
        expect($execute())->toBe(['cached_before' => true, ...$initial]);

        file_put_contents($source, "<?php\nreturn 'changed';\n");
        $changed = $execute();
        unset($changed['cached_before']);
        expect($changed)->toBe($rewritten);
    } finally {
        $filesystem->deleteDirectory($directory);
    }
}
