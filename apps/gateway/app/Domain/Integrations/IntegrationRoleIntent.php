<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/** The Node family retains this reference-only snapshot until its role effects are proven. */
final readonly class IntegrationRoleIntent
{
    /** @return array{assignment_id: int, role: string, source_node_id: int, target_node_id: int|null, created: bool} */
    public function get(Operation $operation): array
    {
        $parentId = $operation->getAttribute('parent_operation_id');
        if (! is_int($parentId)) { $this->refuse(); }
        $parent = Operation::query()->findOrFail($parentId);
        $intent = ValidatedData::object($parent->state['role_intent'] ?? []);
        $role = ValidatedData::string($intent['role'] ?? null);
        $source = ValidatedData::integer($intent['source_node_id'] ?? null);
        $target = isset($intent['target_node_id']) ? ValidatedData::integer($intent['target_node_id']) : null;
        $remove = str_ends_with($operation->kind, '.role_remove');
        $expected = $remove ? $source : ($target ?? $source);
        $configuration = ValidatedData::object($intent['role_configuration'] ?? []);
        $scope = ValidatedData::object($configuration[$expected === $source ? 'source_settings' : 'target_settings'] ?? []);
        if ($operation->kind !== $role.($remove ? '.role_remove' : '.role_converge') || $operation->node_id !== $expected
            || ($scope['scope_type'] ?? null) !== 'node' || ($scope['scope_id'] ?? null) !== $expected) { $this->refuse(); }
        return ['assignment_id' => ValidatedData::integer($intent['assignment_id']), 'role' => $role,
            'source_node_id' => $source, 'target_node_id' => $target, 'created' => IntegrationIntent::boolean($intent['created'])];
    }

    private function refuse(): never
    {
        throw new ResourceOperationException('operation.execution_forbidden', 'The integration role has no matching retained Node intent.', 422);
    }
}
