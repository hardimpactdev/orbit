<?php

declare(strict_types=1);

/**
 * tmux LAND helpers shared by finalization and bin/orbit-feature-land.
 * Renamed from orbit-finalization-solo-land.php.
 *
 * Finalization classification/checks still depend on hook primitives when used
 * from the hook: shell_words, worktrees, ok, block_result, landed_feature_problem,
 * session_archive_problem, session_archive_tracked_problem.
 */

if (is_file(__DIR__.'/orbit-tmux.php')) {
    require_once __DIR__.'/orbit-tmux.php';
}

/**
 * Shared archive slug primitive used by finalization cleanup discovery and LAND.
 * Empty/punctuation-only values fall back to `session` (not `feature`).
 */
function orbit_land_archive_slug(string $value): string
{
    $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $value));
    $slug = trim($slug, '-');

    return $slug === '' ? 'session' : $slug;
}

/**
 * Classify tmux LAND boundary actions by command position in each chain segment.
 * Quoted strings containing "tmux kill-session" are not command positions.
 *
 * @return list<array{type: 'tmux-session-kill', session: string, socket: null|array{flag: '-L'|'-S', value: string}}|array{type: 'invalid', subject: string, reason: string}>
 */
function tmux_boundary_actions(string $command): array
{
    $trimmed = trim($command);

    if ($trimmed === '') {
        return [];
    }

    $segments = preg_split('/\s*(?:&&|\|\||;|\n)\s*/', $trimmed) ?: [];
    $segments = array_values(array_filter($segments, static fn (string $segment): bool => trim($segment) !== ''));
    $actions = [];

    foreach ($segments as $segment) {
        $action = classify_tmux_land_command(shell_words(trim($segment)));

        if ($action !== null) {
            $actions[] = $action;
        }
    }

    if ($actions === []) {
        return [];
    }

    if (count($segments) > 1 || count($actions) > 1) {
        return [[
            'type' => 'invalid',
            'subject' => 'tmux LAND command',
            'reason' => 'tmux kill-session boundaries must be a single unchained command',
        ]];
    }

    return $actions;
}

/**
 * @param  list<string>  $words
 * @return array{type: 'tmux-session-kill', session: string, socket: null|array{flag: '-L'|'-S', value: string}}|array{type: 'invalid', subject: string, reason: string}|null
 */
function classify_tmux_land_command(array $words): ?array
{
    if ($words === [] || basename($words[0]) !== 'tmux') {
        return null;
    }

    $subcommand = null;
    $target = null;
    $sawTarget = false;
    $extraOperands = false;
    $unknownOption = null;
    $socketProblem = null;
    /** @var null|array{flag: '-L'|'-S', value: string} $socket */
    $socket = null;

    for ($i = 1; $i < count($words); $i++) {
        $word = $words[$i];

        if ($word === '-L' || $word === '-S') {
            if (! isset($words[$i + 1]) || str_starts_with($words[$i + 1], '-')) {
                $socketProblem ??= 'tmux -L/-S requires a socket name or path';
                continue;
            }

            if (tmux_socket_value_rejected($words[$i + 1])) {
                $socketProblem ??= 'tmux -L/-S rejects quoted or shell-fragment values';
            } elseif ($socket !== null) {
                $extraOperands = true;
            } else {
                $socket = ['flag' => $word, 'value' => $words[$i + 1]];
            }

            $i++;

            continue;
        }

        if (str_starts_with($word, '-L') || str_starts_with($word, '-S')) {
            $flag = str_starts_with($word, '-L') ? '-L' : '-S';
            $value = substr($word, 2);

            if (str_starts_with($value, '=')) {
                $value = substr($value, 1);
            }

            if (tmux_socket_value_rejected($value)) {
                $socketProblem ??= 'tmux -L/-S rejects quoted or shell-fragment values';
            } elseif ($socket !== null) {
                $extraOperands = true;
            } else {
                $socket = ['flag' => $flag, 'value' => $value];
            }

            continue;
        }

        if ($word === '-t') {
            if ($sawTarget) {
                $extraOperands = true;
                $i += isset($words[$i + 1]) ? 1 : 0;

                continue;
            }

            if (! isset($words[$i + 1]) || str_starts_with($words[$i + 1], '-')) {
                $sawTarget = true;
                $target = null;

                continue;
            }

            $target = $words[$i + 1];
            $sawTarget = true;
            $i++;

            continue;
        }

        if (str_starts_with($word, '-t')) {
            if ($sawTarget) {
                $extraOperands = true;

                continue;
            }

            $target = substr($word, 2);
            $sawTarget = true;

            continue;
        }

        if (str_starts_with($word, '-')) {
            $unknownOption ??= $word;

            continue;
        }

        if ($subcommand === null) {
            $subcommand = $word;

            continue;
        }

        $extraOperands = true;
    }

    if ($subcommand !== 'kill-session') {
        if (is_string($subcommand) && preg_match('/^kill-/', $subcommand) === 1) {
            return tmux_kill_invalid(
                "tmux {$subcommand} is not an allowed LAND boundary; only kill-session -t =feat-<slug> is accepted",
            );
        }

        return null;
    }

    if ($socketProblem !== null) {
        return tmux_kill_invalid($socketProblem);
    }

    if ($unknownOption !== null) {
        return tmux_kill_invalid("tmux kill-session rejects unknown option `{$unknownOption}`");
    }

    if ($extraOperands) {
        return tmux_kill_invalid('tmux kill-session rejects extra operands');
    }

    if (! $sawTarget || ! is_string($target) || preg_match('/^=feat-[a-z0-9][a-z0-9-]*$/', $target) !== 1) {
        return tmux_kill_invalid(
            'tmux kill-session requires exact-match target -t =feat-<slug> (the = prefix is mandatory)',
        );
    }

    return [
        'type' => 'tmux-session-kill',
        'session' => substr($target, 1),
        'socket' => $socket,
    ];
}

/**
 * @param  array{type: 'tmux-session-kill', session: string, socket?: null|array{flag: '-L'|'-S', value: string}}  $action
 * @return array{ok: bool, subject: string, reason: string, warnings: list<string>}
 */
function check_tmux_session_kill(array $action, string $root, string $cwd): array
{
    land_require_tmux_helper();

    $session = $action['session'];
    $subject = "tmux kill-session {$session}";
    $socket = $action['socket'] ?? null;

    $has = orbit_tmux_has_session($session, $socket);

    if ($has['status'] === 'not_found') {
        return ok();
    }

    if ($has['status'] === 'error') {
        return block_result($subject, 'tmux lookup failed: '.($has['reason'] ?? 'unknown'));
    }

    $pathLookup = orbit_tmux_session_path($session, $socket);

    if ($pathLookup['status'] === 'not_found') {
        return ok();
    }

    if ($pathLookup['status'] === 'error') {
        return block_result($subject, 'tmux lookup failed: '.($pathLookup['reason'] ?? 'unknown'));
    }

    $path = $pathLookup['path'] ?? null;

    if (! is_string($path) || $path === '') {
        return block_result($subject, "{$subject} target session has no canonical path");
    }

    $canonical = realpath($path) ?: $path;
    $rootReal = realpath($root) ?: $root;

    if ($canonical === $rootReal) {
        return block_result($subject, "{$subject} refuses the primary/root project path `{$canonical}`");
    }

    $cwdReal = realpath($cwd) ?: $cwd;

    if ($cwdReal === $canonical || str_starts_with($cwdReal, rtrim($canonical, '/').'/')) {
        return block_result(
            $subject,
            "{$subject} refuses self-cleanup while the caller cwd lives inside the target project path `{$canonical}`",
        );
    }

    $branch = null;

    foreach (worktrees($root) as $worktree) {
        $worktreePath = realpath($worktree['path']) ?: $worktree['path'];

        if ($worktreePath === $canonical) {
            $branch = $worktree['branch'] ?? null;

            break;
        }
    }

    if ($branch === null || $branch === '') {
        return block_result(
            $subject,
            "{$subject} requires exact canonical project path to equal a linked feature worktree of this checkout; got `{$canonical}`",
        );
    }

    if (in_array($branch, ['main', 'master'], true)) {
        return block_result(
            $subject,
            "{$subject} refuses primary branch worktree ownership for `{$branch}` at `{$canonical}`",
        );
    }

    $callerSession = orbit_tmux_current_session();

    if ($callerSession === $session) {
        return block_result(
            $subject,
            "refuses to land from inside the feature session {$session}; run LAND from the orchestrator session or outside tmux",
        );
    }

    $cleanupGate = land_landed_archive_gate($root, $branch, $subject);

    return $cleanupGate === null ? ok() : block_result($subject, $cleanupGate);
}

function land_landed_archive_gate(string $root, string $branch, string $subject): ?string
{
    $landedProblem = landed_feature_problem($root, $branch);

    if ($landedProblem !== null) {
        return "{$subject} blocked: {$landedProblem}";
    }

    $archiveProblem = session_archive_problem($root, $branch);

    if ($archiveProblem !== null) {
        return "{$subject} blocked: {$archiveProblem}";
    }

    $trackedProblem = session_archive_tracked_problem($root, $branch);

    if ($trackedProblem !== null) {
        return "{$subject} blocked: {$trackedProblem}";
    }

    return null;
}

function land_require_tmux_helper(): void
{
    static $loaded = false;

    if ($loaded) {
        return;
    }

    $path = __DIR__.'/orbit-tmux.php';

    if (! is_file($path)) {
        throw new RuntimeException('bin/orbit-tmux.php is required for tmux session lookups');
    }

    require_once $path;
    $loaded = true;
}

function tmux_socket_value_rejected(string $value): bool
{
    return (
        $value === ''
        || str_contains($value, "'")
        || str_contains($value, '"')
        || str_contains($value, '\\')
        || preg_match('/\s/', $value) === 1
    );
}

/**
 * @return array{type: 'invalid', subject: string, reason: string}
 */
function tmux_kill_invalid(string $reason): array
{
    return [
        'type' => 'invalid',
        'subject' => 'tmux kill-session',
        'reason' => $reason,
    ];
}
