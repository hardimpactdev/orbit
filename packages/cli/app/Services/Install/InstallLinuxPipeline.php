<?php

declare(strict_types=1);

namespace App\Services\Install;

use App\Actions\Install\Linux;
use App\Actions\Install\Shared;
use App\Data\Install\InstallContext;
use HardImpact\Orbit\Core\Data\StepResult;

final readonly class InstallLinuxPipeline
{
    /**
     * @return array<array{action: class-string, name: string}>
     */
    public function steps(): array
    {
        return [
            // Phase 1: System Dependencies (Linux-specific)
            // Note: PHP is installed by bootstrap installer (install.sh)
            ['action' => Linux\CheckPrerequisites::class, 'name' => 'Checking prerequisites'],
            ['action' => Linux\InstallDocker::class, 'name' => 'Installing Docker'],
            ['action' => Linux\ConfigurePhpFpm::class, 'name' => 'Configuring PHP-FPM'],
            ['action' => Linux\InstallCaddy::class, 'name' => 'Installing Caddy'],
            ['action' => Linux\InstallSupportTools::class, 'name' => 'Installing support tools'],

            // Phase 2: Configuration (Shared)
            ['action' => Shared\CreateDirectories::class, 'name' => 'Creating directories'],
            ['action' => Shared\CopyConfigurationFiles::class, 'name' => 'Copying configuration'],
            ['action' => Shared\InstallWebApp::class, 'name' => 'Installing web dashboard'],
            ['action' => Shared\GenerateCaddyfile::class, 'name' => 'Generating Caddyfile'],
            ['action' => Shared\GenerateDnsConfig::class, 'name' => 'Generating DNS config'],
            ['action' => Shared\InitializeServices::class, 'name' => 'Initializing services'],

            // Phase 3: Docker Setup (Shared)
            ['action' => Shared\CreateDockerNetwork::class, 'name' => 'Creating Docker network'],
            ['action' => Shared\BuildDockerImages::class, 'name' => 'Building Docker images'],
            ['action' => Shared\PullServiceImages::class, 'name' => 'Pulling service images'],

            // Phase 4: System Integration (Mixed)
            ['action' => Shared\ConfigureHostsFile::class, 'name' => 'Configuring /etc/hosts'],
            ['action' => Linux\ConfigureDns::class, 'name' => 'Configuring DNS'],

            // Phase 5: Start & Finalize (Mixed)
            ['action' => Shared\StartServices::class, 'name' => 'Starting services'],
            ['action' => Shared\InstallHorizon::class, 'name' => 'Installing Horizon service'],
            ['action' => Shared\InstallComposerLink::class, 'name' => 'Installing composer-link'],
            ['action' => Linux\TrustRootCa::class, 'name' => 'Trusting SSL certificate'],

            // Phase 6: Health Check
            ['action' => Shared\HealthCheck::class, 'name' => 'Running health checks'],
        ];
    }

    public function run(InstallContext $context, InstallLogger $logger): StepResult
    {
        $steps = $this->steps();
        $total = count($steps);

        foreach ($steps as $index => $step) {
            $logger->progress($index + 1, $total, $step['name']);

            $result = app($step['action'])->handle($context, $logger);

            if ($result->isFailed()) {
                return $result;
            }
        }

        return StepResult::success();
    }
}
