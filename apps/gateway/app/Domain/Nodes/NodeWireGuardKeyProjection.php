<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Data\Nodes\NodeData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** The supplied public key becomes authoritative only after the native hub action. */
final readonly class NodeWireGuardKeyProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return ['intent' => ['hub' => $operation->input['hub_intent'],
            'prepared_peer' => ['node_id' => $operation->target_id, 'public_key' => $operation->input['public_key']]],
            'execution_secrets' => []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $terminal = $operation->status->terminal();
            $result = array_intersect_key(ValidatedData::object($data['result'] ?? []), array_flip(['projected', 'changed', 'error_code']));
            $operation = app(DirectOperation::class)->project($operation, $data, $result);
            if (! $terminal && $operation->status->value === 'succeeded') {
                $node = Node::query()->lockForUpdate()->findOrFail($operation->target_id);
                app(VpnConfigurationRepository::class)->assignPeerKey($node, ValidatedData::string($operation->input['public_key']));
                app(RecordEventBroadcaster::class)->broadcast(RecordEventType::NodeUpdated, $node->id, NodeData::fromModel($node->load('roles'))->toArray());
            }

            return $operation;
        });
    }
}
