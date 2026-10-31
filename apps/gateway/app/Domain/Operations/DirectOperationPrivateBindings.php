<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

/** Immutable admitted Node custody and field transport; never accepts request selectors. */
final readonly class DirectOperationPrivateBindings
{
    public function __construct(private DirectOperation $direct) {}

    /** @param array<string,mixed> $input
     * @param  list<string>  $declaredFields
     */
    public function assertAdmission(array $input, array $declaredFields, int $rootNodeId): void
    {
        $participants = $input['participant_node_ids'] ?? [$rootNodeId];
        if (! $this->nodeIds($participants) || ! in_array($rootNodeId, $participants, true)) {
            $this->invalid();
        }
        $bindings = $input['private_bindings'] ?? [];
        if (! is_array($bindings) || array_diff(array_keys($bindings), $declaredFields) !== []) {
            $this->invalid();
        }
        foreach ($bindings as $binding) {
            if (! is_array($binding) || count($binding) !== 3 || array_diff(['owner_node_id', 'read_node_ids', 'write_node_ids'], array_keys($binding)) !== []
                || ! is_int($binding['owner_node_id']) || ! in_array($binding['owner_node_id'], $participants, true)
                || ! $this->nodeIds($binding['read_node_ids']) || ! $this->nodeIds($binding['write_node_ids'])
                || array_diff($binding['read_node_ids'], $participants) !== [] || array_diff($binding['write_node_ids'], $participants) !== []) {
                $this->invalid();
            }
        }
    }

    public function assertParticipant(Operation $operation, int $callerNodeId): void
    {
        $this->validate($operation);
        if (! in_array($callerNodeId, $operation->input['participant_node_ids'] ?? [$operation->node_id], true)) {
            $this->forbidden();
        }
    }

    public function ownerNode(Operation $operation, string $field): int
    {
        $fields = $this->validate($operation);
        if (! in_array($field, $fields, true)) {
            $this->invalid();
        }

        return $operation->input['private_bindings'][$field]['owner_node_id'] ?? $operation->node_id;
    }

    /** @param array<string,string> $declarations
     * @return array<string,string>
     */
    public function readable(Operation $operation, array $declarations, int $callerNodeId): array
    {
        $this->assertParticipant($operation, $callerNodeId);
        $readable = array_filter($declarations, fn (string $field): bool => $this->allows($operation, $field, 'read_node_ids', $callerNodeId), ARRAY_FILTER_USE_KEY);
        if ($readable === []) {
            $this->forbidden();
        }

        return $readable;
    }

    /** @param array<string,string> $declarations
     * @param  list<string>  $fields
     */
    public function assertWritable(Operation $operation, array $declarations, array $fields, int $callerNodeId): void
    {
        $this->assertParticipant($operation, $callerNodeId);
        foreach ($fields as $field) {
            if (isset($declarations[$field]) && ! $this->allows($operation, $field, 'write_node_ids', $callerNodeId)) {
                $this->forbidden();
            }
        }
    }

    private function allows(Operation $operation, string $field, string $access, int $callerNodeId): bool
    {
        return in_array($callerNodeId, $operation->input['private_bindings'][$field][$access] ?? [$operation->node_id], true);
    }

    /** @return list<string> */
    private function validate(Operation $operation): array
    {
        $definition = $this->direct->definition($operation->kind);
        $fields = array_unique([...array_keys($definition['private_material'] ?? []), ...array_keys($definition['secret_outputs'] ?? [])]);
        $this->assertAdmission($operation->input, array_values($fields), $operation->node_id);

        return array_values($fields);
    }

    private function nodeIds(mixed $ids): bool
    {
        return is_array($ids) && array_is_list($ids) && ! array_any($ids, static fn (mixed $id): bool => ! is_int($id) || $id < 1);
    }

    private function invalid(): never
    {
        throw new ResourceOperationException('operation.private_binding_invalid', 'Private bindings must name declared fields and admitted Nodes.', 422);
    }

    private function forbidden(): never
    {
        throw new ResourceOperationException('operation.executor_forbidden', 'This Node is not admitted for the requested Operation material.', 403);
    }
}
