<?php

declare(strict_types=1);

namespace App\Actions\Provision;

use App\Data\Provision\ProvisionContext;
use App\Data\Provision\StepResult;
use App\Services\ConfigManager;
use App\Services\ProvisionLogger;
use Illuminate\Support\Facades\Process;

final readonly class ForkRepository
{
    public function handle(ProvisionContext $context, ProvisionLogger $logger, ConfigManager $config): StepResult
    {
        if (! $context->cloneUrl) {
            return StepResult::failed('No source URL provided for forking');
        }

        $sourceRepo = $this->extractRepoFromUrl($context->cloneUrl);
        $logger->info("Forking repository: {$sourceRepo}");

        // Fork the repository
        $result = Process::timeout(120)->run("gh repo fork {$sourceRepo} --clone=false");

        if (! $result->successful()) {
            $error = trim($result->errorOutput()) ?: trim($result->output());

            return StepResult::failed("Failed to fork repository: {$error}");
        }

        // Get the fork's full name (user/repo)
        $username = $config->get('github_username');
        if (! $username) {
            $whoami = shell_exec('gh api user --jq .login 2>/dev/null');
            if ($whoami) {
                $username = trim($whoami);
                $config->set('github_username', $username);
            }
        }

        if (! $username) {
            return StepResult::failed('Could not determine GitHub username for fork');
        }

        // Extract just the repo name from source
        $repoName = basename($sourceRepo);
        $forkRepo = "{$username}/{$repoName}";

        $logger->info("Repository forked to: {$forkRepo}");

        // Wait for GitHub to propagate
        $logger->log('Waiting 3 seconds for GitHub propagation...');
        sleep(3);

        return StepResult::success([
            'repo' => $forkRepo,
            'cloneUrl' => $forkRepo,
        ]);
    }

    private function extractRepoFromUrl(string $url): string
    {
        // Handle git@github.com:owner/repo.git format
        if (preg_match("/github\.com[:\\/]([^\\/]+\\/[^\\/\\s]+?)(?:\\.git)?$/", $url, $matches)) {
            return $matches[1];
        }

        // Handle https://github.com/owner/repo format
        if (preg_match('/github\\.com\\/([^\\/]+\\/[^\\/\\s]+?)(?:\\.git)?$/', $url, $matches)) {
            return $matches[1];
        }

        // Assume it's already owner/repo format
        return str_replace('.git', '', $url);
    }
}
