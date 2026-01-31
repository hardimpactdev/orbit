<?php

declare(strict_types=1);

namespace App\Actions\Install\Shared;

use App\Data\Install\InstallContext;
use App\Services\HorizonManager;
use App\Services\Install\InstallLogger;
use HardImpact\Orbit\Core\Data\StepResult;

final readonly class InstallHorizon
{
    public function __construct(
        private HorizonManager $horizonManager,
    ) {}

    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        // Check if already installed
        if ($this->horizonManager->isInstalled()) {
            $logger->skip('Horizon service already installed');

            // Ensure it's running
            if (! $this->horizonManager->isRunning()) {
                $this->horizonManager->start();
            }

            return StepResult::success();
        }

        $logger->step('Installing Horizon service...');

        try {
            $result = $this->horizonManager->install();

            if (! $result) {
                return StepResult::failed('Failed to install Horizon service');
            }

            // Start the service
            $started = $this->horizonManager->start();

            if (! $started) {
                $logger->warn('Horizon service installed but failed to start');
            } else {
                $logger->success('Horizon service installed and started');
            }

            return StepResult::success();
        } catch (\RuntimeException $e) {
            return StepResult::failed('Failed to install Horizon: '.$e->getMessage());
        }
    }
}
