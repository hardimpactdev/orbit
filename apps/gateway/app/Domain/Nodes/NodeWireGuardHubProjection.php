<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Records bounded native hub status only; never renders or publishes configuration. */
final readonly class NodeWireGuardHubProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        $intent = $operation->input['_hub_inventory'] ?? null;
        if (! is_array($intent) || $operation->kind !== 'node.wireguard_hub_converge' || $operation->target_type !== 'node' || $operation->node_id !== $operation->target_id || ($intent['hub_id'] ?? null) !== $operation->target_id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The hub differs from its admitted root.', 422);
        }

        return ['intent' => $intent, 'execution_secrets' => []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return app(DirectOperation::class)->project($operation, $data,
            array_intersect_key(ValidatedData::object($data['result'] ?? []), array_flip(['projected', 'retained_peer_count', 'error_code'])));
    }
}
