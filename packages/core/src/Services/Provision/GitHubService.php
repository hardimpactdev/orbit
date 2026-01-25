<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services\Provision;

use HardImpact\Orbit\Core\Contracts\ProvisionLoggerContract;
use Illuminate\Support\Facades\Process;

/**
 * Service for GitHub operations during provisioning.
 *
 * Uses the gh CLI for all GitHub API operations.
 */
final class GitHubService
{
    private const PROPAGATION_DELAY_SECONDS = 3;

    private ?string $cachedUsername = null;

    /**
     * Get the authenticated GitHub username.
     */
    public function getUsername(): ?string
    {
        if ($this->cachedUsername !== null) {
            return $this->cachedUsername;
        }

        $result = Process::timeout(10)->run('gh api user --jq .login 2>/dev/null');

        if ($result->successful() && trim($result->output())) {
            $this->cachedUsername = trim($result->output());

            return $this->cachedUsername;
        }

        return null;
    }

    /**
     * Wait for GitHub API to propagate changes.
     */
    public function waitForPropagation(ProvisionLoggerContract $logger): void
    {
        $logger->log('Waiting '.self::PROPAGATION_DELAY_SECONDS.' seconds for GitHub propagation...');
        sleep(self::PROPAGATION_DELAY_SECONDS);
    }

    /**
     * Parse various GitHub URL formats to owner/repo format.
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

        // Assume owner/repo format
        if (preg_match('#^[\w.-]+/[\w.-]+$#', $input)) {
            return str_replace('.git', '', $input);
        }

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
