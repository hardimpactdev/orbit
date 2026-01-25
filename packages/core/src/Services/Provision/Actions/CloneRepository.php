<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services\Provision\Actions;

use HardImpact\Orbit\Core\Contracts\ProvisionLoggerContract;
use HardImpact\Orbit\Core\Data\ProvisionContext;
use HardImpact\Orbit\Core\Data\StepResult;
use Illuminate\Support\Facades\Process;

final readonly class CloneRepository
{
    public function handle(ProvisionContext $context, ProvisionLoggerContract $logger): StepResult
    {
        if (! $context->cloneUrl) {
            return StepResult::failed('No clone URL provided');
        }

        $logger->info("Cloning repository to {$context->projectPath}");

        // Remove empty placeholder directory if exists
        if (is_dir($context->projectPath)) {
            $files = array_diff(scandir($context->projectPath), ['.', '..']);
            if (empty($files)) {
                rmdir($context->projectPath);
                $logger->log('Removed empty placeholder directory');
            } else {
                return StepResult::failed("Project directory is not empty: {$context->projectPath}");
            }
        }

        $escapedPath = escapeshellarg($context->projectPath);
        $escapedRepo = escapeshellarg($context->cloneUrl);
        $result = Process::timeout(300)->run("gh repo clone {$escapedRepo} {$escapedPath}");

        $output = trim($result->output());
        $errorOutput = trim($result->errorOutput());

        $logger->log("gh repo clone exit code: {$result->exitCode()}");
        if ($output) {
            $logger->log("gh repo clone stdout: {$output}");
        }
        if ($errorOutput) {
            $logger->log("gh repo clone stderr: {$errorOutput}");
        }

        if (! $result->successful()) {
            return StepResult::failed("Failed to clone repository: {$errorOutput}");
        }

        $logger->info('Repository cloned successfully');

        return StepResult::success();
    }
}
