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
     * Single-quote a value for safe interpolation into bash (outside or inside
     * double-quoted contexts that expand to a single word).
     */
    public static function singleQuote(string $value): string
    {
        return "'".str_replace(search: "'", replace: "'\\''", subject: $value)."'";
    }

    /**
     * Bash snippet: regenerate $fileVar when missing, zero-byte, or whitespace-only.
     * $generateCommand must write the secret to stdout (e.g. openssl rand -hex 32).
     */
    public static function ensureNonEmptySecretFile(string $fileVar, string $generateCommand): string
    {
        return 'if [ -z "$(tr -d "[:space:]" < "'.$fileVar.'" 2>/dev/null || true)" ]; then '
            .$generateCommand.' > "'.$fileVar.'"; chmod 600 "'.$fileVar.'"; fi;';
    }

    /**
     * Bash snippet: load a secret file into $targetVar with whitespace stripped,
     * failing closed when empty.
     */
    public static function requireNonEmptySecretFromFile(
        string $fileVar,
        string $targetVar,
        string $missingMessage,
    ): string {
        return $targetVar.'="$(tr -d "[:space:]" < "'.$fileVar.'" 2>/dev/null || true)"; '
            .'[ -n "${'.$targetVar.'}" ] || { echo '.self::singleQuote($missingMessage).' >&2; exit 1; }; ';
    }
}
