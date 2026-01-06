<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\ProvisioningService;
use Illuminate\Console\Command;

class ProvisionServer extends Command
{
    protected $signature = 'server:provision {server} {ssh_public_key}';

    protected $description = 'Provision a server with the Launchpad stack';

    public function handle(ProvisioningService $provisioning): int
    {
        $server = Server::findOrFail($this->argument('server'));
        $sshPublicKey = $this->argument('ssh_public_key');

        $this->info("Starting provisioning for {$server->name} ({$server->host})...");

        $success = $provisioning->provision($server, $sshPublicKey);

        if ($success) {
            $this->info('Provisioning completed successfully!');

            return Command::SUCCESS;
        }
        $this->error('Provisioning failed: '.$server->fresh()->provisioning_error);

        return Command::FAILURE;
    }
}
