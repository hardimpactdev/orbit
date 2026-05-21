<?php

declare(strict_types=1);

namespace App\Console\Commands\Internal;

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\NodeRegistryWriter;
use App\Services\Security\SshHostKeyPinner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('orbit:internal:bake-app-node
    {name : App node name}
    {--role=app : Node role}
    {--environment= : App-node environment: development or production}
    {--host= : App-node host address}
    {--wireguard-address= : App-node WireGuard address}
    {--gateway-endpoint= : Gateway endpoint address}
    {--user=orbit : Runtime user}
    {--tld= : Development app-node TLD}')]
#[Description('Bake an app-node registry row for prepared E2E topology images')]
class BakeAppNodeCommand extends Command
{
    protected $hidden = true;

    public function handle(NodeRegistryWriter $registryWriter): int
    {
        $name = $this->stringArgument('name');
        $role = $this->stringOption('role') ?? 'app';
        $environment = $this->stringOption('environment');
        $host = $this->stringOption('host');
        $wireguardAddress = $this->stringOption('wireguard-address');
        $gatewayEndpoint = $this->stringOption('gateway-endpoint');
        $user = $this->stringOption('user') ?? 'orbit';
        $tld = $this->stringOption('tld');

        if ($name === null || $environment === null || $host === null || $wireguardAddress === null) {
            throw new RuntimeException('Name, environment, host, and wireguard-address are required.');
        }

        if ($role !== 'app') {
            throw new RuntimeException('Only app nodes can be baked with this command.');
        }

        $hostKey = app(SshHostKeyPinner::class)->pin($host);

        $node = $registryWriter->writeAppNode(
            name: $name,
            environment: $environment,
            tld: $tld,
            host: $host,
            wireguardAddress: $wireguardAddress,
            gatewayEndpoint: $gatewayEndpoint,
            sshUser: $user,
            user: $user,
            status: Node::STATUS_ACTIVE,
            hostKey: $hostKey,
        );

        $this->upsertRoleAssignment($node->id, $environment, $tld);

        return self::SUCCESS;
    }

    private function upsertRoleAssignment(int $nodeId, string $environment, ?string $tld): void
    {
        $role = match ($environment) {
            'development' => NodeRoleName::AppDevelopment->value,
            'production' => NodeRoleName::AppProduction->value,
            default => throw new RuntimeException("Invalid app node environment [{$environment}]."),
        };

        NodeRoleAssignment::query()->updateOrCreate(
            [
                'node_id' => $nodeId,
                'role' => $role,
            ],
            [
                'status' => NodeRoleStatus::Active->value,
                'settings' => $tld !== null ? ['tld' => $tld] : [],
                'last_error' => null,
                'converged_at' => now(),
            ],
        );
    }

    private function stringArgument(string $name): ?string
    {
        $value = $this->argument($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
