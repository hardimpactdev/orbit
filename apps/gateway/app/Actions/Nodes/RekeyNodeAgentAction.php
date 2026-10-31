<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Domain\Nodes\NodeAgentRekeyContext;
use App\Domain\Operations\OperationAttemptLease;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentSchemas;
use App\Infrastructure\Nodes\NodeAgentHashSource;
use App\Infrastructure\Nodes\NodeLocks;
use App\Infrastructure\Nodes\NodeWorkerRollout;
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
        private NodeAgentRekeyContext $context,
        private AgentCommandRunner $agents,
        private NodeLocks $locks,
    ) {}

    public function execute(int $nodeId, int $operationId): Node|Operation
    {
        $node = Node::query()->find($nodeId);
        if ($node !== null && app(NodeWorkerRollout::class)->allows($node)) {
            return app(StartNodeAgentRekeyAction::class)->execute($nodeId, $operationId);
        }
        $operation = Operation::query()->find($operationId) ?? throw $this->refused();
        $leaseId = $this->children->executionLeaseId($operation);
        $result = null;
        $held = $this->attempts->hold($leaseId, function () use ($nodeId, $operation, &$result): void {
            $node = $this->context->node($nodeId, $operation->refresh());
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
                $input = [...$data['input'], 'secret_hash' => null, 'worker_credentials' => null];
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
                    $this->context->node($nodeId, $fresh);
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

    private function refused(): ResourceOperationException
    {
        return NodeAgentRekeyContext::refused();
    }
}
