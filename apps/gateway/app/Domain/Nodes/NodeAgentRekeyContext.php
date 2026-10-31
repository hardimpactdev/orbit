<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentSchemas;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Read-only authorization of the original recovery owner and its admitted configuration. */
final readonly class NodeAgentRekeyContext
{
    public function __construct(private OperationChildren $children, private ManagedNodeEligibility $eligibility) {}

    public function node(int $nodeId, Operation $operation): Node
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
            throw self::refused();
        }

        return $node;
    }

    /** @return array{configuration:string,certificate:string,unit:string,checkouts:list<string>,binary_changed:bool} */
    public function configuration(Operation $operation): array
    {
        $run = ValidatedData::object(ValidatedData::object($operation->state['executor_runs'] ?? [])['footprint-agent'] ?? []);
        $input = ValidatedData::object($run['input'] ?? []);
        app(OperationExecutionContract::class)->authorizeRun($operation, ['worker' => $operation->worker ?? '', 'key' => 'footprint-agent',
            'transport' => 'agent', 'name' => 'agent.configure', 'input' => $input]);
        $configuration = array_intersect_key($input, array_flip(['configuration', 'certificate', 'unit', 'checkouts', 'binary_changed']));
        AgentSchemas::assertInput('agent.configure', [...$configuration, 'secret_hash' => null, 'worker_credentials' => null]);

        /** @var array{configuration:string,certificate:string,unit:string,checkouts:list<string>,binary_changed:bool} $configuration */
        return $configuration;
    }

    public static function refused(): ResourceOperationException
    {
        return new ResourceOperationException('node.agent_rekey_unavailable', 'Recovery requires the owning Node and its open, uncertain configure Operation.', 409);
    }
}
