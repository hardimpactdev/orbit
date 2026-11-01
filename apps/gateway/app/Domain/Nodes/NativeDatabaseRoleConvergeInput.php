<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\NodeRole;
use App\Support\ValidatedData;

/** Public admission capture for the first role set executed entirely from 209. */
final readonly class NativeDatabaseRoleConvergeInput
{
    public function __construct(private SwampSshExecutionInput $ssh) {}

    public static function supports(Node $node, RoleName $role): bool
    {
        return $role === RoleName::Database && $node->roles()->where('role', RoleName::Database)->exists() && ! $node->roles()->where('role', '!=', RoleName::Database)->exists();
    }

    /** @param array<string,mixed> $snapshot
     * @return array{intent:array<string,mixed>,participant_node_ids:list<int>}
     */
    public function capture(Node $node, NodeRole $assignment, array $snapshot): array
    {
        if ($snapshot['metrics_fleet'] !== null || ! self::supports($node, $assignment->role) || $assignment->node_id !== $node->id || ! $assignment->exists) {
            throw new ResourceOperationException('operation.kind_unavailable', 'This native role factory requires the actual Database-only assignment.', 422);
        }
        $footprint = ValidatedData::object($snapshot['footprint']);
        $entries = $footprint['artifacts'];
        if (! is_array($entries) || ! array_is_list($entries)) {
            throw new ResourceOperationException('operation.kind_unavailable', 'The footprint requires its complete artifact list.', 422);
        }
        foreach ($entries as $artifact) {
            $artifact = ValidatedData::object($artifact);
            if ($artifact['required'] === true && ! in_array($artifact['name'], ['agent', 'peer-dns'], true)) {
                throw new ResourceOperationException('operation.kind_unavailable', 'An applicable Database footprint has no native action.', 422);
            }
        }
        $ssh = $this->ssh->forNode($node);
        $intent = ['schema_version' => 1, 'node_id' => $node->id, 'assignment_id' => $assignment->id,
            'created' => false, 'role' => RoleName::Database->value, 'user' => $node->user,
            'agent_sha256' => $snapshot['agent_sha256'], 'prerequisites' => $snapshot['prerequisites'],
            'ssh' => $ssh,
            'footprint' => $footprint, 'cli' => $snapshot['cli'], 'metrics_fleet' => null];

        return ['intent' => $intent, 'participant_node_ids' => array_values(array_unique([209, $node->id]))];
    }
}
