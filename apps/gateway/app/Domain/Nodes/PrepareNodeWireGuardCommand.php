<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;

/** Captures the protected reference after admission. It performs no secret read or Node effect. */
final readonly class PrepareNodeWireGuardCommand implements OperationGatewayCommand
{
    public function __construct(private OperationStore $operations) {}

    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['context_node_id', 'excluded_node_id'],
            'properties' => ['context_node_id' => ['type' => 'integer', 'minimum' => 1], 'excluded_node_id' => ['type' => ['integer', 'null']]]];
    }

    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        $keyChange = $operation->kind === 'node.wireguard_key';
        if (($operation->state['node_wireguard_prepared'] ?? false) === true) {
            return ['prepared' => true];
        }
        $context = $input['context_node_id'] ?? null;
        $excluded = $input['excluded_node_id'] ?? null;
        if (! is_int($context) || $context < 1 || ($excluded !== null && $excluded !== $context)
            || ($keyChange && ($context !== $operation->target_id || $excluded !== null))
            || ! Node::query()->whereKey($operation->node_id)->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Vpn))->exists()) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The WireGuard projection target differs from admitted intent.', 422);
        }
        if ($keyChange && Node::query()->findOrFail($context)->wireguard_public_key !== ($operation->input['public_key'] ?? null)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The admitted WireGuard key changed before projection.', 422);
        }
        $snapshot = ['configuration' => ['$secret' => 'node.vpn_configuration', 'id' => $operation->node_id], 'excluded_node_id' => $excluded];
        $this->operations->change($operation, ['input' => [...$operation->input, '_wireguard' => $snapshot],
            'state' => [...($operation->state ?? []), 'node_wireguard_prepared' => true]]);

        return ['prepared' => true];
    }
}
