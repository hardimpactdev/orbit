<?php

declare(strict_types=1);

namespace App\Actions\Install\Shared;

use App\Data\Install\InstallContext;
use HardImpact\Orbit\Core\Data\StepResult;
use App\Services\DockerManager;
use App\Services\Install\InstallLogger;

final readonly class BuildDockerImages
{
    public function __construct(
        private DockerManager $dockerManager,
    ) {}

    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        // Build DNS container
        $logger->step('Building DNS container...');
        if (! $this->dockerManager->build('dns')) {
            $error = $this->dockerManager->getLastError();

            return StepResult::failed("Failed to build DNS image: {$error}");
        }

        // Build PHP images
        $logger->step('Building PHP images (this may take a while)...');
        if (! $this->dockerManager->build('php')) {
            $error = $this->dockerManager->getLastError();

            return StepResult::failed("Failed to build PHP images: {$error}");
        }

        $logger->success('Docker images built');

        return StepResult::success();
    }
}
