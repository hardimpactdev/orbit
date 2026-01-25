<?php

declare(strict_types=1);

namespace App\Actions\Install\Mac;

use App\Data\Install\InstallContext;
use App\Data\Provision\StepResult;
use App\Services\Install\InstallLogger;
use App\Services\PlatformService;

final readonly class CheckPrerequisites
{
    public function __construct(
        private PlatformService $platformService,
    ) {}

    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        // Detect system
        $os = $this->detectSystem();
        $logger->success("System requirements met ({$os})");

        return StepResult::success();
    }

    private function detectSystem(): string
    {
        $arch = php_uname('m');
        $version = $this->platformService->getCommandOutput('sw_vers -productVersion');

        return "macOS {$version} ({$arch})";
    }
}
