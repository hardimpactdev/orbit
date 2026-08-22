<?php

declare(strict_types=1);

require_once __DIR__.'/orbit-tmux.php';

const ORBIT_WORKER_STATUSES = ['spawned', 'working', 'blocked', 'handoff', 'exited'];

const ORBIT_WORKER_HEARTBEAT_STATUSES = ['working', 'blocked', 'handoff'];

const ORBIT_WORKER_CLIS = ['grok', 'claude', 'codex', 'opencode'];

/**
 * @return array<string, list<string>>
 */
function orbit_worker_default_commands(string $role = ''): array
{
    $grok = ['grok', '--yolo'];

    if ($role === 'impl') {
        $grok[] = '--reasoning-effort';
        $grok[] = 'medium';
    }

    $claude = ['claude', '--dangerously-skip-permissions'];

    if ($role === 'review') {
        $claude[] = '--model';
        $claude[] = 'opus';
        $claude[] = '--effort';
        $claude[] = 'high';
    }

    return [
        'grok' => $grok,
        'claude' => $claude,
        'codex' => ['codex', '--yolo'],
        'opencode' => ['opencode'],
    ];
}

function orbit_worker_worktree(string $cwd): string
{
    $result = orbit_worker_run_command(['git', 'rev-parse', '--show-toplevel'], $cwd);

    if ($result['exit'] !== 0) {
        $reason = trim($result['stderr']);

        throw new RuntimeException(
            $reason === ''
                ? "cwd is not inside a git worktree: {$cwd}"
                : $reason,
        );
    }

    $root = trim($result['stdout']);

    if ($root === '' || ! is_file($root.'/.orbit/loop.md')) {
        throw new RuntimeException(
            "worktree is missing .orbit/loop.md: {$root}. Run bin/orbit-prepare-worktree to seed the feature loop.",
        );
    }

    $realpath = realpath($root);

    return $realpath === false ? $root : $realpath;
}

function orbit_worker_session_name(string $worktree): string
{
    $loopPath = $worktree.'/.orbit/loop.md';
    $contents = @file_get_contents($loopPath);

    if (! is_string($contents) || $contents === '') {
        throw new RuntimeException(
            "unable to read {$loopPath}. Run bin/orbit-prepare-worktree to seed `- Session: feat-<slug>`.",
        );
    }

    if (preg_match('/^-[ \t]+Session:[ \t]*(.+)$/m', $contents, $match) !== 1) {
        throw new RuntimeException(
            "{$loopPath} is missing `- Session: feat-<slug>`. Run bin/orbit-prepare-worktree to create the feature session.",
        );
    }

    $name = trim($match[1], " \t\n\r\0\x0B`");

    if (preg_match('/^feat-[a-z0-9][a-z0-9-]*$/', $name) !== 1) {
        throw new RuntimeException(
            "Session name must match feat-<slug> (lowercase, digits, hyphens); current: {$name}",
        );
    }

    return $name;
}

function orbit_worker_dir(string $worktree): string
{
    $directory = $worktree.'/.orbit/workers';

    foreach (['briefs', 'inbox', 'handoff', 'logs'] as $child) {
        $path = $directory.'/'.$child;

        if (is_dir($path)) {
            continue;
        }

        if (! mkdir($path, 0777, true) && ! is_dir($path)) {
            throw new RuntimeException("unable to create worker directory: {$path}");
        }
    }

    return $directory;
}

/**
 * @return array<string, mixed>|null
 */
function orbit_worker_read(string $worktree, string $id): ?array
{
    $path = orbit_worker_dir($worktree).'/'.$id.'.json';

    if (! is_file($path)) {
        return null;
    }

    $decoded = json_decode((string) file_get_contents($path), true);

    if (! is_array($decoded)) {
        throw new RuntimeException("worker registry entry is not valid JSON: {$path}");
    }

    return orbit_worker_normalize_entry($decoded);
}

/**
 * @param  array<string, mixed>  $entry
 */
function orbit_worker_write(string $worktree, array $entry): void
{
    $normalized = orbit_worker_normalize_entry($entry);
    $directory = orbit_worker_dir($worktree);
    $path = $directory.'/'.$normalized['id'].'.json';
    $temporary = $path.'.tmp';
    $payload =
        json_encode(
            $normalized,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )."\n";

    if (file_put_contents($temporary, $payload) === false) {
        throw new RuntimeException("unable to write worker registry temp file: {$temporary}");
    }

    if (! rename($temporary, $path)) {
        @unlink($temporary);

        throw new RuntimeException("unable to replace worker registry entry: {$path}");
    }
}

/**
 * @return list<array<string, mixed>>
 */
function orbit_worker_list(string $worktree): array
{
    $directory = orbit_worker_dir($worktree);
    $entries = [];

    foreach (glob($directory.'/*.json') ?: [] as $path) {
        if (! is_file($path)) {
            continue;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new RuntimeException("worker registry entry is not valid JSON: {$path}");
        }

        $entries[] = orbit_worker_normalize_entry($decoded);
    }

    usort(
        $entries,
        static function (array $left, array $right): int {
            return strcmp((string) $left['started_at'], (string) $right['started_at']);
        },
    );

    return $entries;
}

function orbit_worker_allocate_id(string $worktree, string $role, ?string $name = null): string
{
    if ($name !== null) {
        if (preg_match('/^[a-z][a-z0-9-]*$/', $name) !== 1) {
            throw new RuntimeException(
                "worker --name must match /^[a-z][a-z0-9-]*\$/; current: {$name}",
            );
        }

        if (orbit_worker_read($worktree, $name) !== null) {
            throw new RuntimeException("worker id already exists: {$name}");
        }

        return $name;
    }

    if (preg_match('/^[a-z][a-z0-9-]*$/', $role) !== 1) {
        throw new RuntimeException("worker --role must match /^[a-z][a-z0-9-]*\$/; current: {$role}");
    }

    $used = [];

    foreach (orbit_worker_list($worktree) as $entry) {
        $used[(string) $entry['id']] = true;
    }

    $n = 1;

    while (isset($used[$role.'-'.$n])) {
        $n++;
    }

    return $role.'-'.$n;
}

function orbit_worker_now(): string
{
    return new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
}

function orbit_worker_log_path(string $worktree, string $id): string
{
    return orbit_worker_dir($worktree).'/logs/'.$id.'.log';
}

function orbit_worker_handoff_path(string $worktree, string $id, ?string $sha = null): string
{
    if (is_string($sha) && $sha !== '') {
        return orbit_worker_dir($worktree).'/handoff/'.$id.'-'.$sha.'.md';
    }

    return orbit_worker_dir($worktree).'/handoff/'.$id.'.md';
}

/**
 * @param  array<string, mixed>  $entry
 * @return array<string, mixed>
 */
function orbit_worker_mark_exited(string $worktree, array $entry): array
{
    if (($entry['status'] ?? null) !== 'exited') {
        $entry['status'] = 'exited';
    }

    if (! is_string($entry['exited_at'] ?? null) || $entry['exited_at'] === '') {
        $entry['exited_at'] = orbit_worker_now();
    }

    orbit_worker_write($worktree, $entry);

    return orbit_worker_read($worktree, (string) $entry['id']) ?? $entry;
}

/**
 * @return 'alive'|'gone'|'unknown'
 */
function orbit_worker_window_alive(array $entry): string
{
    $tmux = is_array($entry['tmux'] ?? null) ? $entry['tmux'] : [];
    $session = (string) ($tmux['session'] ?? '');
    $window = (string) ($tmux['window'] ?? '');

    if ($session === '' || $window === '') {
        return 'gone';
    }

    $listed = orbit_tmux_list_windows($session, orbit_worker_tmux_socket($entry));

    if ($listed['status'] === 'error') {
        return 'unknown';
    }

    if ($listed['status'] === 'not_found') {
        return 'gone';
    }

    foreach ($listed['windows'] as $listedWindow) {
        if ($listedWindow['name'] === $window) {
            return 'alive';
        }
    }

    return 'gone';
}

function orbit_worker_is_self_pane(array $entry): bool
{
    $tmuxPane = getenv('TMUX_PANE');
    $paneId = (string) ($entry['tmux']['pane_id'] ?? '');

    if (! is_string($tmuxPane) || $tmuxPane === '' || $paneId === '' || $tmuxPane !== $paneId) {
        return false;
    }

    $currentSession = orbit_tmux_current_session();
    $session = (string) ($entry['tmux']['session'] ?? '');

    if ($currentSession !== null && $session !== '' && $currentSession !== $session) {
        return false;
    }

    return true;
}

/**
 * @param  array<string, mixed>  $entry
 * @return array{flag: '-L'|'-S', value: string}|null
 */
function orbit_worker_tmux_socket(array $entry): ?array
{
    $tmux = is_array($entry['tmux'] ?? null) ? $entry['tmux'] : [];

    return orbit_worker_normalize_socket($tmux['socket'] ?? null);
}

/**
 * @return array{flag: '-L'|'-S', value: string}|null
 */
function orbit_worker_ambient_socket(): ?array
{
    $ambient = getenv('ORBIT_TMUX_SOCKET');

    if (! is_string($ambient) || $ambient === '') {
        return null;
    }

    return ['flag' => '-L', 'value' => $ambient];
}

/**
 * @return array{flag: '-L'|'-S', value: string}|null
 */
function orbit_worker_normalize_socket(mixed $socket): ?array
{
    if (! is_array($socket)) {
        return null;
    }

    $flag = $socket['flag'] ?? null;
    $value = $socket['value'] ?? null;

    if ($flag !== '-L' && $flag !== '-S' || ! is_string($value) || $value === '') {
        return null;
    }

    return ['flag' => $flag, 'value' => $value];
}

/**
 * @param  array{flag: '-L'|'-S', value: string}|null  $socket
 */
function orbit_worker_send_keys(string $session, string $window, string $text, ?array $socket = null): void
{
    if ($text === '' || str_contains($text, "\n") || str_contains($text, "\r")) {
        throw new RuntimeException('send-keys text must be a single line without newlines');
    }

    $target = '='.$session.':'.$window;
    $literal = orbit_tmux_run(['send-keys', '-t', $target, '-l', $text], $socket);

    if ($literal['exit'] !== 0) {
        $reason = trim($literal['stderr']);

        throw new RuntimeException(
            $reason === '' ? "tmux send-keys failed for {$target}" : $reason,
        );
    }

    $enter = orbit_tmux_run(['send-keys', '-t', $target, 'Enter'], $socket);

    if ($enter['exit'] !== 0) {
        $reason = trim($enter['stderr']);

        throw new RuntimeException(
            $reason === '' ? "tmux send-keys Enter failed for {$target}" : $reason,
        );
    }
}

/**
 * @param  array<string, mixed>  $raw
 * @return array{
 *     id: string,
 *     role: string,
 *     cli: string,
 *     command: list<string>,
 *     tmux: array{session: string, window: string, pane_id: string, pane_pid: int, socket: array{flag: '-L'|'-S', value: string}|null},
 *     cwd: string,
 *     brief: string,
 *     status: string,
 *     heartbeat_at: string,
 *     note: string,
 *     started_at: string,
 *     exited_at: ?string,
 *     provider_ref: ?string,
 *     handoff: ?string
 * }
 */
function orbit_worker_normalize_entry(array $raw): array
{
    $id = trim((string) ($raw['id'] ?? ''));

    if ($id === '') {
        throw new RuntimeException('worker registry entry is missing id');
    }

    $command = $raw['command'] ?? [];

    if (! is_array($command)) {
        throw new RuntimeException("worker command must be a list of argv strings: {$id}");
    }

    $argv = [];

    foreach ($command as $part) {
        if (! is_string($part)) {
            throw new RuntimeException("worker command argv must be strings: {$id}");
        }

        $argv[] = $part;
    }

    $tmux = is_array($raw['tmux'] ?? null) ? $raw['tmux'] : [];
    $status = (string) ($raw['status'] ?? 'spawned');

    if (! in_array($status, ORBIT_WORKER_STATUSES, true)) {
        throw new RuntimeException("worker status is invalid: {$status}");
    }

    $exitedAt = $raw['exited_at'] ?? null;
    $providerRef = $raw['provider_ref'] ?? null;
    $handoff = $raw['handoff'] ?? null;

    return [
        'id' => $id,
        'role' => (string) ($raw['role'] ?? ''),
        'cli' => (string) ($raw['cli'] ?? ''),
        'command' => array_values($argv),
        'tmux' => [
            'session' => (string) ($tmux['session'] ?? ''),
            'window' => (string) ($tmux['window'] ?? ''),
            'pane_id' => (string) ($tmux['pane_id'] ?? ''),
            'pane_pid' => (int) ($tmux['pane_pid'] ?? 0),
            'socket' => orbit_worker_normalize_socket($tmux['socket'] ?? null),
        ],
        'cwd' => (string) ($raw['cwd'] ?? ''),
        'brief' => (string) ($raw['brief'] ?? ''),
        'status' => $status,
        'heartbeat_at' => (string) ($raw['heartbeat_at'] ?? ''),
        'note' => (string) ($raw['note'] ?? ''),
        'started_at' => (string) ($raw['started_at'] ?? ''),
        'exited_at' => is_string($exitedAt) && $exitedAt !== '' ? $exitedAt : null,
        'provider_ref' => is_string($providerRef) && $providerRef !== '' ? $providerRef : null,
        'handoff' => is_string($handoff) && $handoff !== '' ? $handoff : null,
    ];
}

/**
 * @param  list<string>  $arguments
 * @return array{options: array<string, string|bool>, positional: list<string>}
 */
function orbit_worker_parse_argv(array $arguments): array
{
    $options = [];
    $positional = [];

    foreach ($arguments as $argument) {
        if ($argument === '--') {
            continue;
        }

        if (! str_starts_with($argument, '--')) {
            $positional[] = $argument;

            continue;
        }

        $parts = explode('=', substr($argument, 2), 2);
        $options[$parts[0]] = $parts[1] ?? true;
    }

    return ['options' => $options, 'positional' => $positional];
}

function orbit_worker_option_string(array $options, string $name, ?string $default = null): ?string
{
    if (! array_key_exists($name, $options)) {
        return $default;
    }

    $value = $options[$name];

    if ($value === true) {
        throw new RuntimeException("option --{$name} requires a value");
    }

    return (string) $value;
}

function orbit_worker_reject_newline(string $label, string $value): void
{
    if (str_contains($value, "\n") || str_contains($value, "\r")) {
        throw new RuntimeException("{$label} must not contain a newline");
    }
}

function orbit_worker_require_entry(string $worktree, string $id): array
{
    $entry = orbit_worker_read($worktree, $id);

    if ($entry === null) {
        throw new RuntimeException("worker not found: {$id}");
    }

    return $entry;
}

function orbit_worker_heartbeat_age(?string $timestamp): string
{
    if (! is_string($timestamp) || $timestamp === '') {
        return '-';
    }

    try {
        $then = new DateTimeImmutable($timestamp);
    } catch (Exception) {
        return '-';
    }

    $seconds = max(0, time() - $then->getTimestamp());

    if ($seconds < 60) {
        return $seconds.'s';
    }

    if ($seconds < 3600) {
        return intdiv($seconds, 60).'m';
    }

    return intdiv($seconds, 3600).'h';
}

function orbit_worker_freshness(array $entry, string $worktree): int
{
    $heartbeat = 0;

    if (is_string($entry['heartbeat_at'] ?? null) && $entry['heartbeat_at'] !== '') {
        try {
            $heartbeat = new DateTimeImmutable((string) $entry['heartbeat_at'])->getTimestamp();
        } catch (Exception) {
            $heartbeat = 0;
        }
    }

    $logPath = orbit_worker_log_path($worktree, (string) $entry['id']);
    $logMtime = is_file($logPath) ? (int) filemtime($logPath) : 0;

    return max($heartbeat, $logMtime);
}

/**
 * @param  list<string>  $command
 * @return array{exit: int, stdout: string, stderr: string}
 */
function orbit_worker_run_command(array $command, ?string $cwd = null): array
{
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptorSpec, $pipes, $cwd);

    if (! is_resource($process)) {
        return ['exit' => 1, 'stdout' => '', 'stderr' => 'unable to start process'];
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

/**
 * @param  list<string>  $default
 * @return list<string>
 */
function orbit_worker_command_argv(array $default, string $extraArgs): array
{
    if ($extraArgs === '') {
        return array_values($default);
    }

    $tokens = preg_split('/\s+/', trim($extraArgs)) ?: [];

    return array_values(array_merge(
        $default,
        array_values(array_filter($tokens, static fn (string $token): bool => $token !== '')),
    ));
}

function orbit_worker_command_string(array $argv, string $extraArgs): string
{
    $base = implode(' ', $argv);

    if ($extraArgs === '') {
        return $base;
    }

    return $base.' '.$extraArgs;
}
