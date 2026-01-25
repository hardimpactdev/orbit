<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Process;

/**
 * Service for GitHub operations.
 *
 * Consolidates GitHub identity resolution, URL parsing, and propagation timing.
 */
final class GitHubService
{
    private ?string $cachedUsername = null;

    public function __construct(
        private readonly ConfigManager $config,
    ) {}

    /**
     * Get the authenticated GitHub username.
     *
     * Caches result for the lifetime of the service instance.
     * Also persists to config for future sessions.
     */
    public function getUsername(): ?string
    {
        if ($this->cachedUsername !== null) {
            return $this->cachedUsername;
        }

        // Check config first
        $username = $this->config->get('github_username');
        if ($username) {
            $this->cachedUsername = $username;

            return $username;
        }

        // Query GitHub API
        $result = Process::timeout(10)->run('gh api user --jq .login 2>/dev/null');
        if ($result->successful() && trim($result->output())) {
            $username = trim($result->output());
            $this->cachedUsername = $username;
            $this->config->set('github_username', $username);

            return $username;
        }

        return null;
    }

    /**
     * Parse various GitHub URL formats to owner/repo format.
     *
     * Handles:
     * - git@github.com:owner/repo.git
     * - https://github.com/owner/repo.git
     * - https://github.com/owner/repo
     * - owner/repo
     */
    public function parseRepoIdentifier(string $input): string
    {
        // Handle git@github.com:owner/repo.git format
        if (preg_match('#github\.com[:/]([^/]+/[^/\s]+?)(?:\.git)?$#', $input, $matches)) {
            return $matches[1];
        }

        // Handle https://github.com/owner/repo format
        if (preg_match('#github\.com/([^/]+/[^/\s]+?)(?:\.git)?$#', $input, $matches)) {
            return $matches[1];
        }

        // Assume owner/repo format - validate it looks reasonable
        if (preg_match('#^[\w.-]+/[\w.-]+$#', $input)) {
            return str_replace('.git', '', $input);
        }

        // Strip .git suffix as fallback
        return str_replace('.git', '', $input);
    }

    /**
     * Extract just the repository name from a full owner/repo identifier.
     */
    public function extractRepoName(string $identifier): string
    {
        return basename($this->parseRepoIdentifier($identifier));
    }

    /**
     * Check if a GitHub repository exists.
     */
    public function repoExists(string $repo): bool
    {
        $result = Process::timeout(10)->run("gh repo view {$repo} 2>/dev/null");

        return $result->successful();
    }
}
