<?php

declare(strict_types=1);

namespace App\Actions\Provision;

use App\Data\Provision\ProvisionContext;
use App\Data\Provision\StepResult;
use App\Services\ProvisionLogger;
use Illuminate\Support\Facades\Process;

final readonly class RunMigrations
{
    public function handle(ProvisionContext $context, ProvisionLogger $logger): StepResult
    {
        $artisanPath = "{$context->projectPath}/artisan";

        if (! file_exists($artisanPath)) {
            $logger->info('Skipping migrations - no artisan file found');

            return StepResult::success();
        }

        // Clear config cache to ensure fresh .env values are loaded
        $logger->info('Clearing config cache...');
        $clearResult = Process::path($context->projectPath)
            ->timeout(30)
            ->run($context->wrapWithCleanEnv('php artisan config:clear'));

        if (! $clearResult->successful()) {
            $logger->warn('config:clear failed: '.$clearResult->errorOutput());
        }

        $logger->info('Running database migrations...');

        // Use env -i to prevent inherited environment variables from overriding
        // the project's .env file (phpdotenv doesn't override existing env vars)
        $result = Process::path($context->projectPath)
            ->timeout(120)
            ->run($context->wrapWithCleanEnv('php artisan migrate --force'));

        $output = trim($result->output());
        $errorOutput = trim($result->errorOutput());
        $exitCode = $result->exitCode();

        $logger->log("migrate exit code: {$exitCode}");
        if ($output) {
            $logger->log("migrate stdout: {$output}");
        }
        if ($errorOutput) {
            $logger->log("migrate stderr: {$errorOutput}");
        }

        if (! $result->successful()) {
            $error = $errorOutput ?: $output ?: 'Unknown error';

            return StepResult::failed("migrate failed (exit {$exitCode}): {$error}");
        }

        $logger->info('Migrations completed successfully');

        return StepResult::success();
    }
}
