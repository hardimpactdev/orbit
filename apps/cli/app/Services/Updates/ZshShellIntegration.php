<?php

declare(strict_types=1);

namespace App\Services\Updates;

/**
 * Ensures the supported zsh integration so unquoted Orbit namespace wildcards
 * such as `process:*` reach the CLI literally.
 *
 * zsh defaults to NOMATCH and expands unquoted globs before exec. A command
 * wrapper cannot fix that; only a precommand modifier applied to `orbit` can.
 * This integration installs `alias orbit='noglob orbit'` for the Orbit command
 * only and never sets global NONOMATCH / nonomatch.
 *
 * Integration is installed only when the active login shell is zsh, so bash-only
 * hosts do not receive a new `~/.zshrc`. The Orbit-owned snippet under
 * `~/.config/orbit/shell/` may be rewritten on upgrade; `~/.zshrc` is append-only
 * for the managed source block so symlink targets and existing modes stay intact.
 */
final class ZshShellIntegration
{
    public const string BEGIN_MARKER = '# >>> orbit zsh integration >>>';

    public const string END_MARKER = '# <<< orbit zsh integration <<<';

    public const string SNIPPET_BASENAME = 'zsh-noglob.zsh';

    public const string STATUS_INSTALLED = 'installed';

    public const string STATUS_ALREADY_PRESENT = 'already_present';

    public const string STATUS_SKIPPED_NOT_ZSH = 'skipped_not_zsh';

    public const string STATUS_FAILED = 'failed';

    /**
     * Relative path under `$HOME` for the managed snippet.
     */
    public static function snippetRelativePath(): string
    {
        return '.config/orbit/shell/'.self::SNIPPET_BASENAME;
    }

    /**
     * Ensure the managed snippet and a single append-only source block in `~/.zshrc`
     * when the active shell is zsh.
     *
     * @return array{
     *     status: self::STATUS_*,
     *     snippet_path: string|null,
     *     zshrc_path: string|null,
     *     message: string,
     * }
     */
    public function ensure(?string $home = null, ?string $shell = null): array
    {
        $resolvedShell = $this->resolveShell($shell);

        if (! $this->isZsh($resolvedShell)) {
            return [
                'status' => self::STATUS_SKIPPED_NOT_ZSH,
                'snippet_path' => null,
                'zshrc_path' => null,
                'message' => 'Skipped zsh shell integration: active shell is not zsh.',
            ];
        }

        $resolvedHome = $this->resolveHome($home);

        if ($resolvedHome === null) {
            return [
                'status' => self::STATUS_FAILED,
                'snippet_path' => null,
                'zshrc_path' => null,
                'message' => 'Failed to ensure zsh shell integration: HOME is not set.',
            ];
        }

        $snippetPath = $resolvedHome.'/'.self::snippetRelativePath();
        $zshrcPath = $resolvedHome.'/.zshrc';

        if (! $this->writeSnippet($snippetPath)) {
            return [
                'status' => self::STATUS_FAILED,
                'snippet_path' => $snippetPath,
                'zshrc_path' => $zshrcPath,
                'message' => "Failed to ensure zsh shell integration: could not write {$snippetPath}.",
            ];
        }

        $blockStatus = $this->ensureZshrcBlock($zshrcPath, $snippetPath);

        if ($blockStatus === self::STATUS_FAILED) {
            return [
                'status' => self::STATUS_FAILED,
                'snippet_path' => $snippetPath,
                'zshrc_path' => $zshrcPath,
                'message' => "Failed to ensure zsh shell integration: could not append managed block to {$zshrcPath}.",
            ];
        }

        return [
            'status' => $blockStatus,
            'snippet_path' => $snippetPath,
            'zshrc_path' => $zshrcPath,
            'message' => $blockStatus === self::STATUS_ALREADY_PRESENT
                ? 'zsh shell integration already present.'
                : 'Installed zsh shell integration.',
        ];
    }

    public function succeeded(array $result): bool
    {
        return ($result['status'] ?? null) !== self::STATUS_FAILED;
    }

    public static function snippetContents(): string
    {
        return <<<'ZSH'
            # Orbit-managed zsh integration.
            # Preserve literal argv for Orbit namespace wildcards such as process:* and
            # node:* when operators type unquoted flags. Applies only to the orbit command
            # via noglob; does not set NONOMATCH or change globbing for other commands.
            alias orbit='noglob orbit'

            ZSH;
    }

    public static function zshrcBlock(string $snippetPath): string
    {
        $quoted = self::singleQuote($snippetPath);

        return self::BEGIN_MARKER."\n".'[ -f '.$quoted.' ] && . '.$quoted."\n".self::END_MARKER."\n";
    }

    public function isZsh(?string $shell): bool
    {
        if (! is_string($shell) || $shell === '') {
            return false;
        }

        // Match bin/install-orbit: exact basename `zsh` only (not `not-zsh`, `zsh-5.9`, …).
        return strtolower(basename($shell)) === 'zsh';
    }

    private function resolveShell(?string $shell): string
    {
        if (is_string($shell) && $shell !== '') {
            return $shell;
        }

        $envShell = getenv('SHELL');

        return is_string($envShell) ? $envShell : '';
    }

    /**
     * Resolve the home directory.
     *
     * `null` means "use process HOME". An explicit empty string is treated as
     * missing HOME so callers can force the failure path without depending on
     * the process environment.
     */
    private function resolveHome(?string $home): ?string
    {
        if ($home !== null) {
            $trimmed = rtrim($home, '/');

            return $trimmed === '' ? null : $trimmed;
        }

        $envHome = getenv('HOME');

        if (is_string($envHome) && $envHome !== '') {
            return rtrim($envHome, '/');
        }

        return null;
    }

    private function writeSnippet(string $snippetPath): bool
    {
        $directory = dirname($snippetPath);

        if (! is_dir($directory) && ! @mkdir($directory, 0700, recursive: true) && ! is_dir($directory)) {
            return false;
        }

        @chmod($directory, 0700);

        $contents = self::snippetContents();
        // Same-directory stage + rename so a hostile/stale symlink at the final
        // path is replaced rather than written through.
        $tempPath = $directory.'/.zsh-noglob.'.bin2hex(random_bytes(8));

        if (file_put_contents($tempPath, $contents, LOCK_EX) === false) {
            return false;
        }

        if (! @chmod($tempPath, 0600)) {
            @unlink($tempPath);

            return false;
        }

        if (! @rename($tempPath, $snippetPath)) {
            @unlink($tempPath);

            return false;
        }

        return true;
    }

    /**
     * Append the managed source block only when the begin marker is absent.
     * Never rewrites an existing `.zshrc` (preserves symlinks and modes). Snippet
     * upgrades alone refresh noglob behavior on the next shell start.
     *
     * @return self::STATUS_INSTALLED|self::STATUS_ALREADY_PRESENT|self::STATUS_FAILED
     */
    private function ensureZshrcBlock(string $zshrcPath, string $snippetPath): string
    {
        if (is_file($zshrcPath) || is_link($zshrcPath)) {
            $existing = @file_get_contents($zshrcPath);

            if ($existing === false) {
                return self::STATUS_FAILED;
            }

            if (str_contains($existing, self::BEGIN_MARKER)) {
                return self::STATUS_ALREADY_PRESENT;
            }

            $prefix = str_ends_with($existing, "\n") || $existing === '' ? '' : "\n";
            $written = @file_put_contents(
                $zshrcPath,
                $prefix.self::zshrcBlock($snippetPath),
                FILE_APPEND | LOCK_EX,
            );

            return $written === false ? self::STATUS_FAILED : self::STATUS_INSTALLED;
        }

        $written = @file_put_contents(
            $zshrcPath,
            self::zshrcBlock($snippetPath),
            FILE_APPEND | LOCK_EX,
        );

        return $written === false ? self::STATUS_FAILED : self::STATUS_INSTALLED;
    }

    private static function singleQuote(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }
}
