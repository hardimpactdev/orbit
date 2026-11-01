<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\OperationSecretValue;
use App\Infrastructure\Nodes\NodeRoleRuntimeSource;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Admission capture and names-only copies shared by the two owning role parents. */
final readonly class NodeRoleParentInput
{
    /** @return array{snapshot:array<string,mixed>,intent:array<string,mixed>,participant_node_ids:list<int>} */
    public function target(Node $node, NodeRole $assignment, bool $created): array
    {
        app(NodeRoleRuntimeInput::class)->prepare($node, $assignment);
        $snapshot = app(NodeRoleDirectInput::class)->capture($node->refresh(), $assignment, $created);
        $native = app(NativeNodeRoleConvergeInput::class)->capture($node, $assignment, $snapshot);
        $snapshot['certificates'] = $native['intent']['certificates'] ?? $snapshot['certificates'];

        return ['snapshot' => $snapshot, ...$native];
    }

    /** @param array<string,mixed> $snapshot
     * @return array<string,array{vault:string,key:string}>
     */
    public function executionCopies(Operation $operation, Node $node, array $snapshot): array
    {
        $private = ['observer' => new OperationSecretValue($node->agent_secret_hash)];
        if ($snapshot['runtime'] !== null) {
            $material = app(NodeRoleRuntimeSource::class)->resolveRoleMaterial($snapshot, $node->id);
            $private['runtime_material'] = new OperationSecretValue(json_encode($material->value, JSON_THROW_ON_ERROR));
        }
        $copies = [];
        foreach ($private as $name => $value) {
            $copies[$name] = app(SwampCommandStep::class)->executionCopy('orbit-control-inputs', 'node-role-'.$operation->id.'-'.$name, ValidatedData::string($value->value));
        }

        return $copies;
    }
}
