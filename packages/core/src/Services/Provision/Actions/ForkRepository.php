<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services\Provision\Actions;

use HardImpact\Orbit\Core\Contracts\ProvisionLoggerContract;
use HardImpact\Orbit\Core\Data\ProvisionContext;
use HardImpact\Orbit\Core\Data\StepResult;
use HardImpact\Orbit\Core\Services\Provision\GitHubService;
use Illuminate\Support\Facades\Process;

final readonly class ForkRepository
{
    public function __construct(
        private GitHubService $github,
    ) {}

    public function handle(ProvisionContext $context, ProvisionLoggerContract $logger): StepResult
    {
        if (! $context->cloneUrl) {
            return StepResult::failed('No source URL provided for forking');
        }

        $sourceRepo = $this->github->parseRepoIdentifier($context->cloneUrl);
        $logger->info("Forking repository: {$sourceRepo}");

        // Fork the repository
        $result = Process::timeout(120)->run("gh repo fork {$sourceRepo} --clone=false");

        if (! $result->successful()) {
            $error = trim($result->errorOutput()) ?: trim($result->output());

            return StepResult::failed("Failed to fork repository: {$error}");
        }

        // Get the fork's full name (user/repo)
        $username = $this->github->getUsername();

        if (! $username) {
            return StepResult::failed('Could not determine GitHub username for fork');
        }

        // Extract just the repo name from source
        $repoName = $this->github->extractRepoName($sourceRepo);
        $forkRepo = "{$username}/{$repoName}";

        $logger->info("Repository forked to: {$forkRepo}");

        // Wait for GitHub to propagate
        $this->github->waitForPropagation($logger);

        return StepResult::success([
            'repo' => $forkRepo,
            'cloneUrl' => $forkRepo,
        ]);
    }
}
