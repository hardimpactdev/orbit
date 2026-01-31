<?php

declare(strict_types=1);

namespace App\Actions\Install\Shared;

use App\Data\Install\InstallContext;
use App\Services\DockerManager;
use App\Services\Install\InstallLogger;
use App\Services\PlatformService;
use HardImpact\Orbit\Core\Data\StepResult;

final readonly class BuildDockerImages
{
    public function __construct(
        private DockerManager $dockerManager,
        private PlatformService $platformService,
    ) {}

    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        // Build DNS container
        $logger->step('Building DNS container...');
        if (! $this->dockerManager->build('dns')) {
            $error = $this->dockerManager->getLastError();

            return StepResult::failed("Failed to build DNS image: {$error}");
        }

        // On Mac, PHP runs via Homebrew PHP-FPM, not Docker
        // Only build PHP Docker images on Linux
        if (! $this->platformService->isMacOS()) {
            $logger->step('Building PHP images (this may take a while)...');
            if (! $this->dockerManager->build('php')) {
                $error = $this->dockerManager->getLastError();

                return StepResult::failed("Failed to build PHP images: {$error}");
            }
        }

        $logger->success('Docker images built');

        return StepResult::success();
    }
}
