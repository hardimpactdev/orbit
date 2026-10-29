<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Hydrates only the VPN hub identity and configuration; peers are read later under the publication lock. */
final readonly class NodeVpnConfigurationSource implements OperationSecretSource
{
    public function __construct(private VpnConfigurationRepository $configuration) {}

    public function name(): string
    {
        return 'node.vpn_configuration';
    }

    public function targetTypes(): array
    {
        return ['node'];
    }

    public function ownerId(Operation $operation): ?int
    {
        $context = $operation->input['context_node_id'] ?? null;
        if (! in_array($operation->kind, ['node.wireguard_key', 'node.wireguard_hub_converge'], true)
            || ! is_int($context) || ($operation->kind === 'node.wireguard_key' && $context !== $operation->target_id)
            || ! Node::query()->whereKey($context)->exists()) {
            return null;
        }

        return Node::query()->whereKey($operation->node_id)->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Vpn))->exists()
            ? $operation->node_id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        if ($this->ownerId($operation) !== $id || $parameter !== 'configuration') {
            throw new ResourceOperationException('operation.execution_forbidden', 'The VPN configuration does not belong to this hub Operation.', 422);
        }
        $context = ValidatedData::integer($operation->input['context_node_id']);
        $vpn = $this->configuration->forPeer(Node::query()->findOrFail($context));
        if ($vpn->server->id !== $id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The VPN hub changed after admission.', 422);
        }

        return new OperationSecretValue(['hub_id' => $id, 'address' => $vpn->serverAddress, 'port' => $vpn->port,
            'private_key' => $vpn->serverPrivateKey], [$vpn->serverPrivateKey]);
    }
}
