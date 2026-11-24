<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolves the fleet's primary agent Node: the active Node with an active `agent` role
 * explicitly marked `primary_agent`, or the sole active `agent` Node when none is marked.
 * With zero or multiple unmarked `agent` Nodes, there is no primary agent.
 */
final readonly class PrimaryAgentNode
{
    public function resolveId(): ?int
    {
        $agentNodeIds = [];
        foreach (NodeRole::query()
            ->where('role', RoleName::Agent->value)
            ->where('status', LifecycleStatus::Active->value)
            ->whereHas('node', static fn (Builder $query): Builder => $query->where('status', LifecycleStatus::Active->value))
            ->get(['node_id']) as $assignment) {
            $agentNodeIds[$assignment->node_id] = $assignment->node_id;
        }

        if ($agentNodeIds === []) {
            return null;
        }

        $ids = array_values($agentNodeIds);

        $explicit = Node::query()
            ->whereIn('id', $ids)
            ->where('primary_agent', true)
            ->orderBy('id')
            ->first(['id']);

        if ($explicit instanceof Node) {
            return $explicit->id;
        }

        return count($ids) === 1 ? $ids[0] : null;
    }
}
