<?php

declare(strict_types=1);

namespace App\Actions\Provision;

use App\Data\Provision\ProvisionContext;
use App\Data\Provision\StepResult;
use App\Services\ConfigManager;
use App\Services\ProvisionLogger;
use Illuminate\Support\Facades\Process;

/**
 * Check if the target GitHub repository is available (does not already exist).
 * This is a safeguard to prevent overwriting existing repositories.
 */
final class CheckRepoAvailable
{
    public function handle(
        ProvisionContext $context,
        ProvisionLogger $logger,
        ConfigManager $config
    ): StepResult {
        // Determine the target repo name
        $targetRepo = $this->determineTargetRepo($context, $config);

        if (! $targetRepo) {
            // No target repo to check (e.g., cloning own repo)
            return StepResult::success();
        }

        $logger->info("Checking if repository {$targetRepo} is available...");

        // Check if repo already exists
        $result = Process::timeout(15)->run(
            "gh api repos/{$targetRepo} --jq .full_name 2>/dev/null"
        );

        if ($result->successful() && trim($result->output())) {
            // Repo exists - fail with clear error
            return StepResult::failed(
                "Repository '{$targetRepo}' already exists on GitHub. Please choose a different project name."
            );
        }

        $logger->info("Repository {$targetRepo} is available");

        return StepResult::success();
    }

    private function determineTargetRepo(ProvisionContext $context, ConfigManager $config): ?string
    {
        // If github-repo is explicitly set, use that
        if ($context->githubRepo) {
            return $context->githubRepo;
        }

        // Get the GitHub owner (organization takes precedence over personal username)
        $owner = $this->getGitHubOwner($context, $config);

        if (! $owner) {
            // Cannot determine target repo without owner
            return null;
        }

        // For templates: will create {owner}/{slug}
        if ($context->template) {
            return "{$owner}/{$context->slug}";
        }

        // For clone-url: check if we need to import as new repo
        if ($context->cloneUrl && ! $context->fork) {
            $sourceRepo = $this->extractRepoFromUrl($context->cloneUrl);
            $sourceOwner = explode('/', $sourceRepo)[0];

            // If cloning from different owner, will create new repo
            if (strtolower($sourceOwner) !== strtolower((string) $owner)) {
                return "{$owner}/{$context->slug}";
            }
        }

        // For fork: will create {owner}/{original-name}
        if ($context->fork && $context->cloneUrl) {
            $sourceRepo = $this->extractRepoFromUrl($context->cloneUrl);
            $originalName = explode('/', $sourceRepo)[1] ?? $context->slug;

            return "{$owner}/{$originalName}";
        }

        return null;
    }

    /**
     * Get the GitHub owner - organization if set, otherwise personal username.
     */
    private function getGitHubOwner(ProvisionContext $context, ConfigManager $config): ?string
    {
        // Organization takes precedence
        if ($context->organization) {
            return $context->organization;
        }

        // Fall back to personal username
        $username = $config->get('github_username');
        if (! $username) {
            $whoami = shell_exec('gh api user --jq .login 2>/dev/null');
            if ($whoami) {
                $username = trim($whoami);
            }
        }

        return $username;
    }

    private function extractRepoFromUrl(?string $url): string
    {
        if (! $url) {
            return '';
        }

        if (preg_match('/github\.com[:\\/]([^\\/]+\\/[^\\/\\s]+?)(?:\\.git)?$/', $url, $matches)) {
            return $matches[1];
        }

        return str_replace('.git', '', $url);
    }
}
