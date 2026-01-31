<?php

declare(strict_types=1);

namespace App\Actions\Install\Linux;

use App\Data\Install\InstallContext;
use HardImpact\Orbit\Core\Data\StepResult;
use App\Services\Install\InstallLogger;
use App\Services\PlatformService;
use Illuminate\Support\Facades\Process;

final readonly class InstallDocker
{
    public function __construct(
        private PlatformService $platformService,
    ) {}

    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        if ($context->skipDocker) {
            $logger->skip('Docker installation skipped');

            return StepResult::success();
        }

        // Check if Docker is already installed and running
        if ($this->platformService->hasDocker()) {
            $logger->skip('Docker already installed and running');

            return StepResult::success();
        }

        // Check if Docker is installed but not running
        if ($this->commandExists('docker')) {
            $logger->step('Starting Docker service...');
            Process::run('sudo systemctl start docker');

            if ($this->platformService->hasDocker()) { // @phpstan-ignore if.alwaysFalse
                $logger->success('Docker service started');

                return StepResult::success();
            }

            return StepResult::failed('Docker installed but failed to start');
        }

        // Install Docker
        $logger->step('Installing Docker...');

        $result = Process::timeout(600)->run(
            'curl -fsSL https://get.docker.com | sh && sudo usermod -aG docker $USER'
        );

        if (! $result->successful()) {
            return StepResult::failed('Failed to install Docker: '.$result->errorOutput());
        }

        $logger->success('Docker installed');
        $logger->warn('You may need to log out and back in for Docker group membership to take effect');

        return StepResult::success();
    }

    private function commandExists(string $command): bool
    {
        return Process::run("which {$command}")->successful();
    }
}
