<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Node;
use App\Support\ValidatedData;

/** Pure public captures for a participant selected by the shared authenticated public-view boundary. */
final readonly class NodeRemovalPublicInput
{
    /** @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    public function participant(array $snapshot, Node $participant): array
    {
        $context = Node::query()->findOrFail(ValidatedData::integer($snapshot['node_id']));
        if ($context->name !== $snapshot['name']) {
            throw new ResourceOperationException('node.removal_owner_mismatch', 'The admitted removal no longer owns this Node.', 422);
        }
        $pin = app(AgentDelivery::class)->pin($participant);
        $base = ['schema_version' => 1, 'removal_node_id' => $context->id,
            'node_id' => $participant->id, 'agent_sha256' => $pin];
        if ($participant->is($context) && $snapshot['offline'] === false && $snapshot['machine_deleted'] === false) {
            return $base;
        }
        $admitted = collect(is_array($snapshot['participants']) ? $snapshot['participants'] : [])->first(
            static fn (mixed $row): bool => is_array($row) && ($row['node_id'] ?? null) === $participant->id);
        if (! is_array($admitted)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Node is outside the admitted removal inventory.', 403);
        }

        return [...$base, 'network' => app(NodeNetworkParticipantInput::class)->capture($participant, $context, true),
            'residue' => app(NodeConvergeInput::class)->residue($participant),
            'dns' => $admitted['dns'] ? app(NodeConvergeInput::class)->dns() : null];
    }
}
