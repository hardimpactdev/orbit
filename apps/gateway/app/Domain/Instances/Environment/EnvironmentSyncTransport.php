<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Domain\Instances\Deployment\InstanceDeploymentGuards;
use App\Domain\Operations\OperationEffectTransport;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Operations\OperationTransportInput;
use App\Domain\Operations\SwampOperationExecutor;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\OperationAgentTransport;
use App\Models\Instance;
use App\Models\Operation;

/** Serializes one bounded write with retained environment callers and fresh parent guards. */
final readonly class EnvironmentSyncTransport implements OperationEffectTransport, OperationTransportInput
{
    public function __construct(private InstanceEnvironmentOperationLock $locks, private EnvironmentSyncMaterial $material,
        private InstanceDeploymentGuards $guards, private OperationAgentTransport $agent) {}

    public function schema(Operation $operation, array $run): array|bool
    {
        return $this->agent->schema($operation, $run);
    }

    public function wireInput(Operation $operation, array $run, #[\SensitiveParameter] array $input): mixed
    {
        return $this->agent->wireInput($operation, $run, $input);
    }

    public function effectful(Operation $operation, array $run): bool
    {
        return $this->agent->effectful($operation, $run);
    }

    public function receipt(Operation $operation, array $run, #[\SensitiveParameter] array $input): array
    {
        return $this->agent->receipt($operation, $run, $input);
    }

    public function run(Operation $operation, array $run, #[\SensitiveParameter] array $input): array
    {
        return $this->locks->run([$operation->target_id], function () use ($operation, $run, $input): array {
            $peer = $operation->state['executor_lease_node_id'] ?? null;
            if (! is_int($peer)) {
                throw new ResourceOperationException('operation.lease_lost', 'The environment write requires its authenticated executor lease.', 409);
            }
            app(SwampOperationExecutor::class)->heartbeat($operation, $run['worker'], $peer);
            if (! app(OperationExecutionContract::class)->cancellationAllowsRun($operation)) {
                return ['error_code' => 'operation.cancelled'];
            }
            $this->material->context($operation);
            $parent = $this->guards->deploymentParent($operation);
            if (in_array($parent->kind, ['instance.deploy', 'instance.rollback'], true)) {
                $this->guards->assert($parent, Instance::query()->findOrFail($operation->target_id));
            }

            return $this->agent->run($operation, $run, $input);
        });
    }
}
