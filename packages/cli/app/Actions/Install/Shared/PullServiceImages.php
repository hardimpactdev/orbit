<?php

declare(strict_types=1);

namespace App\Actions\Install\Shared;

use App\Data\Install\InstallContext;
use HardImpact\Orbit\Core\Data\StepResult;
use App\Services\DockerManager;
use App\Services\Install\InstallLogger;

final readonly class PullServiceImages
{
    private const SERVICES = ['postgres', 'redis', 'mailpit'];

    public function __construct(
        private DockerManager $dockerManager,
    ) {}

    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        foreach (self::SERVICES as $service) {
            $logger->step("Pulling {$service} image...");

            if (! $this->dockerManager->pull($service)) {
                $error = $this->dockerManager->getLastError();
                $logger->warn("Failed to pull {$service}: {$error}");
                // Non-critical, continue with other services
            }
        }

        $logger->success('Service images pulled');

        return StepResult::success();
    }
}
