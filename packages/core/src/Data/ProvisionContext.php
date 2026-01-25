<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Data;

/**
 * Context object passed through all provisioning actions.
 *
 * Contains all parameters needed for project provisioning.
 * Immutable - use withRepoInfo() to create updated instances.
 */
final readonly class ProvisionContext
{
    public function __construct(
        public string $slug,
        public string $projectPath,
        public ?int $projectId = null,
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
     * Create new context with updated GitHub repo info.
     */
    public function withRepoInfo(?string $githubRepo, ?string $cloneUrl): self
    {
        return new self(
            slug: $this->slug,
            projectPath: $this->projectPath,
            projectId: $this->projectId,
            githubRepo: $githubRepo,
            cloneUrl: $cloneUrl,
            template: $this->template,
            visibility: $this->visibility,
            phpVersion: $this->phpVersion,
            dbDriver: $this->dbDriver,
            sessionDriver: $this->sessionDriver,
            cacheDriver: $this->cacheDriver,
            queueDriver: $this->queueDriver,
            minimal: $this->minimal,
            fork: $this->fork,
            displayName: $this->displayName,
            tld: $this->tld,
            organization: $this->organization,
        );
    }

    /**
     * Get the GitHub owner (organization or personal username).
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
        return [
            'HOME' => $this->getHomeDir(),
            'PATH' => $this->getCleanPath(),
        ];
    }

    /**
     * Get the PATH string for clean environment commands.
     */
    public function getCleanPath(): string
    {
        $home = $this->getHomeDir();

        return "{$home}/.bun/bin:{$home}/.local/bin:/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin";
    }

    /**
     * Wrap a command with env -i to prevent inherited environment variables.
     *
     * This is necessary because phpdotenv does NOT override existing
     * environment variables. When running artisan commands from within
     * Horizon, the parent process's env vars would otherwise take precedence.
     */
    public function wrapWithCleanEnv(string $command): string
    {
        $home = $this->getHomeDir();
        $path = $this->getCleanPath();

        return "env -i HOME={$home} PATH={$path} CI=1 {$command}";
    }
}
