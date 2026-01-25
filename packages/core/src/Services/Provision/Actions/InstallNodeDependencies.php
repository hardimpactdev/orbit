<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services\Provision\Actions;

use HardImpact\Orbit\Core\Contracts\ProvisionLoggerContract;
use HardImpact\Orbit\Core\Data\ProvisionContext;
use HardImpact\Orbit\Core\Data\StepResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;

final readonly class InstallNodeDependencies
{
    public function handle(ProvisionContext $context, ProvisionLoggerContract $logger, ?string $packageManager): StepResult
    {
        if ($packageManager === null) {
            $logger->info('No package manager detected, skipping Node dependencies');

            return StepResult::success();
        }

        $logger->info("Installing Node dependencies with {$packageManager}...");

        return match ($packageManager) {
            'bun' => $this->installWithBun($context, $logger),
            'npm' => $this->installWithNpm($context, $logger),
            default => StepResult::failed("Unsupported package manager: {$packageManager}"),
        };
    }

    private function installWithBun(ProvisionContext $context, ProvisionLoggerContract $logger): StepResult
    {
        $projectPath = $context->projectPath;
        $home = $context->getHomeDir();
        $bunPath = file_exists("{$home}/.bun/bin/bun") ? "{$home}/.bun/bin/bun" : 'bun';

        $hasLockFile = file_exists("{$projectPath}/bun.lock") || file_exists("{$projectPath}/bun.lockb");
        $bunCommand = $hasLockFile ? 'ci' : 'install';

        try {
            $command = $context->wrapWithCleanEnv("{$bunPath} {$bunCommand}");
            $result = Process::path($projectPath)
                ->timeout(120)
                ->run("{$command} 2>&1");

            if (! $result->successful()) {
                return StepResult::failed("Bun {$bunCommand} failed: ".substr($result->output(), 0, 500));
            }

            $logger->info("Bun {$bunCommand} completed");

            return StepResult::success();
        } catch (ProcessTimedOutException) {
            return StepResult::failed('Bun install timed out after 120 seconds');
        }
    }

    private function installWithNpm(ProvisionContext $context, ProvisionLoggerContract $logger): StepResult
    {
        $projectPath = $context->projectPath;

        $hasLockFile = file_exists("{$projectPath}/package-lock.json");
        $npmCommand = $hasLockFile ? 'ci' : 'install --legacy-peer-deps';

        $command = $context->wrapWithCleanEnv("npm {$npmCommand}");
        $result = Process::path($projectPath)
            ->timeout(600)
            ->run("{$command} 2>&1");

        if (! $result->successful()) {
            $logger->warn('npm install had issues: '.substr($result->output(), 0, 500));
        }

        $logger->info('npm install completed');

        return StepResult::success();
    }
}
