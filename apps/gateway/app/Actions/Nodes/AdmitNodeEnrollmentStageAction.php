<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\NodeEnrollmentEvidence;
use App\Domain\Nodes\NodeRemovalPlan;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Owns only the recorded stage; recovery runs in a separate compensation Operation. */
final readonly class AdmitNodeEnrollmentStageAction
{
    public function __construct(private StartOperationAction $operations, private OperationIdempotency $idempotency,
        private OperationStore $store, private OperationChildren $children, private NodeEnrollmentEvidence $evidence,
        private NodeRemovalPlan $networkRestore) {}

    /** @param array{key:string,kind:string,node_id:int|null,input:array<string,mixed>} $intent */
    public function execute(Operation $parent, array $intent): Operation
    {
        if (($existing = $this->idempotency->replay($intent['kind'])) !== null) {
            return $existing;
        }
        $keys = ['node.enrollment_begin' => 'add-begin', 'node.enrollment_activate' => 'add-activate',
            'node.enrollment_commit' => 'add-commit', 'node.enrollment_restore' => 'add-restore'];
        if ($parent->kind !== 'node.add' || $this->idempotency->parent?->id !== $parent->id || $intent['node_id'] !== $parent->node_id
            || $intent['input'] !== [] || ! isset($keys[$intent['kind']]) || $intent['key'] !== $keys[$intent['kind']]) {
            throw new ResourceOperationException('operation.child_forbidden', 'The enrollment stage differs from its owning Node.', 422);
        }
        $restore = $intent['kind'] === 'node.enrollment_restore';
        if ($restore) {
            $this->children->requireSucceeded($parent, 'add-begin');
        } else {
            $this->evidence->before($parent, $intent['key']);
        }
        $node = Node::query()->findOrFail($parent->node_id);
        $retained = ValidatedData::object($parent->state['enrollment_intent'] ?? []);
        $steps = [];
        $result = [];
        if ($restore) {
            if (! is_array($retained['prior_state'] ?? null)) {
                throw new ResourceOperationException('operation.child_forbidden', 'Enrollment has no prior identity to restore.', 422);
            }
            $network = $parent->children()->where('child_key', 'add-network')->first();
            if ($network !== null) {
                $this->children->verify($parent, $network);
                if ($network->status->value === 'succeeded' && ($network->result['retained'] ?? false) === true) {
                    $steps[] = $this->child('add-network-rollback', 'node.network_rollback', $node->id);
                }
            }
            $steps[] = $this->run('enrollment-state', 'gateway', 'node.restore-enrollment-state', [], ['restoring' => true]);
            $priorNode = clone $node;
            $priorNode->fill(ValidatedData::object($retained['prior_state']));
            foreach ($this->networkRestore->restore($priorNode) as $step) {
                if ($step['type'] === 'child') {
                    $steps[] = $step;
                }
            }
            $result = ['restored' => true];
        } elseif ($intent['kind'] === 'node.enrollment_commit') {
            $network = $this->children->requireSucceeded($parent, 'add-network');
            if (($network->result['retained'] ?? false) === true) {
                $steps[] = $this->run('enrollment-network-commit', 'agent', 'wireguard.commit-peer',
                    ['public_key' => ValidatedData::string($network->result['public_key'] ?? null)], []);
            }
            $result = ['committed' => true];
        } else {
            $activate = $intent['kind'] === 'node.enrollment_activate';
            $result = [$activate ? 'activated' : 'preparing' => true];
            $steps[] = $this->run('enrollment-state', 'gateway', $activate ? 'node.activate-enrollment' : 'node.begin-enrollment', [], $result);
        }
        $childIntents = [];
        foreach ($steps as $step) {
            if ($step['type'] === 'child') {
                $childIntents[] = array_intersect_key($step, array_flip(['key', 'kind', 'node_id', 'input']));
            }
        }
        $operation = $this->operations->execute($intent['kind'], 'node', $node->id, $node->id,
            ['_node_plan' => $steps, '_node_result' => $result, '_node_failure_result' => []], 'node-enrollment', $parent->request_id);
        if ($operation->wasRecentlyCreated) {
            $this->store->change($operation, ['state' => [...($operation->state ?? []), 'enrollment_intent' => $retained, 'child_intents' => $childIntents]]);
        }

        return $operation;
    }

    /** @param array<string,mixed> $input
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function run(string $key, string $transport, string $name, array $input, array $result): array
    {
        return ['type' => 'run', 'key' => $key, 'transport' => $transport, 'name' => $name, 'input' => $input, 'result' => $result, 'optional' => false];
    }

    /** @return array<string,mixed> */
    private function child(string $key, string $kind, int $nodeId): array
    {
        return ['type' => 'child', 'key' => $key, 'kind' => $kind, 'node_id' => $nodeId, 'input' => [], 'optional' => false];
    }
}
