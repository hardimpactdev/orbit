<?php

declare(strict_types=1);

namespace App\Console\Commands;

use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Services\ProvisioningService;
use Illuminate\Console\Command;

class ProvisionEnvironment extends Command
{
    protected $signature = 'environment:provision {environment} {ssh_public_key}';

    protected $description = 'Provision an environment with the Orbit stack';

    public function handle(ProvisioningService $provisioning): int
    {
        $environment = Environment::findOrFail($this->argument('environment'));
        $sshPublicKey = $this->argument('ssh_public_key');

        $this->info("Starting provisioning for {$environment->name} ({$environment->host})...");

        $success = $provisioning->provision($environment, $sshPublicKey);

        if ($success) {
            $this->info('Provisioning completed successfully!');

            return Command::SUCCESS;
        }
        $this->error('Provisioning failed: '.$environment->fresh()->provisioning_error);

        return Command::FAILURE;
    }
}
