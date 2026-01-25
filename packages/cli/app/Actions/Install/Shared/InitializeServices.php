<?php

declare(strict_types=1);

namespace App\Actions\Install\Shared;

use App\Data\Install\InstallContext;
use App\Data\Provision\StepResult;
use App\Services\Install\InstallLogger;
use App\Services\PhpComposeGenerator;
use App\Services\ServiceManager;

final readonly class InitializeServices
{
    public function __construct(
        private ServiceManager $serviceManager,
        private PhpComposeGenerator $phpComposeGenerator,
    ) {}

    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        // ServiceManager will auto-create services.yaml from stub if missing
        $this->serviceManager->loadServices();

        // Generate docker-compose.yaml from services configuration
        if (! $this->serviceManager->regenerateCompose()) {
            return StepResult::failed('Failed to generate docker-compose.yaml');
        }

        // Generate PHP docker-compose.yml for building PHP images
        $this->phpComposeGenerator->generate();

        $logger->success('Service configuration initialized');

        return StepResult::success();
    }
}
