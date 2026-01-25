<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services\Provision\Actions;

use HardImpact\Orbit\Core\Contracts\ProvisionLoggerContract;
use HardImpact\Orbit\Core\Data\ProvisionContext;
use HardImpact\Orbit\Core\Data\StepResult;
use Illuminate\Support\Facades\Process;

final readonly class InstallComposerDependencies
{
    public function handle(ProvisionContext $context, ProvisionLoggerContract $logger): StepResult
    {
        $composerPath = "{$context->projectPath}/composer.json";

        if (! file_exists($composerPath)) {
            $logger->info('No composer.json found, skipping Composer install');

            return StepResult::success();
        }

        $logger->info('Installing Composer dependencies...');

        // Use --no-scripts in full setup to prevent scripts from running before .env is configured
        $noScripts = $context->minimal ? '' : ' --no-scripts';
        $command = "composer install --no-interaction{$noScripts}";

        $logger->log("Running: {$command}");

        $result = Process::path($context->projectPath)
            ->env($context->getPhpEnv())
            ->timeout(600)
            ->run($command);

        $output = trim($result->output());
        $errorOutput = trim($result->errorOutput());

        $logger->log("composer install exit code: {$result->exitCode()}");
        if ($errorOutput) {
            $logger->log('composer stderr: '.substr($errorOutput, 0, 1000));
        }

        if (! $result->successful()) {
            $error = $errorOutput ?: $output ?: 'Unknown error';

            return StepResult::failed('Composer install failed: '.substr($error, 0, 500));
        }

        $logger->info('Composer dependencies installed successfully');

        return StepResult::success();
    }
}
