<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

function cli_binary_repo_root(): string
{
    return dirname(base_path(), 2);
}

function cli_binary_builder_script(): string
{
    return cli_binary_repo_root().'/bin/orbit-build-cli-binary';
}

/**
 * @param  list<string>  $arguments
 * @return array{int, string}
 */
function run_cli_binary_script(string $script, array $arguments): array
{
    $command = array_merge([$script], $arguments);
    $process = proc_open(
        $command,
        [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        cli_binary_repo_root(),
    );

    expect($process)->not->toBeFalse();

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [$exitCode, $stdout.$stderr];
}

it('prints the artifact contract for --help', function (): void {
    [$exitCode, $output] = run_cli_binary_script(cli_binary_builder_script(), ['--help']);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('mac arm')
        ->and($output)->toContain('linux x64')
        ->and($output)->not->toContain('linux arm')
        ->and($output)->toContain('apps/cli/builds/dist/mac/mac-arm')
        ->and($output)->toContain('apps/cli/builds/dist/linux/linux-x64')
        ->and($output)->toContain('orbit-macos-arm64')
        ->and($output)->toContain('orbit-linux-x64')
        ->and($output)->not->toContain('orbit-linux-arm64');
});

it('exits 2 when a target is missing', function (): void {
    [$exitCode, $output] = run_cli_binary_script(cli_binary_builder_script(), []);

    expect($exitCode)->toBe(2)
        ->and($output)->toContain('Usage:');
});

it('refuses an unsupported target', function (): void {
    [$exitCode, $output] = run_cli_binary_script(cli_binary_builder_script(), ['windows', 'x64']);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('unsupported target: windows x64');
});

it('refuses a Linux arm target because Linux machines are x86_64 only', function (): void {
    [$exitCode, $output] = run_cli_binary_script(cli_binary_builder_script(), ['linux', 'arm']);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('unsupported target: linux arm');
});

it('keeps the workflow targets, artifact names, dest paths, and build runners aligned with the builder', function (): void {
    $workflow = file_get_contents(cli_binary_repo_root().'/.github/workflows/orbit-cli-binary.yml');
    $builder = file_get_contents(cli_binary_builder_script());

    $target = static fn (string $target, string $runner, string $platform, string $architecture, string $artifact, string $path): string => implode("\n", [
        "          - target: {$target}",
        "            runner: {$runner}",
        "            platform: {$platform}",
        "            architecture: {$architecture}",
        "            artifact: {$artifact}",
        "            path: {$path}",
    ]);

    expect($workflow)->toBeString()
        ->and($builder)->toBeString()
        ->and($workflow)->toContain($target('linux-x64', 'ubuntu-26.04', 'linux', 'x64', 'orbit-linux-x64', 'apps/cli/builds/dist/linux/linux-x64'))
        ->and($workflow)->toContain($target('macos-arm64', 'ubuntu-26.04', 'mac', 'arm', 'orbit-macos-arm64', 'apps/cli/builds/dist/mac/mac-arm'))
        ->and($workflow)->toContain('bin/orbit-build-cli-binary "${{ matrix.platform }}" "${{ matrix.architecture }}" "$VERSION"')
        ->and($workflow)->toContain('workflow_call:')
        ->and($workflow)->toContain('working-dir=apps/cli/phpacker')
        ->and($workflow)->toContain('GITHUB_TOKEN: ${{ github.token }}')
        ->and($workflow)->not->toContain('self-hosted')
        ->and($workflow)->not->toContain('ORBIT_MINI_RUNNER')
        ->and($workflow)->not->toContain('macos-latest')
        ->and($workflow)->not->toContain('linux-arm')
        ->and($workflow)->not->toContain('ubuntu-26.04-arm')
        ->and($builder)->toContain('mac:arm|linux:x64)')
        ->and($builder)->toContain('--dest=./builds/dist')
        ->and($builder)->toContain('packages/php-sdk')
        ->and($builder)->toContain('vendor/nckrtl/orbit-php-sdk')
        ->and($builder)->toContain('phpacker/vendor/bin/phpacker')
        ->and($builder)->toContain('variables_order=EGPCS')
        ->and($builder)->toContain('php-bin')
        ->and($builder)->not->toContain('packages/core')
        ->and($builder)->not->toContain('compressFiles')
        ->and(cli_binary_repo_root().'/apps/cli/box.json')->toBeFile()
        ->and((string) file_get_contents(cli_binary_repo_root().'/apps/cli/box.json'))->toContain('"compression": "GZ"');
});

it('keeps Laravel HTTP client in the packed production install', function (): void {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
    $lock = json_decode((string) file_get_contents(base_path('composer.lock')), true, flags: JSON_THROW_ON_ERROR);
    $provider = (string) file_get_contents(base_path('app/Providers/AppServiceProvider.php'));
    $productionPackages = array_column($lock['packages'] ?? [], 'name');

    expect($composer['require']['illuminate/http'] ?? null)->toBe('^13.0')
        ->and($productionPackages)->toContain('illuminate/http')
        ->and($provider)->toContain('Illuminate\\Http\\Client\\Factory')
        ->and($provider)->toContain('HttpFactory::class');
});

it('binds the Laravel HTTP client factory as a singleton', function (): void {
    $first = app(Factory::class);
    $second = app(Factory::class);

    expect($first)->toBeInstanceOf(Factory::class)
        ->and($first)->toBe($second);
});

it('prints a git describe version', function (): void {
    [$exitCode, $output] = run_cli_binary_script(cli_binary_repo_root().'/bin/orbit-version', []);

    expect($exitCode)->toBe(0)
        ->and(trim($output))->not->toBe('');
});

it('authenticates the PHPacker archive directly without forwarding its token through redirects', function (): void {
    $directory = sys_get_temp_dir().'/orbit-phpacker-auth-'.bin2hex(random_bytes(6));
    mkdir($directory, 0700, true);
    $script = <<<'PHP_SCRIPT'
        require $argv[1];
        class ArchiveStream
        {
            public mixed $context;
            public static array $requests = [];
            private int $offset = 0;
            public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
            {
                self::$requests[] = ['url' => $path, 'options' => stream_context_get_options($this->context)];
                return true;
            }
            public function stream_read(int $count): string
            {
                $chunk = substr('archive', $this->offset, $count);
                $this->offset += strlen($chunk);
                return $chunk;
            }
            public function stream_eof(): bool { return $this->offset >= 7; }
            public function stream_stat(): array { return []; }
        }
        stream_wrapper_unregister('https');
        stream_wrapper_register('https', ArchiveStream::class);
        $path = orbit_phpacker_archive('phpacker/php-bin', 'v1.2.3', $argv[2], 'ghs_archive_sentinel');
        $request = ArchiveStream::$requests[0];
        echo json_encode([
            'url' => $request['url'],
            'authenticated' => in_array('Authorization: Bearer ghs_archive_sentinel', $request['options']['http']['header'], true),
            'redirects' => $request['options']['http']['follow_location'],
            'contents' => file_get_contents($path),
        ]);
        PHP_SCRIPT;
    try {
        $process = new Process(['php', '-r', $script, base_path('phpacker/run.php'), $directory]);
        $process->run();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
        expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe([
            'url' => 'https://codeload.github.com/phpacker/php-bin/legacy.zip/v1.2.3',
            'authenticated' => true,
            'redirects' => 0,
            'contents' => 'archive',
        ]);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('rejects missing credentials and invalid PHPacker download identities before downloading', function (string $repository, string $token): void {
    require_once base_path('phpacker/run.php');

    expect(fn () => orbit_phpacker_archive($repository, 'v1.2.3', sys_get_temp_dir(), $token))->toThrow(RuntimeException::class);
})->with([
    'missing token' => ['phpacker/php-bin', ''],
    'invalid token header' => ['phpacker/php-bin', "sentinel\r\nX-Injected: value"],
    'invalid repository' => ['phpacker/php-bin/../../other', 'sentinel'],
]);
