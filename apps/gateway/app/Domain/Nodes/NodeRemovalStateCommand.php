<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Database transitions and evidence only; every Node effect is a separate allowlisted run or child. */
final readonly class NodeRemovalStateCommand implements OperationGatewayCommand
{
    public function __construct(private NodeRemovalPolicy $policy, private OperationChildren $children) {}

    public function schema(): array
    {
        return ['anyOf' => [['type' => 'object', 'additionalProperties' => false], ['type' => 'array', 'maxItems' => 0]]];
    }

    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        $parent = Operation::query()->findOrFail($operation->parent_operation_id);
        $this->children->verify($parent, $operation);
        $intent = ValidatedData::object($operation->state['removal_intent'] ?? []);
        $node = Node::query()->lockForUpdate()->findOrFail($operation->node_id);
        $prior = LifecycleStatus::from(ValidatedData::string($intent['prior_status'] ?? null));
        if ($parent->kind !== 'node.remove' || $parent->node_id !== $node->id || ($intent['id'] ?? null) !== $node->id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The removal transition has no retained Node ownership.', 422);
        }
        if ($operation->kind === 'node.removal_restore') {
            $node->update(['status' => $prior]);

            return ['restoring' => true];
        }
        $caller = Node::query()->findOrFail(ValidatedData::integer($operation->state['removal_caller_id'] ?? null));
        $this->policy->protected($node, $caller);
        $this->policy->resources($node, ($intent['offline'] ?? false) === true);
        if ($node->roles()->exists()) {
            throw new ResourceOperationException('node.has_roles', 'Settle every recorded role removal before withdrawing Node membership.', 409);
        }
        $node->update(['status' => LifecycleStatus::Removing]);

        return ['removing' => true];
    }
}
