<?php

declare(strict_types=1);

namespace App\Services\Install;

use App\Actions\Install\Mac;
use App\Actions\Install\Shared;
use App\Data\Install\InstallContext;
use App\Data\Provision\StepResult;

final readonly class InstallMacPipeline
{
    /**
     * @return array<array{action: class-string, name: string}>
     */
    public function steps(): array
    {
        return [
            // Phase 1: System Dependencies (Mac-specific)
            // Note: PHP and Homebrew are installed by bootstrap installer (install.sh)
            ['action' => Mac\CheckPrerequisites::class, 'name' => 'Checking prerequisites'],
            ['action' => Mac\InstallOrbStack::class, 'name' => 'Installing OrbStack'],
            ['action' => Mac\ConfigurePhpFpm::class, 'name' => 'Configuring PHP-FPM'],
            ['action' => Mac\InstallCaddy::class, 'name' => 'Installing Caddy'],
            ['action' => Mac\InstallSupportTools::class, 'name' => 'Installing support tools'],

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
            ['action' => Mac\ConfigureDns::class, 'name' => 'Configuring DNS'],

            // Phase 5: Start & Finalize (Mixed)
            ['action' => Shared\StartServices::class, 'name' => 'Starting services'],
            ['action' => Shared\InstallComposerLink::class, 'name' => 'Installing composer-link'],
            ['action' => Mac\TrustRootCa::class, 'name' => 'Trusting SSL certificate'],

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
