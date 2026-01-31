<?php

declare(strict_types=1);

namespace App\Actions\Install\Shared;

use App\Data\Install\InstallContext;
use App\Services\Install\InstallLogger;
use App\Services\ServiceManager;
use HardImpact\Orbit\Core\Data\StepResult;

final readonly class InitializeServices
{
    public function __construct(
        private ServiceManager $serviceManager,
    ) {}

    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        // ServiceManager will auto-create services.yaml from stub if missing
        $this->serviceManager->loadServices();

        // Generate docker-compose.yaml from services configuration
        if (! $this->serviceManager->regenerateCompose()) {
            return StepResult::failed('Failed to generate docker-compose.yaml');
        }

        $logger->success('Service configuration initialized');

        return StepResult::success();
    }
}
