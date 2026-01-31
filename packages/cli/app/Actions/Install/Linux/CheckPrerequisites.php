<?php

declare(strict_types=1);

namespace App\Actions\Install\Linux;

use App\Data\Install\InstallContext;
use HardImpact\Orbit\Core\Data\StepResult;
use App\Services\Install\InstallLogger;
use Illuminate\Support\Facades\Process;

final readonly class CheckPrerequisites
{
    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        // Check apt is available
        if (! $this->commandExists('apt')) {
            return StepResult::failed('apt package manager not found. Ubuntu/Debian required.');
        }

        // Check systemd is available (for DNS configuration and services)
        if (! is_dir('/etc/systemd')) {
            return StepResult::failed('systemd not found. Required for service management.');
        }

        // Detect OS
        $os = $this->detectSystem();
        $logger->success("System requirements met ({$os})");

        return StepResult::success();
    }

    private function commandExists(string $command): bool
    {
        return Process::run("which {$command}")->successful();
    }

    private function detectSystem(): string
    {
        $result = Process::run('cat /etc/os-release | grep PRETTY_NAME | cut -d= -f2 | tr -d "\""');

        return $result->successful() ? trim($result->output()) : 'Linux';
    }
}
