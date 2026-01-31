<?php

declare(strict_types=1);

namespace App\Actions\Install\Shared;

use App\Data\Install\InstallContext;
use App\Services\DockerManager;
use App\Services\Install\InstallLogger;
use HardImpact\Orbit\Core\Data\StepResult;

final readonly class StartServices
{
    public function __construct(
        private DockerManager $dockerManager,
    ) {}

    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        $this->dockerManager->startAll();

        $logger->success('Docker services started');

        return StepResult::success();
    }
}
