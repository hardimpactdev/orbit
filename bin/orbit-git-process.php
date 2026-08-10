<?php

declare(strict_types=1);

function git_root(string $cwd): ?string
{
    $result = run_process(['git', 'rev-parse', '--show-toplevel'], $cwd);

    if ($result['exit_code'] !== 0) {
        return null;
    }

    $root = trim($result['stdout']);

    return $root === '' ? null : $root;
}

function local_branch_exists(string $root, string $branch): bool
{
    return run_git($root, ['show-ref', '--verify', '--quiet', 'refs/heads/'.$branch])['exit_code'] === 0;
}

function worktree_for_branch(string $root, string $branch): ?string
{
    foreach (worktrees($root) as $worktree) {
        if (($worktree['branch'] ?? null) === $branch) {
            return $worktree['path'];
        }
    }

    return null;
}

function branch_for_worktree(string $root, string $path): ?string
{
    $path = realpath($path) ?: $path;

    foreach (worktrees($root) as $worktree) {
        if ((realpath($worktree['path']) ?: $worktree['path']) === $path) {
            return $worktree['branch'] ?? null;
        }
    }

    return null;
}

/**
 * @return list<array{path: string, branch?: string}>
 */
function worktrees(string $root): array
{
    $result = run_git($root, ['worktree', 'list', '--porcelain']);

    if ($result['exit_code'] !== 0) {
        return [];
    }

    $worktrees = [];
    $current = [];

    foreach (preg_split('/\R/', trim($result['stdout'])) as $line) {
        if (str_starts_with($line, 'worktree ')) {
            if (isset($current['path'])) {
                $worktrees[] = $current;
            }

            $current = ['path' => substr($line, strlen('worktree '))];

            continue;
        }

        if (str_starts_with($line, 'branch refs/heads/')) {
            $current['branch'] = substr($line, strlen('branch refs/heads/'));
        }
    }

    if (isset($current['path'])) {
        $worktrees[] = $current;
    }

    return $worktrees;
}

function absolute_path(string $root, string $path): string
{
    $path = trim($path, " \t\n\r\0\x0B'\"");

    if (str_starts_with($path, '/')) {
        return $path;
    }

    return $root.'/'.$path;
}

/**
 * @param  list<string>  $args
 * @return array{exit_code: int, stdout: string, stderr: string}
 */
function run_git(string $root, array $args): array
{
    return run_process(array_merge(['git'], $args), $root);
}

/**
 * @param  list<string>  $command
 * @return array{exit_code: int, stdout: string, stderr: string}
 */
function run_process(array $command, string $cwd): array
{
    $descriptorSpec = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptorSpec, $pipes, $cwd);

    if (! is_resource($process)) {
        return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'unable to start process'];
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

/**
 * @param  list<string>  $command
 * @return array{exit_code: int, stdout: string, stderr: string, timed_out: bool}
 */
function run_process_with_timeout(array $command, string $cwd, int $timeoutMs, string $stdin = ''): array
{
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptorSpec, $pipes, $cwd);

    if (! is_resource($process)) {
        return [
            'exit_code' => 1,
            'stdout' => '',
            'stderr' => 'unable to start process',
            'timed_out' => false,
        ];
    }

    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $startedAt = hrtime(true);
    $stdout = '';
    $stderr = '';
    $exitCode = null;
    $timedOut = false;

    while (true) {
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        $status = proc_get_status($process);

        if (! $status['running']) {
            $exitCode = $status['exitcode'];

            break;
        }

        if ((hrtime(true) - $startedAt) / 1_000_000 >= $timeoutMs) {
            $timedOut = true;
            proc_terminate($process);
            usleep(10_000);

            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }

            break;
        }

        usleep(1_000);
    }

    $stdout .= (string) stream_get_contents($pipes[1]);
    $stderr .= (string) stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    $closedExitCode = proc_close($process);

    if ($exitCode === null || $exitCode < 0) {
        $exitCode = $closedExitCode >= 0 ? $closedExitCode : ($timedOut ? 124 : 1);
    }

    return [
        'exit_code' => $exitCode,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'timed_out' => $timedOut,
    ];
}
