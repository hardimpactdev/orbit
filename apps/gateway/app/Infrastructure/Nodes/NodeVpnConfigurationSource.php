<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Nodes\NodeWireGuardInput;
use App\Domain\Operations\DirectOperationPrivateBindings;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Models\Node;
use App\Models\Operation;

/** Existing VPN private type, bound to one admitted hub participant in a native parent run. */
final readonly class NodeVpnConfigurationSource implements OperationSecretSource
{
    public function __construct(private VpnConfigurationRepository $configuration) {}

    public function name(): string
    {
        return 'node.vpn_configuration';
    }

    public function targetTypes(): array
    {
        return ['node', 'task_vm'];
    }

    public function ownerId(Operation $operation): ?int
    {
        $snapshot = NodeWireGuardInput::bound($operation);

        return $snapshot !== null && app(DirectOperationPrivateBindings::class)->ownerNode($operation, 'configuration') === $snapshot['hub_id']
            ? $snapshot['hub_id'] : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        $snapshot = NodeWireGuardInput::bound($operation);
        if ($snapshot === null || $this->ownerId($operation) !== $id || $parameter !== 'configuration'
            || ($operation->input['configuration'] ?? null) !== ['$secret' => $this->name(), 'id' => $id]) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The VPN material requires its exact admitted hub participant.', 422);
        }
        $vpn = $this->configuration->forPeer(Node::query()->findOrFail($snapshot['context_node_id']));
        if ($vpn->server->id !== $id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The VPN owner changed after admission.', 422);
        }
        $peers = Node::query()->whereNotNull('wireguard_public_key')->whereNotNull('wireguard_ip')
            ->where('status', '!=', LifecycleStatus::Removing)->orderBy('id')->get()
            ->map(static fn (Node $node): array => ['node_id' => $node->id, 'name' => $node->name,
                'public_key' => $node->wireguard_public_key, 'address' => $node->wireguard_ip])->all();

        return new OperationSecretValue(['snapshot' => $snapshot, 'address' => $vpn->serverAddress, 'port' => $vpn->port,
            'private_key' => $vpn->serverPrivateKey, 'peers' => $peers], [$vpn->serverPrivateKey]);
    }
}
