<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Models\Node;
use App\Models\NodeRole;

/** Retains the original assignment identity and scoped Settings references, never credential values. */
final readonly class NodeRoleIntent
{
    /** @return array{assignment_id: int, created: bool, role: string, source_node_id: int, target_node_id: ?int, role_configuration: array{cluster_id: ?int, source_settings: array{scope_type: 'node', scope_id: int}, target_settings: array{scope_type: 'node', scope_id: int}|null}} */
    public static function capture(NodeRole $assignment, bool $created, ?Node $target = null): array
    {
        return ['assignment_id' => $assignment->id, 'created' => $created, 'role' => $assignment->role->value,
            'source_node_id' => $assignment->node_id, 'target_node_id' => $target?->id,
            'role_configuration' => ['cluster_id' => $assignment->cluster_id,
                'source_settings' => ['scope_type' => 'node', 'scope_id' => $assignment->node_id],
                'target_settings' => $target === null ? null : ['scope_type' => 'node', 'scope_id' => $target->id]]];
    }
}
