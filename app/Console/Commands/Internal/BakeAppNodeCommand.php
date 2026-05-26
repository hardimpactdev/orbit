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
    {--role=app-dev : App node role: app-dev or app-prod}
    {--environment= : Deprecated legacy app-node environment: development or production}
    {--host= : App-node host address}
    {--host-key-host= : Host/IP to scan for the SSH host key when different from --host}
    {--wireguard-address= : App-node WireGuard address}
    {--gateway-endpoint= : Gateway endpoint address}
    {--user=orbit : Runtime user}
    {--tld= : Development app-node TLD}
    {--ingress-node= : Existing ingress node name for production placement}')]
#[Description('Bake an app-node registry row for prepared E2E topology images')]
class BakeAppNodeCommand extends Command
{
    #[\Override]
    protected $hidden = true;

    public function handle(NodeRegistryWriter $registryWriter): int
    {
        $name = $this->stringArgument('name');
        $role = $this->stringOption('role') ?? NodeRoleName::AppDevelopment->value;
        $environment = $this->environmentForRole($role, $this->stringOption('environment'));
        $host = $this->stringOption('host');
        $hostKeyHost = $this->stringOption('host-key-host');
        $wireguardAddress = $this->stringOption('wireguard-address');
        $gatewayEndpoint = $this->stringOption('gateway-endpoint');
        $user = $this->stringOption('user') ?? 'orbit';
        $tld = $this->stringOption('tld');
        $ingressNode = $this->stringOption('ingress-node');

        if ($name === null || $host === null || $wireguardAddress === null) {
            throw new RuntimeException('Name, host, and wireguard-address are required.');
        }

        $hostKey = app(SshHostKeyPinner::class)->pin($hostKeyHost ?? $host);

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

        $this->upsertRoleAssignment($node->id, $environment, $tld, $ingressNode);

        return self::SUCCESS;
    }

    private function upsertRoleAssignment(int $nodeId, string $environment, ?string $tld, ?string $ingressNode): void
    {
        $role = match ($environment) {
            'development' => NodeRoleName::AppDevelopment->value,
            'production' => NodeRoleName::AppProduction->value,
            default => throw new RuntimeException("Invalid app node environment [{$environment}]."),
        };

        $settings = $tld !== null ? ['tld' => $tld] : [];

        if ($ingressNode !== null) {
            if ($role !== NodeRoleName::AppProduction->value) {
                throw new RuntimeException('Only production app nodes can reference ingress.');
            }

            $settings['ingress_node_id'] = Node::query()
                ->where('name', $ingressNode)
                ->whereHas('roleAssignments', fn ($query) => $query
                    ->where('role', NodeRoleName::Ingress->value)
                    ->where('status', NodeRoleStatus::Active->value))
                ->value('id')
                ?? throw new RuntimeException("Active ingress node [{$ingressNode}] was not found.");
        }

        NodeRoleAssignment::query()->updateOrCreate(
            [
                'node_id' => $nodeId,
                'role' => $role,
            ],
            [
                'status' => NodeRoleStatus::Active->value,
                'settings' => $settings,
                'last_error' => null,
                'converged_at' => now(),
            ],
        );
    }

    private function environmentForRole(string $role, ?string $environment): string
    {
        $resolved = match ($role) {
            'app' => $environment,
            'app-dev', NodeRoleName::AppDevelopment->value => 'development',
            'app-prod', NodeRoleName::AppProduction->value => 'production',
            default => throw new RuntimeException('Only app-dev and app-prod nodes can be baked with this command.'),
        };

        if ($resolved === null) {
            throw new RuntimeException('Environment is required for legacy app node baking.');
        }

        if (! in_array($resolved, ['development', 'production'], true)) {
            throw new RuntimeException("Invalid app node environment [{$resolved}].");
        }

        if ($environment !== null && $environment !== $resolved) {
            throw new RuntimeException("Role [{$role}] does not match environment [{$environment}].");
        }

        return $resolved;
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
