<?php

declare(strict_types=1);

namespace App\Data\Provision;

final class ProvisionContext
{
    public function __construct(
        public string $slug,
        public string $projectPath,
        public ?string $githubRepo = null,
        public ?string $cloneUrl = null,
        public ?string $template = null,
        public string $visibility = 'private',
        public ?string $phpVersion = null,
        public ?string $dbDriver = null,
        public ?string $sessionDriver = null,
        public ?string $cacheDriver = null,
        public ?string $queueDriver = null,
        public bool $minimal = false,
        public bool $fork = false,
        public ?string $displayName = null,
        public ?string $tld = 'ccc',
        public ?string $organization = null,
    ) {}

    /**
     * Get the GitHub owner (organization or personal username).
     * Organization takes precedence if set.
     */
    public function getGitHubOwner(?string $fallbackUsername = null): ?string
    {
        return $this->organization ?? $fallbackUsername;
    }

    public function getHomeDir(): string
    {
        return $_SERVER['HOME'] ?? '/home/orbit';
    }

    public function getPhpEnv(): array
    {
        $home = $this->getHomeDir();

        return [
            'HOME' => $home,
            'PATH' => $this->getCleanPath(),
        ];
    }

    /**
     * Get the PATH string for clean environment commands.
     * Includes paths for PHP, bun, node, and standard system binaries.
     */
    public function getCleanPath(): string
    {
        $home = $this->getHomeDir();

        // Include bun path for node package manager commands
        return "{$home}/.bun/bin:{$home}/.local/bin:/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin";
    }

    /**
     * Wrap a command with env -i to prevent inherited environment variables
     * from overriding the project's .env file.
     *
     * This is necessary because phpdotenv (used by Laravel) does NOT override
     * existing environment variables. When running artisan commands from within
     * Horizon, the parent process's env vars would otherwise take precedence.
     *
     * Also critical for bun/npm commands which can pick up APP_KEY and other
     * Laravel environment variables that interfere with the new project setup.
     */
    public function wrapWithCleanEnv(string $command): string
    {
        $home = $this->getHomeDir();
        $path = $this->getCleanPath();

        return "env -i HOME={$home} PATH={$path} CI=1 {$command}";
    }
}
