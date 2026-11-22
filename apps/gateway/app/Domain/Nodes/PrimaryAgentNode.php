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
        $agentNodeIds = NodeRole::query()
            ->where('role', RoleName::Agent->value)
            ->where('status', LifecycleStatus::Active->value)
            ->whereHas('node', static fn (Builder $query): Builder => $query->where('status', LifecycleStatus::Active->value))
            ->pluck('node_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($agentNodeIds === []) {
            return null;
        }

        $explicit = Node::query()
            ->whereIn('id', $agentNodeIds)
            ->where('primary_agent', true)
            ->orderBy('id')
            ->value('id');

        if (is_int($explicit) || is_numeric($explicit)) {
            return (int) $explicit;
        }

        return count($agentNodeIds) === 1 ? $agentNodeIds[0] : null;
    }
}
