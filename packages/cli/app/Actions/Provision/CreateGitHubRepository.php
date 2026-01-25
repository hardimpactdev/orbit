<?php

declare(strict_types=1);

namespace App\Actions\Provision;

use App\Data\Provision\ProvisionContext;
use App\Data\Provision\StepResult;
use App\Services\ProvisionLogger;
use Illuminate\Support\Facades\Process;

final readonly class CreateGitHubRepository
{
    public function handle(ProvisionContext $context, ProvisionLogger $logger, string $targetRepo): StepResult
    {
        $template = $context->template;
        $visibility = $context->visibility;

        if (! $template) {
            return StepResult::failed('No template specified for GitHub repository creation');
        }

        $logger->info("Creating GitHub repository: {$targetRepo} from template {$template}");

        // Check if repo already exists
        $checkResult = Process::run("gh repo view {$targetRepo} 2>/dev/null");
        if ($checkResult->successful()) {
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
        $logger->log('Waiting 3 seconds for GitHub propagation...');
        sleep(3);

        return StepResult::success([
            'repo' => $targetRepo,
            'cloneUrl' => $targetRepo,
        ]);
    }
}
