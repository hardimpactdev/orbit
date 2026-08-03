<?php

declare(strict_types=1);

namespace App\Services\Tools;

/**
 * Shared shell helpers for managed tool install/configure/credentials scripts.
 *
 * Keeps quoting and non-empty secret file regeneration consistent across
 * OpenClaw, Hermes, and similar managed tools without inventing a new
 * credential product model.
 */
final class ManagedToolShell
{
    /**
     * Single-quote a value as one shell word (for outer argv such as bash -lc).
     */
    public static function singleQuote(string $value): string
    {
        return "'".str_replace(search: "'", replace: "'\\''", subject: $value)."'";
    }

    /**
     * Double-quote a value for use inside a script that will later be wrapped
     * by {@see singleQuote()} as a bash -lc argument.
     */
    public static function doubleQuote(string $value): string
    {
        return (
            '"'
            .str_replace(
                search: ['\\', '"', '$', '`'],
                replace: ['\\\\', '\\"', '\\$', '\\`'],
                subject: $value,
            )
            .'"'
        );
    }

    /**
     * Bash snippet: regenerate $fileVar when missing, zero-byte, or whitespace-only.
     * $generateCommand must write the secret to stdout (e.g. openssl rand -hex 32).
     *
     * Safe inside a script that is outer-wrapped with {@see singleQuote()}.
     */
    public static function ensureNonEmptySecretFile(string $fileVar, string $generateCommand): string
    {
        return (
            'if [ -z "$(tr -d "[:space:]" < "'
            .$fileVar
            .'" 2>/dev/null || true)" ]; then '
            .$generateCommand
            .' > "'
            .$fileVar
            .'"; chmod 600 "'
            .$fileVar
            .'"; fi; '
        );
    }

    /**
     * Bash snippet: load a secret file into $targetVar with whitespace stripped,
     * failing closed when empty.
     *
     * The missing message is double-quoted so this snippet remains valid when
     * the complete inner script is later single-quoted once for bash -lc.
     */
    public static function requireNonEmptySecretFromFile(
        string $fileVar,
        string $targetVar,
        string $missingMessage,
    ): string {
        return (
            $targetVar
            .'="$(tr -d "[:space:]" < "'
            .$fileVar
            .'" 2>/dev/null || true)"; '
            .'[ -n "${'
            .$targetVar
            .'}" ] || { echo '
            .self::doubleQuote($missingMessage)
            .' >&2; exit 1; }; '
        );
    }
}
