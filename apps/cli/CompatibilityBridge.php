<?php

declare(strict_types=1);

/**
 * Phase 4 compatibility bridge for unported public commands.
 *
 * Until each command family is ported under Phase 6 / Phase 8, the bridge
 * forwards the listed commands to gateway Artisan. Every entry must remain
 * in the Phase 1 classification matrix at
 * `docs/superpowers/notes/cli-command-classification-2026-05-28.md`. The
 * bridge is empty when the last family is ported (Phase 11 gate).
 *
 * The bridge MUST never forward:
 *   - hidden `internal:*` commands
 *   - gateway-runtime / orbit:internal:* maintenance commands
 *   - Laravel framework, dev, or vendor commands
 *   - unknown commands
 */
const ORBIT_COMPATIBILITY_BRIDGE_ALLOW_LIST = [
    // process
    // profile
    'profile',
    // workspace
    'workspace:log',
];

const ORBIT_COMPATIBILITY_BRIDGE_FORBIDDEN_PREFIXES = [
    'internal:',
    'orbit:internal:',
    'make:',
    'stub:',
    'app:build',
    'app:install',
    'app:rename',
    'vendor:',
    'package:',
    'key:',
    'cache:',
    'queue:',
    'db:',
    'migrate',
    'schedule:work',
];

const ORBIT_NATIVE_MULTI_TOKEN_COMMANDS = [
    'node role:add',
    'node role:list',
    'node role:remove',
];

const ORBIT_COMPATIBILITY_BRIDGE_OPTION_ALLOW_LIST = [];

/**
 * @param  list<string>  $argv
 */
function fallbackToGatewayArtisanWhenCommandIsUnported(array $argv): void
{
    $command = commandNameFromArgv($argv);

    if ($command === null) {
        return;
    }

    if (! shouldBridgeToGatewayArtisan($argv, $command)) {
        return;
    }

    $artisan = dirname(__DIR__, 2).'/apps/gateway/artisan';

    if (! is_file($artisan)) {
        return;
    }

    $artisanArguments = gatewayArtisanArgumentsFromArgv($argv);

    if ($artisanArguments === null) {
        return;
    }

    passthruCommand(PHP_BINARY, [$artisan, ...$artisanArguments]);
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

/**
 * @param  list<string>  $argv
 */
function commandNameFromArgv(array $argv): ?string
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

    for ($length = count($arguments); $length >= 1; $length--) {
        $candidate = implode(' ', array_slice($arguments, 0, $length));

        if (in_array($candidate, ORBIT_COMPATIBILITY_BRIDGE_ALLOW_LIST, true)) {
            return $candidate;
        }
    }

    return $arguments[0];
}

/**
 * Rewrite orbit argv so multi-token bridge commands are passed to gateway artisan as one
 * positional argument (e.g. `node role:list`) while preserving options and trailing args.
 *
 * @param  list<string>  $argv
 * @return list<string>|null
 */
function gatewayArtisanArgumentsFromArgv(array $argv): ?array
{
    $command = commandNameFromArgv($argv);

    if ($command === null || ! shouldBridgeToGatewayArtisan($argv, $command)) {
        return null;
    }

    $commandTokenCount = substr_count($command, ' ') + 1;
    $rewritten = [];
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

function isUnportedPublicCommand(string $command): bool
{
    foreach (ORBIT_COMPATIBILITY_BRIDGE_FORBIDDEN_PREFIXES as $forbidden) {
        if ($command === $forbidden || str_starts_with($command, $forbidden)) {
            return false;
        }
    }

    return in_array($command, ORBIT_COMPATIBILITY_BRIDGE_ALLOW_LIST, true);
}

/**
 * @param  list<string>  $argv
 */
function shouldBridgeToGatewayArtisan(array $argv, ?string $command = null): bool
{
    $command ??= commandNameFromArgv($argv);

    if ($command === null) {
        return false;
    }

    if (isUnportedPublicCommand($command)) {
        return true;
    }

    $bridgeOptions = ORBIT_COMPATIBILITY_BRIDGE_OPTION_ALLOW_LIST[$command] ?? null;

    if ($bridgeOptions === null) {
        return false;
    }

    foreach ($bridgeOptions as $option) {
        if (argvContainsOption($argv, $option)) {
            return true;
        }
    }

    return false;
}

/**
 * @param  list<string>  $argv
 */
function argvContainsOption(array $argv, string $option): bool
{
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--') {
            return false;
        }

        if ($argument === $option || str_starts_with($argument, "{$option}=")) {
            return true;
        }
    }

    return false;
}

/**
 * @param  list<string>  $arguments
 */
function buildPassthruShellCommand(string $binary, array $arguments): string
{
    $parts = [$binary, ...$arguments];

    return implode(' ', array_map('escapeshellarg', $parts));
}

/**
 * @param  list<string>  $arguments
 */
function passthruCommand(string $binary, array $arguments): never
{
    passthru(buildPassthruShellCommand($binary, $arguments), $status);

    exit($status);
}
