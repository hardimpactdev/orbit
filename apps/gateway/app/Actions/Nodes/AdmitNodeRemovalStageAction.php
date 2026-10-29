<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\NodeRemovalPlan;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Same-target stages retain the parent hold. Compensation never executes inside the cancelled parent. */
final readonly class AdmitNodeRemovalStageAction
{
    public function __construct(private StartOperationAction $operations, private OperationIdempotency $idempotency,
        private OperationStore $store, private OperationChildren $children, private NodeRemovalPlan $plan) {}

    /** @param array{key:string,kind:string,node_id:int|null,input:array<string,mixed>} $intent */
    public function execute(Operation $parent, array $intent): Operation
    {
        if (($existing = $this->idempotency->replay($intent['kind'])) !== null) {
            return $existing;
        }
        $restore = $intent['kind'] === 'node.removal_restore';
        if ($parent->kind !== 'node.remove' || $this->idempotency->parent?->id !== $parent->id
            || $intent['node_id'] !== $parent->node_id || $intent['input'] !== []
            || $intent['key'] !== ($restore ? 'remove-restore' : 'remove-begin')) {
            throw new ResourceOperationException('operation.child_forbidden', 'The removal stage differs from its owning Node.', 422);
        }
        if ($restore) {
            $this->children->requireSucceeded($parent, 'remove-begin');
        }

        return DB::transaction(function () use ($parent, $intent, $restore): Operation {
            $node = Node::query()->findOrFail($parent->node_id);
            $operation = $this->operations->execute($intent['kind'], 'node', $node->id, $node->id, [], 'node-removal', $parent->request_id);
            if (! $operation->wasRecentlyCreated) {
                return $operation;
            }
            $steps = $restore ? $this->plan->restore($node) : [['type' => 'run', 'key' => 'remove-state', 'transport' => 'gateway', 'name' => 'node.begin-removal',
                'input' => [], 'result' => ['removing' => true], 'optional' => false]];
            $children = [];
            foreach ($steps as $step) {
                if ($step['type'] === 'child') {
                    $children[] = array_intersect_key($step, array_flip(['key', 'kind', 'node_id', 'input']));
                }
            }
            $this->store->change($operation, ['input' => ['_node_plan' => $steps, '_node_result' => [$restore ? 'restored' : 'removing' => true]],
                'state' => [...($operation->state ?? []), 'child_intents' => $children,
                    'removal_intent' => ValidatedData::object($parent->state['removal_intent'] ?? []), 'removal_caller_id' => $parent->input['caller_node_id']]]);

            return $operation;
        });
    }
}
