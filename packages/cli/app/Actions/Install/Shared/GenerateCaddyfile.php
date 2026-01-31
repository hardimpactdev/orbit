<?php

declare(strict_types=1);

namespace App\Actions\Install\Shared;

use App\Contracts\CaddyfileGeneratorInterface;
use App\Data\Install\InstallContext;
use HardImpact\Orbit\Core\Data\StepResult;
use App\Services\ConfigManager;
use App\Services\Install\InstallLogger;

final readonly class GenerateCaddyfile
{
    public function __construct(
        private CaddyfileGeneratorInterface $caddyfileGenerator,
        private ConfigManager $configManager,
    ) {}

    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        // Save TLD to config first
        $this->configManager->set('tld', $context->tld);

        // Generate the Caddyfile
        $this->caddyfileGenerator->generate();

        $logger->success('Caddyfile generated');

        return StepResult::success();
    }
}
