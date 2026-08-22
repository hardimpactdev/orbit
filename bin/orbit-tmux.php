<?php

declare(strict_types=1);

/**
 * Shared tmux helper. Every argv-based call honors ORBIT_TMUX_SOCKET as
 * `tmux -L <socket>` when that environment variable is set and non-empty.
 * Targets use the `=` exact-name prefix for sessions except the
 * `display-message -t <name>:` session-path form.
 */

/**
 * @param  list<string>  $args
 * @return list<string>
 */
function orbit_tmux_args(array $args): array
{
    $command = [orbit_tmux_binary()];
    $socket = getenv('ORBIT_TMUX_SOCKET');

    if (is_string($socket) && $socket !== '') {
        $command[] = '-L';
        $command[] = $socket;
    }

    return array_merge($command, $args);
}

/**
 * @param  list<string>  $args
 * @return array{exit: int, stdout: string, stderr: string}
 */
function orbit_tmux_run(array $args): array
{
    return orbit_tmux_run_command(orbit_tmux_args($args));
}

/**
 * @return array{status: 'ok'|'not_found'|'error', reason: ?string}
 */
function orbit_tmux_has_session(string $name): array
{
    if (! orbit_tmux_session_name_is_valid($name)) {
        return [
            'status' => 'error',
            'reason' => "invalid tmux session name: {$name}",
        ];
    }

    $result = orbit_tmux_run(['has-session', '-t', '='.$name]);

    if ($result['exit'] === 0) {
        return ['status' => 'ok', 'reason' => null];
    }

    if ($result['exit'] === 1 && orbit_tmux_stderr_is_not_found($result['stderr'])) {
        $reason = trim($result['stderr']);

        return [
            'status' => 'not_found',
            'reason' => $reason === '' ? 'session not found' : $reason,
        ];
    }

    $reason = trim($result['stderr']);

    return [
        'status' => 'error',
        'reason' => $reason === '' ? "tmux has-session exited {$result['exit']}" : $reason,
    ];
}

/**
 * @return array{status: 'ok'|'not_found'|'error', path: ?string, reason: ?string}
 */
function orbit_tmux_session_path(string $name): array
{
    $hasSession = orbit_tmux_has_session($name);

    if ($hasSession['status'] !== 'ok') {
        return [
            'status' => $hasSession['status'],
            'path' => null,
            'reason' => $hasSession['reason'],
        ];
    }

    $display = orbit_tmux_run(['display-message', '-p', '-t', $name.':', '#{session_path}']);
    $path = trim($display['stdout']);

    if ($path === '') {
        if ($display['exit'] !== 0 && ! orbit_tmux_stderr_is_not_found($display['stderr'])) {
            $listed = orbit_tmux_run([
                'list-sessions',
                '-F',
                '#{session_path}',
                '-f',
                '#{==:#{session_name},'.$name.'}',
            ]);

            if ($listed['exit'] !== 0) {
                if (orbit_tmux_stderr_is_not_found($listed['stderr'])) {
                    $reason = trim($listed['stderr']);

                    return [
                        'status' => 'not_found',
                        'path' => null,
                        'reason' => $reason === '' ? 'session not found' : $reason,
                    ];
                }

                $reason = trim($listed['stderr']);

                return [
                    'status' => 'error',
                    'path' => null,
                    'reason' => $reason === '' ? "tmux list-sessions exited {$listed['exit']}" : $reason,
                ];
            }

            $path = trim($listed['stdout']);
        }
    }

    if ($path === '') {
        return [
            'status' => 'not_found',
            'path' => null,
            'reason' => 'empty session path',
        ];
    }

    $realpath = realpath($path);

    if ($realpath === false) {
        return [
            'status' => 'error',
            'path' => null,
            'reason' => "session path does not exist: {$path}",
        ];
    }

    return ['status' => 'ok', 'path' => $realpath, 'reason' => null];
}

function orbit_tmux_current_session(): ?string
{
    $tmux = getenv('TMUX');

    if (! is_string($tmux) || $tmux === '') {
        return null;
    }

    $comma = strpos($tmux, ',');
    $socketPath = $comma === false ? $tmux : substr($tmux, 0, $comma);

    if ($socketPath === '') {
        return null;
    }

    $result = orbit_tmux_run_command(array_merge(
        [orbit_tmux_binary(), '-S', $socketPath],
        ['display-message', '-p', '#S'],
    ));

    if ($result['exit'] !== 0) {
        return null;
    }

    $name = trim($result['stdout']);

    return $name === '' ? null : $name;
}

function orbit_tmux_window_exists(string $session, string $window): bool
{
    foreach (orbit_tmux_list_windows($session) as $entry) {
        if ($entry['name'] === $window) {
            return true;
        }
    }

    return false;
}

/**
 * @return list<array{name: string, pane_id: string, pane_pid: int, current_command: string}>
 */
function orbit_tmux_list_windows(string $session): array
{
    $result = orbit_tmux_run([
        'list-windows',
        '-t',
        '='.$session,
        '-F',
        "#{window_name}\t#{pane_id}\t#{pane_pid}\t#{pane_current_command}",
    ]);

    if ($result['exit'] !== 0) {
        return [];
    }

    $windows = [];

    foreach (preg_split('/\R/', trim($result['stdout'])) ?: [] as $line) {
        if ($line === '') {
            continue;
        }

        $parts = explode("\t", $line, 4);

        if (count($parts) < 4) {
            continue;
        }

        $windows[] = [
            'name' => $parts[0],
            'pane_id' => $parts[1],
            'pane_pid' => (int) $parts[2],
            'current_command' => $parts[3],
        ];
    }

    return $windows;
}

function orbit_tmux_session_name_is_valid(string $name): bool
{
    return preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/', $name) === 1;
}

function orbit_tmux_binary(): string
{
    $path = getenv('PATH');

    if (! is_string($path) || $path === '') {
        throw new RuntimeException('tmux is required but PATH is empty.');
    }

    foreach (explode(PATH_SEPARATOR, $path) as $directory) {
        if ($directory === '') {
            continue;
        }

        $candidate = $directory.DIRECTORY_SEPARATOR.'tmux';

        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    throw new RuntimeException('tmux is required but was not found on PATH.');
}

/**
 * @param  list<string>  $command
 * @return array{exit: int, stdout: string, stderr: string}
 */
function orbit_tmux_run_command(array $command): array
{
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptorSpec, $pipes);

    if (! is_resource($process)) {
        return ['exit' => 1, 'stdout' => '', 'stderr' => 'unable to start tmux'];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'exit' => proc_close($process),
        'stdout' => $stdout === false ? '' : $stdout,
        'stderr' => $stderr === false ? '' : $stderr,
    ];
}

function orbit_tmux_stderr_is_not_found(string $stderr): bool
{
    $normalized = strtolower($stderr);

    foreach (["can't find session", 'no server running', 'no sessions'] as $needle) {
        if (str_contains($normalized, $needle)) {
            return true;
        }
    }

    return false;
}
