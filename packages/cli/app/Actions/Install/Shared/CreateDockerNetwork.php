<?php

declare(strict_types=1);

namespace App\Actions\Install\Shared;

use App\Data\Install\InstallContext;
use App\Data\Provision\StepResult;
use App\Services\DockerManager;
use App\Services\Install\InstallLogger;

final readonly class CreateDockerNetwork
{
    public function __construct(
        private DockerManager $dockerManager,
    ) {}

    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        if (! $this->dockerManager->createNetwork()) {
            $error = $this->dockerManager->getLastError();
            if ($error && ! str_contains($error, 'already exists')) {
                return StepResult::failed("Failed to create Docker network: {$error}");
            }
        }

        $logger->success('Docker network ready');

        return StepResult::success();
    }
}
