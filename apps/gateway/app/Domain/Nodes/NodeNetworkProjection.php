<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationPublicView;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Public key recording precedes the native hub read. No Node effect or receipt validation. */
final readonly class NodeNetworkProjection implements DirectOperationProjection, DirectOperationPublicView
{
    public function assertPublicParticipant(Operation $operation, int $callerNodeId): void
    {
        $this->hubParticipant($operation, $callerNodeId);
    }

    public function publicInput(Operation $operation, int $callerNodeId): array
    {
        return ['hub' => $this->hubParticipant($operation, $callerNodeId)];
    }

    /** @return array<string, mixed> */
    private function hubParticipant(Operation $operation, int $callerNodeId): array
    {
        $snapshot = NodeWireGuardInput::bound($operation);
        if ($operation->kind !== 'node.network_converge' || $snapshot === null
            || $callerNodeId !== $snapshot['executor_node_id']) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Node is outside the admitted network hub participant.', 403);
        }

        return $snapshot;
    }

    public function input(Operation $operation): array
    {
        return ['network' => ValidatedData::object($operation->input['_network'] ?? []),
            'hub' => ValidatedData::object($operation->input['_wireguard'] ?? []),
            'hub_command' => ValidatedData::object($operation->input['_hub_command'] ?? [])];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $raw = ValidatedData::object($data['result'] ?? []);
        $result = array_intersect_key($raw, array_flip(['network_proven', 'public_key', 'retained', 'restore_peer_key', 'error_code']));
        $wasTerminal = $operation->status->terminal();
        $projected = app(DirectOperation::class)->project($operation, $data, $result);
        if (! $wasTerminal && in_array($data['status'] ?? null, ['running', 'succeeded'], true)) {
            $node = Node::query()->findOrFail($operation->target_id);
            if (($result['restore_peer_key'] ?? false) === true) {
                $node->update(['wireguard_public_key' => ValidatedData::object($operation->input['_network'] ?? [])['existing_key'] ?? null]);
            } elseif (is_string($result['public_key'] ?? null)) {
                app(VpnConfigurationRepository::class)->assignPeerKey($node, $result['public_key']);
            }
        }

        return $projected;
    }
}
