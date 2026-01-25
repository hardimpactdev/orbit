<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services\Deletion\Actions;

use HardImpact\Orbit\Core\Contracts\ProvisionLoggerContract;
use HardImpact\Orbit\Core\Data\DeletionContext;
use HardImpact\Orbit\Core\Data\StepResult;
use Illuminate\Support\Facades\Process;

/**
 * Action to delete project files from the filesystem.
 *
 * Attempts normal deletion first, then falls back to sudo
 * if permission errors occur (e.g., files created by PHP container).
 */
final readonly class DeleteProjectFiles
{
    public function handle(DeletionContext $context, ProvisionLoggerContract $logger): StepResult
    {
        $projectPath = $context->projectPath;

        if (! $projectPath) {
            $logger->warn('No project path specified - skipping file deletion');

            return StepResult::success(['skipped' => true]);
        }

        if (! is_dir($projectPath)) {
            $logger->info("Project directory does not exist: {$projectPath}");

            return StepResult::success(['skipped' => true, 'reason' => 'directory_not_exists']);
        }

        $logger->info("Deleting project directory: {$projectPath}");

        // Attempt normal deletion first
        $result = Process::run('rm -rf '.escapeshellarg($projectPath));

        if ($result->successful() && ! is_dir($projectPath)) {
            $logger->info('Project directory deleted successfully');

            return StepResult::success(['path' => $projectPath]);
        }

        // If still exists, try with sudo (for files created by PHP container with different ownership)
        if (is_dir($projectPath)) {
            $logger->info('Attempting deletion with elevated privileges...');
            $result = Process::run('sudo rm -rf '.escapeshellarg($projectPath));

            if ($result->successful() && ! is_dir($projectPath)) {
                $logger->info('Project directory deleted successfully (with sudo)');

                return StepResult::success(['path' => $projectPath, 'used_sudo' => true]);
            }
        }

        // Check one more time if directory was deleted
        if (! is_dir($projectPath)) {
            $logger->info('Project directory deleted successfully');

            return StepResult::success(['path' => $projectPath]);
        }

        return StepResult::failed("Failed to delete project directory: {$projectPath}");
    }
}
