<?php

declare(strict_types=1);

namespace App\Actions\Install\Shared;

use App\Data\Install\InstallContext;
use App\Data\Provision\StepResult;
use App\Services\ConfigManager;
use App\Services\Install\InstallLogger;

final readonly class GenerateDnsConfig
{
    public function __construct(
        private ConfigManager $configManager,
    ) {}

    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        $this->configManager->writeDnsmasqConf();

        $logger->success('DNS config generated');

        return StepResult::success();
    }
}
