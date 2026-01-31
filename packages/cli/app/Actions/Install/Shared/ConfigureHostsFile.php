<?php

declare(strict_types=1);

namespace App\Actions\Install\Shared;

use App\Data\Install\InstallContext;
use App\Services\Install\InstallLogger;
use HardImpact\Orbit\Core\Data\StepResult;
use Illuminate\Support\Facades\Process;

final readonly class ConfigureHostsFile
{
    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        $hostsEntry = '127.0.0.1 orbit-redis';
        $hostsFile = '/etc/hosts';

        // Check if entry already exists
        $result = Process::run("grep -q 'orbit-redis' {$hostsFile}");
        if ($result->successful()) {
            $logger->skip('/etc/hosts already configured');

            return StepResult::success();
        }

        // Add the entry using sudo
        $result = Process::run("echo '{$hostsEntry}' | sudo tee -a {$hostsFile} > /dev/null");

        if (! $result->successful()) {
            $logger->warn('Failed to add orbit-redis to /etc/hosts - you may need to do this manually');

            return StepResult::success(); // Non-critical, continue anyway
        }

        $logger->success('/etc/hosts configured');

        return StepResult::success();
    }
}
