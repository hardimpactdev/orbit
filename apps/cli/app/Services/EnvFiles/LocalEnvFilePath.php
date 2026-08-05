<?php

declare(strict_types=1);

namespace App\Services\EnvFiles;

/**
 * Lexical allowlist and symlink-escape checks for internal env-file paths.
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final readonly class LocalEnvFilePath
{
    private const array ALLOWED_ROOT_PREFIXES = [
        '/home/orbit/',
        '/srv/',
        '/var/www/',
    ];

    private const string PRODUCTION_APP_ENV_PATTERN = '#\A/home/[a-z_][a-z0-9_-]*/app/\.env\z#';

    private const string DEVELOPMENT_APP_ENV_PATTERN = '#\A(?:/home/[a-z_][a-z0-9_-]*|/Users/[A-Za-z0-9][A-Za-z0-9._-]*)/apps/[a-z0-9][a-z0-9._-]*/\.env\z#';

    /**
     * Exact Orbit-managed development worktree env paths.
     * Linux: /home/<user>/apps/<project>/.worktrees/<workspace>/.env
     * macOS: /Users/<user>/apps/<project>/.worktrees/<workspace>/.env
     *
     * One project segment, literal `.worktrees`, one workspace segment, terminal `.env`.
     */
    private const string DEVELOPMENT_WORKTREE_ENV_PATTERN = '#\A(?:/home/[a-z_][a-z0-9_-]*|/Users/[A-Za-z0-9][A-Za-z0-9._-]*)/apps/[a-z0-9][a-z0-9._-]*/\.worktrees/[a-z0-9][a-z0-9._-]*/\.env\z#';

    /**
     * Exact Codex worktree workspace env paths used by registered workspaces.
     * Linux: /home/<user>/.codex/worktrees/<id>/<project>/.env
     * macOS: /Users/<user>/.codex/worktrees/<id>/<project>/.env
     */
    private const string CODEX_WORKTREE_ENV_PATTERN = '#\A(?:/home/[a-z_][a-z0-9_-]*|/Users/[A-Za-z0-9][A-Za-z0-9._-]*)/\.codex/worktrees/[a-z0-9]+/[A-Za-z0-9][A-Za-z0-9._-]*/\.env\z#';

    public function validate(mixed $value): string
    {
        if (! is_string($value) || $value === '' || str_contains($value, "\0")) {
            throw $this->invalid();
        }

        if (! $this->isLexicallyAllowed($value)) {
            throw $this->invalid();
        }

        $this->assertNoSymlinkEscape($value);

        return $value;
    }

    public function assertWritableTarget(string $path): void
    {
        $this->assertNoSymlinkEscape($path);

        if (is_link($path)) {
            throw $this->invalid();
        }

        // Existing targets must be regular files. A directory (or other non-file)
        // must not be accepted: atomic replace via rename/mv would otherwise
        // move the staged temp into the directory and report a false success.
        if (file_exists($path) && ! is_file($path)) {
            throw $this->invalid();
        }
    }

    public function isReadableFile(string $path): bool
    {
        return is_file($path) && ! is_link($path);
    }

    private function isLexicallyAllowed(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0")) {
            return false;
        }

        if (! str_starts_with($path, '/') || ! str_ends_with($path, '/.env')) {
            return false;
        }

        if (str_contains($path, '/../') || str_contains($path, '/./') || str_ends_with($path, '/..')) {
            return false;
        }

        if (
            preg_match(self::PRODUCTION_APP_ENV_PATTERN, $path) === 1
            || preg_match(self::DEVELOPMENT_APP_ENV_PATTERN, $path) === 1
            || preg_match(self::DEVELOPMENT_WORKTREE_ENV_PATTERN, $path) === 1
            || preg_match(self::CODEX_WORKTREE_ENV_PATTERN, $path) === 1
        ) {
            return true;
        }

        return array_any(
            self::ALLOWED_ROOT_PREFIXES,
            static fn (string $prefix): bool => str_starts_with($path, $prefix),
        );
    }

    private function assertNoSymlinkEscape(string $path): void
    {
        if (is_link($path)) {
            throw $this->invalid();
        }

        $directory = dirname($path);

        if (is_dir($directory) || is_link($directory)) {
            $resolvedDirectory = realpath($directory);

            if ($resolvedDirectory === false || $resolvedDirectory === '') {
                throw $this->invalid();
            }

            if (! $this->isResolvedAllowed("{$resolvedDirectory}/.env")) {
                throw $this->invalid();
            }
        }

        if (! file_exists($path)) {
            return;
        }

        if (is_link($path)) {
            throw $this->invalid();
        }

        $resolvedFile = realpath($path);

        if ($resolvedFile === false || $resolvedFile === '' || ! $this->isResolvedAllowed($resolvedFile)) {
            throw $this->invalid();
        }
    }

    private function isResolvedAllowed(string $resolvedPath): bool
    {
        if ($this->isLexicallyAllowed($resolvedPath)) {
            return true;
        }

        foreach (['/home', '/Users', '/srv', '/var/www', '/home/orbit'] as $logicalRoot) {
            $realRoot = realpath($logicalRoot);

            if ($realRoot === false || $realRoot === '') {
                continue;
            }

            if ($resolvedPath !== $realRoot && ! str_starts_with($resolvedPath, "{$realRoot}/")) {
                continue;
            }

            $logicalPath = $logicalRoot.substr($resolvedPath, strlen($realRoot));

            if ($this->isLexicallyAllowed($logicalPath)) {
                return true;
            }
        }

        return false;
    }

    private function invalid(): LocalEnvFileFailure
    {
        return new LocalEnvFileFailure(errorCode: 'validation_failed', message: 'Env file path is invalid.', meta: [
            'field' => 'path',
        ]);
    }
}
