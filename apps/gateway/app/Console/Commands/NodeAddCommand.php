<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Nodes\ProvisionNodeAction;
use App\Data\Nodes\ProvisionNodeData;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use Illuminate\Console\Command;

/**
 * Admits `node.add` from the Gateway itself, the way `node:add` does through the API. The nested Gateway of a fleet has no
 * CLI on its machine, so the fleet phase of the converge adds its Nodes this way (swamp/ansible/tasks/task-vm-fleet-build.yml); Swamp then runs the enrollment.
 */
final class NodeAddCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:node-add
        {name : Node name}
        {host : Public SSH host}
        {--user=orbit : Bootstrap SSH user}
        {--role=* : Role to assign}
        {--wireguard-ip= : WireGuard address of the Node}
        {--host-key-fingerprint= : SSH SHA256 fingerprint of the host key}
        {--architecture=x86_64 : Machine architecture}
        {--tld= : Development TLD for app-dev}';

    #[\Override]
    protected $description = 'Admit node.add for a Node from the Gateway itself.';

    public function handle(ProvisionNodeAction $nodes): int
    {
        $roles = [];
        foreach ((array) $this->option('role') as $name) {
            $role = RoleName::tryFrom((string) $name);
            if ($role === null) {
                $this->error("The role [{$name}] is unknown.");

                return self::FAILURE;
            }
            $roles[] = $role;
        }
        $tld = $this->option('tld');

        try {
            $operation = $nodes->start(new ProvisionNodeData(
                name: (string) $this->argument('name'),
                publicSshHost: (string) $this->argument('host'),
                roles: $roles,
                user: (string) $this->option('user'),
                wireguardIp: $this->option('wireguard-ip') ?: null,
                expectedSshHostFingerprint: $this->option('host-key-fingerprint') ?: null,
                architecture: (string) $this->option('architecture'),
                tldProvided: is_string($tld) && $tld !== '',
                tld: is_string($tld) && $tld !== '' ? $tld : null,
            ));
        } catch (ResourceOperationException $exception) {
            $this->error("{$exception->errorCode}: {$exception->getMessage()}");

            return self::FAILURE;
        }
        $this->line("Operation #{$operation->id} {$operation->kind} {$operation->status->value}");

        return self::SUCCESS;
    }
}
