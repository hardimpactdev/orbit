<?php

declare(strict_types=1);

function repo_path(string $path = ''): string
{
    $root = dirname(__DIR__, 3);

    if ($path === '') {
        return $root;
    }

    return $root.'/'.ltrim($path, '/');
}

function make_temp_directory(string $prefix): string
{
    $path = sys_get_temp_dir().'/orbit-e2e-'.$prefix.'-'.bin2hex(random_bytes(4));

    mkdir($path, 0777, true);

    return $path;
}

function remove_directory(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    exec('rm -rf '.escapeshellarg($path));
}

/**
 * @param  list<string>  $command
 * @param  array<string, string>  $env
 * @return array{exit_code: int, stdout: string, stderr: string}
 */
function run_e2e_script(array $command, ?string $cwd = null, array $env = []): array
{
    $environment = array_filter(getenv(), is_string(...));

    $process = proc_open(
        $command,
        [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $cwd ?? repo_path('apps/e2e'),
        [...$environment, ...$env],
    );

    if (! is_resource($process)) {
        throw new RuntimeException('Failed to start E2E script process.');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'exit_code' => proc_close($process),
        'stdout' => $stdout === false ? '' : $stdout,
        'stderr' => $stderr === false ? '' : $stderr,
    ];
}
