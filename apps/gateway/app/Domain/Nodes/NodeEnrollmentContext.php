<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Reads the candidate account through stamped ancestry while the persisted prior identity is retained. */
final readonly class NodeEnrollmentContext
{
    public function __construct(private OperationChildren $children, private OperationIdempotency $idempotency) {}

    public function node(Operation $operation): Node
    {
        $node = Node::query()->findOrFail($operation->node_id);
        $parent = $operation->parent_operation_id === null ? null : Operation::query()->findOrFail($operation->parent_operation_id);
        if ($parent !== null) {
            $this->children->verify($parent, $operation);
        } elseif ($operation->wasRecentlyCreated) {
            $parent = $this->idempotency->parent;
        }

        return $parent === null ? $node : $this->forChild($parent, $node);
    }

    public function forChild(Operation $parent, Node $node): Node
    {
        while (true) {
            if ($parent->kind === 'node.add') {
                if ($parent->target_type !== 'node' || $parent->target_id !== $node->id || $parent->node_id !== $node->id) {
                    return $node;
                }
                $intent = ValidatedData::object($parent->state['enrollment_intent'] ?? []);
                $candidate = ValidatedData::object($intent['candidate'] ?? []);
                $user = ValidatedData::string($candidate['user'] ?? null);
                if (($intent['id'] ?? null) !== $node->id || ! LinuxUserName::isValid($user)
                    || ($parent->input['managed_user'] ?? null) !== $user) {
                    throw new ResourceOperationException('operation.child_unproven', 'The enrollment candidate account has no matching owner.', 409);
                }
                $effective = clone $node;
                $effective->user = $user;

                return $effective;
            }
            if ($parent->parent_operation_id === null) {
                return $node;
            }
            $ancestor = Operation::query()->findOrFail($parent->parent_operation_id);
            $this->children->verify($ancestor, $parent);
            $parent = $ancestor;
        }
    }
}
