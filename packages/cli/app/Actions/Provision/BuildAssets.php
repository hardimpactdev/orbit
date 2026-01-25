<?php

declare(strict_types=1);

namespace App\Actions\Provision;

use App\Data\Provision\ProvisionContext;
use App\Data\Provision\StepResult;
use App\Services\ProvisionLogger;
use Illuminate\Support\Facades\Process;

final readonly class BuildAssets
{
    public function handle(ProvisionContext $context, ProvisionLogger $logger, string $packageManager): StepResult
    {
        $packageJsonPath = "{$context->projectPath}/package.json";

        if (! file_exists($packageJsonPath)) {
            $logger->info('No package.json found, skipping asset build');

            return StepResult::success();
        }

        $packageJson = json_decode(file_get_contents($packageJsonPath), true);

        if (! isset($packageJson['scripts']['build'])) {
            $logger->info('No build script in package.json, skipping asset build');

            return StepResult::success();
        }

        $logger->info("Building assets with {$packageManager}...");

        $projectPath = $context->projectPath;

        // Use env -i to clear inherited environment (prevents APP_KEY pollution from Horizon)
        $result = match ($packageManager) {
            'bun' => $this->buildWithBun($context),
            'pnpm' => Process::path($projectPath)->timeout(600)->run($context->wrapWithCleanEnv('pnpm run build').' 2>&1'),
            'yarn' => Process::path($projectPath)->timeout(600)->run($context->wrapWithCleanEnv('yarn run build').' 2>&1'),
            default => Process::path($projectPath)->timeout(600)->run($context->wrapWithCleanEnv('npm run build').' 2>&1'),
        };

        $output = trim($result->output());
        $exitCode = $result->exitCode();

        $logger->log("Build exit code: {$exitCode}");
        if ($output) {
            $logger->log('Build output: '.substr($output, -1000));
        }

        if (! $result->successful()) {
            return StepResult::failed('Asset build failed: '.substr($output, 0, 500));
        }

        $logger->info('Assets built successfully');

        return StepResult::success();
    }

    private function buildWithBun(ProvisionContext $context): \Illuminate\Process\ProcessResult
    {
        $home = $context->getHomeDir();
        $bunPath = file_exists("{$home}/.bun/bin/bun") ? "{$home}/.bun/bin/bun" : 'bun';

        // Use env -i to clear inherited environment (prevents APP_KEY pollution from Horizon)
        $command = $context->wrapWithCleanEnv("{$bunPath} run build");

        return Process::path($context->projectPath)
            ->timeout(60)
            ->run("{$command} 2>&1");
    }
}
