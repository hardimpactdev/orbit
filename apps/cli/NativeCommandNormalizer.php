<?php

declare(strict_types=1);

const ORBIT_NATIVE_MULTI_TOKEN_COMMANDS = [
    'node role:add',
    'node role:list',
    'node role:remove',
];

/**
 * Convert supported multi-token native command invocations into the single
 * Symfony command-name argument expected by Laravel Zero.
 *
 * @param  list<string>  $argv
 * @return list<string>
 */
function normalizeNativeMultiTokenCommandArgv(array $argv): array
{
    if ($argv === []) {
        return [];
    }

    $command = nativeMultiTokenCommandNameFromArgv($argv);

    if ($command === null) {
        return $argv;
    }

    $commandTokenCount = substr_count($command, ' ') + 1;
    $rewritten = [$argv[0]];
    $commandInserted = false;
    $remainingCommandTokens = $commandTokenCount;
    $afterEndOfOptions = false;

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--') {
            $rewritten[] = $argument;
            $afterEndOfOptions = true;

            continue;
        }

        if ($afterEndOfOptions) {
            $rewritten[] = $argument;

            continue;
        }

        if ($argument === '' || str_starts_with($argument, '-')) {
            $rewritten[] = $argument;

            continue;
        }

        if (! $commandInserted) {
            $rewritten[] = $command;
            $commandInserted = true;
            $remainingCommandTokens--;

            continue;
        }

        if ($remainingCommandTokens > 0) {
            $remainingCommandTokens--;

            continue;
        }

        $rewritten[] = $argument;
    }

    return $rewritten;
}

/**
 * @param  list<string>  $argv
 */
function nativeMultiTokenCommandNameFromArgv(array $argv): ?string
{
    $arguments = [];

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--') {
            break;
        }

        if ($argument === '' || str_starts_with($argument, '-')) {
            continue;
        }

        $arguments[] = $argument;
    }

    if ($arguments === []) {
        return null;
    }

    for ($length = count($arguments); $length >= 2; $length--) {
        $candidate = implode(' ', array_slice($arguments, 0, $length));

        if (in_array($candidate, ORBIT_NATIVE_MULTI_TOKEN_COMMANDS, true)) {
            return $candidate;
        }
    }

    return null;
}
