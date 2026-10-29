<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Operations\OperationAttemptLease;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentSchemas;
use App\Infrastructure\Nodes\NodeAgentHashSource;
use App\Infrastructure\Nodes\NodeLocks;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Explicit operator repair; it never turns an uncertain Swamp effect into a successful receipt. */
final readonly class RekeyNodeAgentAction
{
    public function __construct(
        private OperationAttemptLease $attempts,
        private OperationChildren $children,
        private OperationExecutionContract $contract,
        private OperationStore $operations,
        private NodeAgentHashSource $hashes,
        private ManagedNodeEligibility $eligibility,
        private AgentCommandRunner $agents,
        private NodeLocks $locks,
    ) {}

    public function execute(int $nodeId, int $operationId): Node
    {
        $operation = Operation::query()->find($operationId) ?? throw $this->refused();
        $leaseId = $this->children->executionLeaseId($operation);
        $result = null;
        $held = $this->attempts->hold($leaseId, function () use ($nodeId, $operation, &$result): void {
            $node = $this->owner($nodeId, $operation->refresh());
            $lock = $this->locks->lock('node-agent:id:'.$nodeId, 420);
            if (! $lock->get()) {
                throw new ResourceOperationException('agent.converge_busy', 'Another Node agent operation is running.', 409);
            }
            try {
                if (isset($operation->state['agent_secret_rekey'])) {
                    $result = $node;

                    return;
                }
                $run = ValidatedData::object(ValidatedData::object($operation->state['executor_runs'] ?? [])['footprint-agent']);
                $data = ['worker' => $operation->worker ?? '', 'key' => 'footprint-agent',
                    'transport' => 'agent', 'name' => 'agent.configure', 'input' => ValidatedData::object($run['input'])];
                $this->contract->authorizeRun($operation, $data);
                $input = [...$data['input'], 'secret_hash' => null];
                AgentSchemas::assertInput('agent.configure', $input);
                // This is a new, operator-requested repair with no target receipt or original effect key.
                $configured = $this->agents->run($node, 'agent.configure', $input);
                if (($configured['restarted'] ?? null) !== true) {
                    throw $this->refused();
                }
                if (! $lock->refresh()) {
                    throw new ResourceOperationException('agent.converge_lock_lost', 'The Node agent recovery lock expired.', 409);
                }
                DB::transaction(function () use ($nodeId, $operation, $configured): void {
                    $fresh = Operation::query()->lockForUpdate()->findOrFail($operation->id);
                    $this->owner($nodeId, $fresh);
                    $this->hashes->store($fresh, $nodeId, $configured['secret_hash'] ?? null);
                    $this->operations->change($fresh, ['state' => [...($fresh->state ?? []),
                        'agent_secret_rekey' => ['node_id' => $nodeId, 'recovered_at' => now()->toISOString()]]]);
                });
                $result = $node->refresh();
            } finally {
                $lock->release();
            }
        });
        if (! $held || $result === null) {
            throw new ResourceOperationException('operation.execution_busy', 'A Node effect is still running.', 409);
        }

        return $result;
    }

    private function owner(int $nodeId, Operation $operation): Node
    {
        $node = Node::query()->find($nodeId);
        $run = ValidatedData::object($operation->state['executor_runs'] ?? [])['footprint-agent'] ?? null;
        $holder = Operation::query()->where('lock_key', 'node:'.$nodeId)->first();
        if ($node === null || $node->platform !== 'linux' || $node->status === LifecycleStatus::Removing || ! $this->eligibility->isManagedForObservation($node) || $operation->kind !== 'node.converge'
            || $operation->status !== OperationStatus::Running || $operation->node_id !== $nodeId
            || $operation->target_type !== 'node' || $operation->target_id !== $nodeId
            || $this->children->nodeId($operation) !== $nodeId
            || $holder === null || $holder->status->terminal() || $holder->id !== $this->children->executionLeaseId($operation)
            || ! is_array($run) || ($run['transport'] ?? null) !== 'agent' || ($run['name'] ?? null) !== 'agent.configure'
            || ($run['started'] ?? false) !== true || ! array_key_exists('result', $run) || $run['result'] !== null) {
            throw $this->refused();
        }

        return $node;
    }

    private function refused(): ResourceOperationException
    {
        return new ResourceOperationException('node.agent_rekey_unavailable', 'Recovery requires the owning Node and its open, uncertain configure Operation.', 409);
    }
}
