<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services\Provision\Actions;

use HardImpact\Orbit\Core\Contracts\ProvisionLoggerContract;
use HardImpact\Orbit\Core\Data\ProvisionContext;
use HardImpact\Orbit\Core\Data\StepResult;
use Illuminate\Support\Facades\Process;

final readonly class GenerateAppKey
{
    public function handle(ProvisionContext $context, ProvisionLoggerContract $logger): StepResult
    {
        if (! file_exists("{$context->projectPath}/artisan")) {
            $logger->info('Skipping key:generate - no artisan file found');

            return StepResult::success();
        }

        $envPath = "{$context->projectPath}/.env";
        if (! file_exists($envPath)) {
            return StepResult::failed('.env file not found');
        }

        $logger->info('Generating application key...');

        // Use env -i to clear inherited environment variables
        $result = Process::path($context->projectPath)
            ->timeout(30)
            ->run($context->wrapWithCleanEnv('php artisan key:generate --force'));

        $logger->log('key:generate output: '.trim($result->output()));

        if (! $result->successful()) {
            return StepResult::failed('key:generate failed: '.$result->errorOutput());
        }

        // Verify APP_KEY was set
        clearstatcache(true, $envPath);
        $env = file_get_contents($envPath);

        if (preg_match('/^APP_KEY=(.+)$/m', $env, $matches)) {
            return StepResult::success(['app_key' => trim($matches[1])]);
        }

        return StepResult::failed('APP_KEY is empty after key:generate');
    }
}
