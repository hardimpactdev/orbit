<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services\Deletion\Actions;

use HardImpact\Orbit\Core\Contracts\ProvisionLoggerContract;
use HardImpact\Orbit\Core\Data\DeletionContext;
use HardImpact\Orbit\Core\Data\StepResult;
use Illuminate\Support\Facades\Process;

/**
 * Action to regenerate Caddy configuration after project deletion.
 *
 * Uses the orbit CLI to regenerate the Caddyfile and reload Caddy.
 * This removes the deleted project from the web server configuration.
 */
final readonly class RegenerateCaddyConfig
{
    public function handle(DeletionContext $context, ProvisionLoggerContract $logger): StepResult
    {
        $logger->info('Regenerating Caddy configuration...');

        $cliPath = config('orbit.cli_path', '/usr/local/bin/orbit');

        // Use the CLI's caddy:reload command which regenerates the Caddyfile
        // and reloads Caddy in one step
        $result = Process::timeout(30)->run("{$cliPath} caddy:reload --json 2>/dev/null");

        if ($result->successful()) {
            $output = json_decode($result->output(), true);
            if ($output['success'] ?? false) {
                $logger->info('Caddy configuration regenerated and reloaded');

                return StepResult::success();
            }
        }

        // Try without --json flag as fallback
        $result = Process::timeout(30)->run("{$cliPath} caddy:reload 2>&1");

        if ($result->successful()) {
            $logger->info('Caddy configuration regenerated and reloaded');

            return StepResult::success();
        }

        // Non-fatal failure - site is already deleted, Caddy will just have a stale entry
        return StepResult::failed('Could not reload Caddy - you may need to reload manually');
    }
}
