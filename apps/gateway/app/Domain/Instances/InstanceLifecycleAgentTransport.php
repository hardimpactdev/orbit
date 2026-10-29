<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Operations\OperationEffectTransport;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Operations\OperationTransportInput;
use App\Domain\Operations\OperationTransports;
use App\Domain\Operations\SwampOperationExecutor;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\OperationAgentTransport;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Holds the existing source/environment locks across fresh validation and one admitted agent invocation. */
final readonly class InstanceLifecycleAgentTransport implements OperationEffectTransport, OperationTransportInput
{
    public function __construct(
        private AppDevSourceOperationLock $sources,
        private InstanceEnvironmentOperationLock $environment,
    ) {}

    public function schema(Operation $operation, array $run): array|bool
    {
        return app(OperationTransports::class)->get('agent')->schema($operation, $run);
    }

    public function wireInput(Operation $operation, array $run, #[\SensitiveParameter] array $input): mixed
    {
        return app(OperationAgentTransport::class)->wireInput($operation, $run, $input);
    }

    public function effectful(Operation $operation, array $run): bool
    {
        return app(OperationAgentTransport::class)->effectful($operation, $run);
    }

    public function receipt(Operation $operation, array $run, #[\SensitiveParameter] array $input): array
    {
        return app(OperationAgentTransport::class)->receipt($operation, $run, $input);
    }

    public function run(Operation $operation, array $run, #[\SensitiveParameter] array $input): array
    {
        $node = $operation->node_id;
        if ($node === null) {
            throw new ResourceOperationException('operation.execution_forbidden', 'A lifecycle Node effect requires its recorded Node.', 422);
        }
        $ids = $operation->kind === 'instance.destroy'
            ? array_column(InstanceLifecycleInput::members(app(RemovalLifecycleExecution::class)->intent($operation)), 'instance_id')
            : [$operation->input['instance_id']];

        return $this->environment->run(ValidatedData::integerList($ids), fn (): array => $this->sources->synchronized($node, function () use ($operation, $run, $input): array {
            $peerNodeId = $operation->state['executor_lease_node_id'] ?? null;
            if (! is_int($peerNodeId)) {
                throw new ResourceOperationException('operation.lease_lost', 'The lifecycle invocation requires its authenticated executor lease.', 409);
            }
            app(SwampOperationExecutor::class)->heartbeat($operation, $run['worker'], $peerNodeId);
            $contract = app(OperationExecutionContract::class);
            if (! $contract->cancellationAllowsRun($operation)) {
                return ['error_code' => 'operation.cancelled'];
            }
            $contract->execution($operation)?->beforeRun($operation, $run);

            return app(OperationTransports::class)->get('agent')->run($operation, $run, $input);
        }));
    }
}
