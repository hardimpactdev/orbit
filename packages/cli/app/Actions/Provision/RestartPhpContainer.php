<?php

declare(strict_types=1);

namespace App\Actions\Provision;

use App\Data\Provision\StepResult;
use App\Services\ProvisionLogger;
use Illuminate\Support\Facades\Process;

final readonly class RestartPhpContainer
{
    public function handle(string $phpVersion, ProvisionLogger $logger): StepResult
    {
        $container = 'orbit-php-'.str_replace('.', '', $phpVersion);

        $logger->info("Restarting {$container} to clear cached state...");

        $result = Process::timeout(30)->run("docker restart {$container} 2>&1");

        $output = trim($result->output());
        $logger->log("docker restart output: {$output}");

        if (! $result->successful()) {
            $logger->warn("Failed to restart PHP container: {$output}");
            // Don't fail - this is not critical
        } else {
            $logger->info('PHP container restarted successfully');
        }

        return StepResult::success();
    }
}
