<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services\Provision\Actions;

use HardImpact\Orbit\Core\Contracts\ProvisionLoggerContract;
use HardImpact\Orbit\Core\Data\ProvisionContext;
use HardImpact\Orbit\Core\Data\StepResult;
use HardImpact\Orbit\Core\Services\Provision\GitHubService;
use Illuminate\Support\Facades\Process;

final readonly class CreateGitHubRepository
{
    public function __construct(
        private GitHubService $github,
    ) {}

    public function handle(ProvisionContext $context, ProvisionLoggerContract $logger, string $targetRepo): StepResult
    {
        $template = $context->template;
        $visibility = $context->visibility;

        if (! $template) {
            return StepResult::failed('No template specified for GitHub repository creation');
        }

        $logger->info("Creating GitHub repository: {$targetRepo} from template {$template}");

        // Check if repo already exists
        if ($this->github->repoExists($targetRepo)) {
            return StepResult::failed("Repository '{$targetRepo}' already exists. Please choose a different project name.");
        }

        $command = "gh repo create {$targetRepo} --{$visibility} --template ".escapeshellarg($template).' --clone=false';
        $logger->log("Running: {$command}");

        $result = Process::timeout(120)->run($command);

        if (! $result->successful()) {
            $error = trim($result->errorOutput()) ?: trim($result->output());

            return StepResult::failed("Failed to create GitHub repository: {$error}");
        }

        $logger->info('GitHub repository created successfully');

        // Wait for GitHub to propagate
        $this->github->waitForPropagation($logger);

        return StepResult::success([
            'repo' => $targetRepo,
            'cloneUrl' => $targetRepo,
        ]);
    }
}
