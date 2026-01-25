<?php

declare(strict_types=1);

namespace App\Actions\Provision;

use App\Data\Provision\ProvisionContext;
use App\Data\Provision\StepResult;
use App\Services\ProvisionLogger;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;

final readonly class InstallNodeDependencies
{
    public function handle(ProvisionContext $context, ProvisionLogger $logger): StepResult
    {
        $packageJsonPath = "{$context->projectPath}/package.json";

        if (! file_exists($packageJsonPath)) {
            $logger->info('No package.json found, skipping Node dependencies');

            return StepResult::success(['packageManager' => null]);
        }

        $projectPath = $context->projectPath;

        // Check for conflicting lock files
        $lockFiles = [];
        if (file_exists("{$projectPath}/bun.lock") || file_exists("{$projectPath}/bun.lockb")) {
            $lockFiles[] = 'bun.lock';
        }
        if (file_exists("{$projectPath}/package-lock.json")) {
            $lockFiles[] = 'package-lock.json';
        }
        if (file_exists("{$projectPath}/yarn.lock")) {
            $lockFiles[] = 'yarn.lock';
        }
        if (file_exists("{$projectPath}/pnpm-lock.yaml")) {
            $lockFiles[] = 'pnpm-lock.yaml';
        }

        if (count($lockFiles) > 1) {
            return StepResult::failed('Multiple lock files detected: '.implode(', ', $lockFiles));
        }

        $home = $context->getHomeDir();
        $packageManager = 'npm';

        // Detect package manager from lock file
        if (file_exists("{$projectPath}/bun.lock") || file_exists("{$projectPath}/bun.lockb")) {
            $packageManager = 'bun';
            $result = $this->installWithBun($context, $logger);
        } elseif (file_exists("{$projectPath}/pnpm-lock.yaml")) {
            $packageManager = 'pnpm';
            $result = $this->installWithPnpm($context, $logger);
        } elseif (file_exists("{$projectPath}/yarn.lock")) {
            $packageManager = 'yarn';
            $result = $this->installWithYarn($context, $logger);
        } else {
            $packageManager = 'npm';
            $result = $this->installWithNpm($context, $logger);
        }

        if ($result->isFailed()) {
            return $result;
        }

        return StepResult::success(['packageManager' => $packageManager]);
    }

    private function installWithBun(ProvisionContext $context, ProvisionLogger $logger): StepResult
    {
        $projectPath = $context->projectPath;
        $home = $context->getHomeDir();
        $bunPath = file_exists("{$home}/.bun/bin/bun") ? "{$home}/.bun/bin/bun" : 'bun';

        $logger->info('Installing dependencies with Bun...');

        // Check if lock file exists for bun ci, otherwise use bun install
        $hasLockFile = file_exists("{$projectPath}/bun.lock") || file_exists("{$projectPath}/bun.lockb");
        $bunCommand = $hasLockFile ? 'ci' : 'install';

        try {
            // Use env -i to clear inherited environment (prevents APP_KEY pollution from Horizon)
            // Use 'bun ci' for CI/background environments when lock file exists
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

    private function installWithPnpm(ProvisionContext $context, ProvisionLogger $logger): StepResult
    {
        $logger->info('Installing dependencies with pnpm...');

        // Use env -i to clear inherited environment
        $command = $context->wrapWithCleanEnv('pnpm install');
        $result = Process::path($context->projectPath)
            ->timeout(600)
            ->run($command);

        if (! $result->successful()) {
            return StepResult::failed('pnpm install failed: '.substr($result->errorOutput(), 0, 500));
        }

        $logger->info('pnpm install completed');

        return StepResult::success();
    }

    private function installWithYarn(ProvisionContext $context, ProvisionLogger $logger): StepResult
    {
        $logger->info('Installing dependencies with Yarn...');

        // Use env -i to clear inherited environment
        $command = $context->wrapWithCleanEnv('yarn install');
        $result = Process::path($context->projectPath)
            ->timeout(600)
            ->run($command);

        if (! $result->successful()) {
            return StepResult::failed('Yarn install failed: '.substr($result->errorOutput(), 0, 500));
        }

        $logger->info('Yarn install completed');

        return StepResult::success();
    }

    private function installWithNpm(ProvisionContext $context, ProvisionLogger $logger): StepResult
    {
        $logger->info('Installing dependencies with npm...');

        // Use env -i to clear inherited environment
        $command = $context->wrapWithCleanEnv('npm install --legacy-peer-deps');
        $result = Process::path($context->projectPath)
            ->timeout(600)
            ->run("{$command} 2>&1");

        if (! $result->successful()) {
            $logger->warn('npm install had issues: '.substr($result->output(), 0, 500));
        }

        $logger->info('npm install completed');

        return StepResult::success();
    }
}
