<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services\Provision\Actions;

use HardImpact\Orbit\Core\Contracts\ProvisionLoggerContract;
use HardImpact\Orbit\Core\Data\ProvisionContext;
use HardImpact\Orbit\Core\Data\StepResult;

final readonly class DetectNodePackageManager
{
    public function handle(ProvisionContext $context, ProvisionLoggerContract $logger): StepResult
    {
        $projectPath = $context->projectPath;
        $packageJsonPath = "{$projectPath}/package.json";

        if (! file_exists($packageJsonPath)) {
            $logger->info('No package.json found, skipping Node package manager detection');

            return StepResult::success(['packageManager' => null]);
        }

        // Check for conflicting lock files
        $lockFiles = [];
        if (file_exists("{$projectPath}/bun.lock") || file_exists("{$projectPath}/bun.lockb")) {
            $lockFiles[] = 'bun.lock';
        }
        if (file_exists("{$projectPath}/package-lock.json")) {
            $lockFiles[] = 'package-lock.json';
        }

        if (count($lockFiles) > 1) {
            return StepResult::failed('Multiple lock files detected: '.implode(', ', $lockFiles).'. Please remove one.');
        }

        // Detect package manager from lock file
        $packageManager = match (true) {
            file_exists("{$projectPath}/bun.lock") || file_exists("{$projectPath}/bun.lockb") => 'bun',
            file_exists("{$projectPath}/package-lock.json") => 'npm',
            default => 'npm',
        };

        $logger->info("Detected Node package manager: {$packageManager}");

        return StepResult::success(['packageManager' => $packageManager]);
    }
}
