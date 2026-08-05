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
 *
 * @mago-expect lint:cyclomatic-complexity -- Ensure branches for zsh skip, HOME failure, and FS write paths.
 * @mago-expect lint:kan-defect -- Ensure + path-normalization helpers for HOME/ZDOTDIR/root safety.
 * @mago-expect lint:too-many-methods -- Small pure helpers keep the ensure path readable.
 * @mago-expect lint:no-error-control-operator -- Soft user-home FS ops (mkdir/chmod/rename/unlink/append).
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
     * Ensure the managed snippet and a single append-only source block in the
     * active zsh rc when the active shell is zsh.
     *
     * The Orbit-owned snippet always lives under `$HOME/.config/orbit/shell/`.
     * The managed source block is appended to `$ZDOTDIR/.zshrc` when `ZDOTDIR`
     * is a non-empty export, otherwise `$HOME/.zshrc`, matching zsh startup.
     *
     * @return array{
     *     status: self::STATUS_*,
     *     snippet_path: string|null,
     *     zshrc_path: string|null,
     *     message: string,
     * }
     */
    public function ensure(?string $home = null, ?string $shell = null, ?string $zdotdir = null): array
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

        $snippetPath = $this->joinUnderHome($resolvedHome, self::snippetRelativePath());
        $zshrcPath = $this->resolveZshrcPath($resolvedHome, $zdotdir);

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
        // Prefer explicit lines over a nowdoc so formatter presets cannot indent
        // the managed snippet body.
        return implode("\n", [
            '# Orbit-managed zsh integration.',
            '# Preserve literal argv for Orbit namespace wildcards such as process:* and',
            '# node:* when operators type unquoted flags. Applies only to the orbit command',
            '# via noglob; does not set NONOMATCH or change globbing for other commands.',
            "alias orbit='noglob orbit'",
            '',
        ]);
    }

    public static function zshrcBlock(string $snippetPath): string
    {
        $quoted = self::singleQuote($snippetPath);

        return self::BEGIN_MARKER."\n".self::sourceLine($snippetPath)."\n".self::END_MARKER."\n";
    }

    public static function sourceLine(string $snippetPath): string
    {
        $quoted = self::singleQuote($snippetPath);

        return '[ -f '.$quoted.' ] && . '.$quoted;
    }

    /**
     * Pure path resolution for HOME/ZDOTDIR without writing files.
     *
     * @return array{snippet_path: string, zshrc_path: string}|null
     */
    public function resolvePaths(string $home, ?string $zdotdir = null): ?array
    {
        $resolvedHome = $this->normalizeDirectoryPath($home);

        if ($resolvedHome === null) {
            return null;
        }

        return [
            'snippet_path' => $this->joinUnderHome($resolvedHome, self::snippetRelativePath()),
            'zshrc_path' => $this->resolveZshrcPath($resolvedHome, $zdotdir),
        ];
    }

    public function isZsh(?string $shell): bool
    {
        if (! is_string($shell) || $shell === '') {
            return false;
        }

        // Match bin/install-orbit: exact basename `zsh` only (not `not-zsh`, `zsh-5.9`, …).
        return strtolower(basename($shell)) === 'zsh';
    }

    /**
     * True when contents already contain the exact complete managed block for the snippet.
     */
    public static function hasCompleteManagedBlock(string $contents, string $snippetPath): bool
    {
        return (
            str_contains($contents, self::zshrcBlock($snippetPath))
            || str_contains($contents, rtrim(self::zshrcBlock($snippetPath), "\n"))
        );
    }

    /**
     * Remove managed Orbit zsh integration lines while preserving all unknown
     * user content.
     *
     * - A complete BEGIN…END block is removed only as a bounded range.
     * - An orphan BEGIN with no END removes only that exact marker line (never
     *   consumes following user lines).
     * - Orphan END lines and the exact Orbit source line for `$snippetPath` are
     *   removed when recognizable.
     */
    public static function stripManagedBlocks(string $contents, string $snippetPath): string
    {
        $stripped = preg_replace(
            '/'.preg_quote(self::BEGIN_MARKER, '/').'.*?'.preg_quote(self::END_MARKER, '/').'\n?/s',
            '',
            $contents,
        );

        if (! is_string($stripped)) {
            $stripped = $contents;
        }

        // Orphan markers only — never delete arbitrary later user content.
        $stripped = preg_replace('/^'.preg_quote(self::BEGIN_MARKER, '/').'\n?/m', '', $stripped) ?? $stripped;
        $stripped = preg_replace('/^'.preg_quote(self::END_MARKER, '/').'\n?/m', '', $stripped) ?? $stripped;
        $stripped =
            preg_replace(
                '/^'.preg_quote(self::sourceLine($snippetPath), '/').'\n?/m',
                '',
                $stripped,
            ) ?? $stripped;

        return $stripped;
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
     * the process environment. The root path `/` is preserved (never collapsed
     * to empty by trailing-slash stripping).
     */
    private function resolveHome(?string $home): ?string
    {
        if ($home !== null) {
            return $this->normalizeDirectoryPath($home);
        }

        $envHome = getenv('HOME');

        if (is_string($envHome) && $envHome !== '') {
            return $this->normalizeDirectoryPath($envHome);
        }

        return null;
    }

    /**
     * Resolve the zsh rc path zsh will load.
     *
     * A non-empty `ZDOTDIR` (parameter or environment) wins; otherwise `$HOME/.zshrc`.
     * An explicit empty string forces the HOME fallback without reading the env.
     */
    private function resolveZshrcPath(string $home, ?string $zdotdir): string
    {
        $resolved = $this->resolveZdotdir($zdotdir);

        if ($resolved !== null) {
            return $resolved === '/' ? '/.zshrc' : $resolved.'/.zshrc';
        }

        return $home === '/' ? '/.zshrc' : $home.'/.zshrc';
    }

    private function resolveZdotdir(?string $zdotdir): ?string
    {
        if ($zdotdir !== null) {
            return $this->normalizeDirectoryPath($zdotdir);
        }

        $envZdotdir = getenv('ZDOTDIR');

        if (is_string($envZdotdir) && $envZdotdir !== '') {
            return $this->normalizeDirectoryPath($envZdotdir);
        }

        return null;
    }

    /**
     * Strip trailing slashes without turning `/` into an empty path.
     * Matches `bin/install-orbit` `${ZDOTDIR%/}` root-safe behavior for `/`.
     */
    private function normalizeDirectoryPath(string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        if ($path === '/') {
            return '/';
        }

        $trimmed = rtrim($path, '/');

        return $trimmed === '' ? '/' : $trimmed;
    }

    private function joinUnderHome(string $home, string $relative): string
    {
        $relative = ltrim($relative, '/');

        return $home === '/' ? '/'.$relative : $home.'/'.$relative;
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
     * Ensure exactly one complete managed source block for the snippet path.
     *
     * Only the exact complete block (begin marker, source line for this snippet,
     * end marker) counts as already present. A begin-marker-only or otherwise
     * partial interrupted append is repaired to one complete block. Writes go
     * through the existing path so symlink targets and modes are preserved
     * (never rename-over the rc file).
     *
     * @return self::STATUS_INSTALLED|self::STATUS_ALREADY_PRESENT|self::STATUS_FAILED
     */
    private function ensureZshrcBlock(string $zshrcPath, string $snippetPath): string
    {
        $directory = dirname($zshrcPath);

        if ($directory !== '' && $directory !== '.' && ! is_dir($directory)) {
            if (! @mkdir($directory, 0700, recursive: true) && ! is_dir($directory)) {
                return self::STATUS_FAILED;
            }
        }

        $block = self::zshrcBlock($snippetPath);

        if (is_file($zshrcPath) || is_link($zshrcPath)) {
            $existing = @file_get_contents($zshrcPath);

            if ($existing === false) {
                return self::STATUS_FAILED;
            }

            if (self::hasCompleteManagedBlock($existing, $snippetPath)) {
                return self::STATUS_ALREADY_PRESENT;
            }

            $cleaned = self::stripManagedBlocks($existing, $snippetPath);
            $separator = $cleaned === '' || str_ends_with($cleaned, "\n") ? '' : "\n";
            // Write through the path (follows symlink); do not rename-over.
            $written = @file_put_contents($zshrcPath, $cleaned.$separator.$block, LOCK_EX);

            return $written === false ? self::STATUS_FAILED : self::STATUS_INSTALLED;
        }

        $written = @file_put_contents($zshrcPath, $block, LOCK_EX);

        return $written === false ? self::STATUS_FAILED : self::STATUS_INSTALLED;
    }

    private static function singleQuote(string $value): string
    {
        return "'".str_replace(search: "'", replace: "'\\''", subject: $value)."'";
    }
}
