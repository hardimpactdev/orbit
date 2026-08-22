#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * @param  list<string>  $argv
 */
function command_from_argv(array $argv): ?string
{
    if (count($argv) <= 1) {
        return null;
    }

    return implode(' ', array_slice($argv, 1));
}

function extract_command(string $input): ?string
{
    $trimmed = trim($input);

    if ($trimmed === '') {
        return null;
    }

    $decoded = json_decode($trimmed, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return $trimmed;
    }

    if (! is_array($decoded)) {
        return null;
    }

    return find_command_value($decoded);
}

/**
 * @param  array<mixed>  $value
 */
function find_command_value(array $value): ?string
{
    foreach (['command', 'cmd'] as $key) {
        if (isset($value[$key]) && is_string($value[$key])) {
            return $value[$key];
        }
    }

    foreach (['tool_input', 'input', 'arguments', 'params', 'payload'] as $key) {
        if (isset($value[$key]) && is_array($value[$key])) {
            $command = find_command_value($value[$key]);

            if ($command !== null) {
                return $command;
            }
        }
    }

    foreach ($value as $item) {
        if (is_array($item)) {
            $command = find_command_value($item);

            if ($command !== null) {
                return $command;
            }
        }
    }

    return null;
}

/**
 * @return array{type: 'merge', targets: list<string>}|array{type: 'worktree-remove', path: string}|array{type: 'branch-delete', branch: string}|array{type: 'tmux-session-kill', session: string}|array{type: 'invalid', subject: string, reason: string}|null
 */
function classify_command(string $command): ?array
{
    $mergeCommands = git_merge_command_arguments($command);
    $actionableMergeCommands = array_values(array_filter(
        $mergeCommands,
        static fn (array $arguments): bool => (
            ! isset($arguments[0]) || ! in_array($arguments[0], ['--abort', '--continue', '--quit', '--skip'], true)
        ),
    ));
    $tmuxActions = tmux_boundary_actions($command);
    $destructiveActionCount =
        count($actionableMergeCommands)
        + worktree_remove_command_count($command)
        + branch_delete_command_count($command)
        + count($tmuxActions);

    if ($destructiveActionCount > 1) {
        return [
            'type' => 'invalid',
            'subject' => 'destructive boundary',
            'reason' => "exactly one destructive boundary action is allowed; found {$destructiveActionCount}",
        ];
    }

    if (count($actionableMergeCommands) === 1) {
        $unsafeMergeOption = unsafe_merge_option_problem($actionableMergeCommands[0]);

        if ($unsafeMergeOption !== null) {
            return ['type' => 'invalid', 'subject' => 'git merge', 'reason' => $unsafeMergeOption];
        }
    }

    if ($mergeCommands !== []) {
        $targets = [];
        $actionableCommands = 0;

        foreach ($mergeCommands as $arguments) {
            if (isset($arguments[0]) && in_array($arguments[0], ['--abort', '--continue', '--quit', '--skip'], true)) {
                continue;
            }

            $actionableCommands++;
            array_push($targets, ...merge_head_operands_from_words($arguments));
        }

        if ($actionableCommands > 1) {
            $targets[] = '__multiple_git_merge_commands__';
        }

        if ($actionableCommands > 0) {
            return ['type' => 'merge', 'targets' => $targets];
        }
    }

    if (preg_match('/\bgit\s+worktree\s+remove\s+/', $command) === 1) {
        $segment = command_segment_after($command, '/\bgit\s+worktree\s+remove\s+/');
        $targets = non_option_tokens($segment);

        if (count($targets) !== 1) {
            return [
                'type' => 'invalid',
                'subject' => 'git worktree remove',
                'reason' => 'git worktree remove requires exactly one worktree target; found '.count($targets),
            ];
        }

        return ['type' => 'worktree-remove', 'path' => $targets[0]];
    }

    if (
        preg_match('/\bgit\s+branch\s+/', $command) === 1
        && preg_match('/\s(?:-d|-D|--delete|--force-delete)(?:\s|=)/', ' '.$command.' ') === 1
    ) {
        $segment = command_segment_after($command, '/\bgit\s+branch\s+/');
        $targets = branch_delete_targets($segment);

        if (count($targets) !== 1) {
            return [
                'type' => 'invalid',
                'subject' => 'git branch delete',
                'reason' => 'git branch delete requires exactly one branch target; found '.count($targets),
            ];
        }

        return ['type' => 'branch-delete', 'branch' => $targets[0]];
    }

    if (count($tmuxActions) === 1) {
        return $tmuxActions[0];
    }

    return null;
}

/**
 * The `composer test:e2e*` lanes are human-only; deny every executable agent
 * form: direct, suffix variant, env-prefixed, path or phar, run/run-script,
 * chained segment, and shell -c wrapper. Quoted prose stays one token in
 * shell_words(), so mentions inside messages or searches never classify as
 * execution. Returns the matched invocation, or null when none exists.
 */
function e2e_manual_only_invocation(string $command): ?string
{
    foreach (preg_split('/\s*(?:&&|\|\||;|\n)\s*/', $command) ?: [] as $segment) {
        $words = shell_words($segment);

        foreach ($words as $index => $word) {
            $binary = basename($word);

            if (in_array($binary, ['composer', 'composer.phar'], true)) {
                $script = composer_script_operand(array_slice($words, $index + 1));

                if ($script !== null && str_starts_with($script, 'test:e2e')) {
                    return "{$binary} {$script}";
                }

                continue;
            }

            if (in_array($binary, ['bash', 'sh', 'zsh', 'dash', 'ksh'], true)) {
                $payload = shell_command_string_operand(array_slice($words, $index + 1));

                if ($payload !== null) {
                    $nested = e2e_manual_only_invocation($payload);

                    if ($nested !== null) {
                        return $nested;
                    }
                }
            }
        }
    }

    return null;
}

/**
 * Resolve the composer script operand: skip global options (with separate
 * values for -d/--working-dir) and the run/run-script subcommand words.
 *
 * @param  list<string>  $words
 */
function composer_script_operand(array $words): ?string
{
    $expectValue = false;

    foreach ($words as $word) {
        if ($expectValue) {
            $expectValue = false;

            continue;
        }

        if (str_starts_with($word, '-')) {
            if (in_array($word, ['-d', '--working-dir'], true)) {
                $expectValue = true;
            }

            continue;
        }

        if (in_array($word, ['run', 'run-script'], true)) {
            continue;
        }

        return $word;
    }

    return null;
}

/**
 * Return the command-string operand of a shell wrapper (`bash -lc '...'`)
 * when a -c style short option precedes it; plain script paths return null.
 *
 * @param  list<string>  $words
 */
function shell_command_string_operand(array $words): ?string
{
    $sawCommandOption = false;

    foreach ($words as $word) {
        if (str_starts_with($word, '--')) {
            continue;
        }

        if (str_starts_with($word, '-') && strlen($word) > 1) {
            if (str_contains($word, 'c')) {
                $sawCommandOption = true;
            }

            continue;
        }

        return $sawCommandOption ? $word : null;
    }

    return null;
}

function e2e_block_message(string $invocation, string $command): string
{
    return <<<TEXT
        Orbit E2E guard blocked `{$invocation}`.

        The `composer test:e2e*` lanes are human-only: they run only when the
        user personally decides to type the Composer command in a shell they
        operate directly. Agents never run, delegate, background, schedule,
        hook, script, or trigger these lanes, and never hand them off as a
        feature-completion step. For integrated runtime behavior, use retained
        topology proof per `HARNESS.md` Acceptance Venues; existing E2E
        artifacts may be read and triaged without execution.

        Blocked command: {$command}

        TEXT;
}

function unsafe_merge_option_problem(array $arguments): ?string
{
    foreach ($arguments as $argument) {
        if ($argument === '--squash') {
            return 'merge option --squash is not allowed because it does not land the accepted feature commit';
        }

        if ($argument === '--no-commit') {
            return 'merge option --no-commit is not allowed because landing must produce the reviewed merge directly';
        }

        if (
            in_array($argument, ['-s', '--strategy'], true)
            || preg_match('/^(?:-s.+|--strategy=.+)$/', $argument) === 1
        ) {
            return 'custom merge strategies are not allowed because they can omit accepted changes';
        }

        if (
            in_array($argument, ['-X', '--strategy-option'], true)
            || preg_match('/^(?:-X.+|--strategy-option=.+)$/', $argument) === 1
        ) {
            return 'custom merge strategy options are not allowed because they can rewrite accepted changes';
        }
    }

    return null;
}

function worktree_remove_command_count(string $command): int
{
    return preg_match_all('/\bgit\s+worktree\s+remove(?:\s+|$)/', $command);
}

function branch_delete_command_count(string $command): int
{
    $count = 0;

    foreach (preg_split('/\s*(?:&&|\|\||;|\n)\s*/', $command) ?: [] as $segment) {
        if (
            preg_match('/\bgit\s+branch\s+/', $segment) === 1
            && preg_match('/\s(?:-d|-D|--delete|--force-delete)(?:\s|=)/', ' '.$segment.' ') === 1
        ) {
            $count++;
        }
    }

    return $count;
}

/**
 * @return list<list<string>>
 */
function git_merge_command_arguments(string $command): array
{
    $commands = [];

    foreach (preg_split('/\s*(?:&&|\|\||;|\n)\s*/', $command) ?: [] as $segment) {
        $words = shell_words($segment);

        foreach ($words as $index => $word) {
            if (basename($word) !== 'git') {
                continue;
            }

            $cursor = $index + 1;
            $contextOverride = false;

            while (isset($words[$cursor])) {
                $candidate = $words[$cursor];

                if (in_array($candidate, ['-C', '-c', '--git-dir', '--work-tree', '--namespace'], true)) {
                    if (in_array($candidate, ['-C', '--git-dir', '--work-tree'], true)) {
                        $contextOverride = true;
                    }

                    $cursor += 2;

                    continue;
                }

                if (
                    preg_match('/^(?:-C|-c).+/', $candidate) === 1
                    || preg_match('/^--(?:git-dir|work-tree|namespace)=/', $candidate) === 1
                    || str_starts_with($candidate, '-')
                ) {
                    if (
                        preg_match('/^-C.+/', $candidate) === 1
                        || preg_match('/^--(?:git-dir|work-tree)=/', $candidate) === 1
                    ) {
                        $contextOverride = true;
                    }

                    $cursor++;

                    continue;
                }

                break;
            }

            if (($words[$cursor] ?? null) === 'merge') {
                $arguments = array_slice($words, $cursor + 1);

                if ($contextOverride) {
                    array_unshift($arguments, '__orbit_git_context_override__');
                }

                $commands[] = $arguments;
            }

            break;
        }
    }

    return $commands;
}

/**
 * @param  list<string>  $words
 * @return list<string>
 */
function merge_head_operands_from_words(array $words): array
{
    $valueOptions = [
        '-m',
        '--message',
        '-s',
        '--strategy',
        '-X',
        '--strategy-option',
        '--cleanup',
        '--into-name',
        '--log',
    ];
    $targets = [];
    $expectValue = false;
    $optionsEnded = false;

    foreach ($words as $word) {
        if ($expectValue) {
            $expectValue = false;

            continue;
        }

        if (! $optionsEnded && $word === '--') {
            $optionsEnded = true;

            continue;
        }

        if (! $optionsEnded && str_starts_with($word, '-')) {
            if (in_array($word, $valueOptions, true)) {
                $expectValue = true;
            }

            continue;
        }

        $targets[] = $word;
    }

    return $targets;
}

function command_segment_after(string $command, string $pattern): string
{
    if (preg_match($pattern, $command, $matches, PREG_OFFSET_CAPTURE) !== 1) {
        return '';
    }

    $offset = $matches[0][1] + strlen($matches[0][0]);
    $tail = substr($command, $offset);
    $parts = preg_split('/\s*(?:&&|\|\||;|\n)\s*/', $tail, 2);

    return trim($parts[0] ?? '');
}

/**
 * @return list<string>
 */
function shell_words(string $segment): array
{
    preg_match_all('/\'([^\']*)\'|"((?:\\\\.|[^"\\\\])*)"|(\S+)/', $segment, $matches, PREG_SET_ORDER);

    $words = [];

    foreach ($matches as $match) {
        if (isset($match[1]) && $match[1] !== '') {
            $words[] = $match[1];
        } elseif (isset($match[2]) && $match[2] !== '') {
            $words[] = stripcslashes($match[2]);
        } elseif (isset($match[3]) && $match[3] !== '') {
            $words[] = $match[3];
        }
    }

    return $words;
}

/** @return list<string> */
function non_option_tokens(string $segment): array
{
    return array_values(array_filter(
        shell_words($segment),
        static fn (string $word): bool => ! str_starts_with($word, '-'),
    ));
}

/** @return list<string> */
function branch_delete_targets(string $segment): array
{
    $sawDelete = false;
    $targets = [];

    foreach (shell_words($segment) as $word) {
        if (in_array($word, ['-d', '-D', '--delete', '--force-delete'], true)) {
            $sawDelete = true;

            continue;
        }

        if ($sawDelete && ! str_starts_with($word, '-')) {
            $targets[] = $word;
        }
    }

    return $targets;
}
