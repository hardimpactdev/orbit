<?php

declare(strict_types=1);

const ORBIT_NATIVE_MULTI_TOKEN_COMMANDS = [
    'node role:add',
    'node role:list',
    'node role:remove',
];

/**
 * Normalize native argv quirks before Symfony binds global options.
 *
 * @param  list<string>  $argv
 * @return list<string>
 */
function normalizeNativeCommandArgv(array $argv): array
{
    return normalizeNativeToolInstallVersionArgv(
        normalizeNativeMultiTokenCommandArgv($argv),
    );
}

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
 * Rewrite the public `tool:install --version=<version>` contract to an
 * internal option name because Symfony reserves `--version` globally.
 *
 * @param  list<string>  $argv
 * @return list<string>
 */
function normalizeNativeToolInstallVersionArgv(array $argv): array
{
    if ($argv === []) {
        return [];
    }

    $rewritten = [];
    $insideToolInstall = false;
    $afterEndOfOptions = false;
    $count = count($argv);

    for ($index = 0; $index < $count; $index++) {
        $argument = $argv[$index];

        if ($index === 0) {
            $rewritten[] = $argument;

            continue;
        }

        if ($afterEndOfOptions) {
            $rewritten[] = $argument;

            continue;
        }

        if ($argument === '--') {
            $rewritten[] = $argument;
            $afterEndOfOptions = true;

            continue;
        }

        if (! $insideToolInstall && $argument !== '' && ! str_starts_with($argument, '-')) {
            $insideToolInstall = $argument === 'tool:install';
            $rewritten[] = $argument;

            continue;
        }

        if ($insideToolInstall && str_starts_with($argument, '--version=')) {
            $rewritten[] = '--tool-version='.substr($argument, strlen('--version='));

            continue;
        }

        if ($insideToolInstall && $argument === '--version' && $index + 1 < $count && ! str_starts_with($argv[$index + 1], '-')) {
            $rewritten[] = '--tool-version='.$argv[$index + 1];
            $index++;

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
