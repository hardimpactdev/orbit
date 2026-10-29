<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Actions\Instances\InstantiateProjectRuntimeDefinitionsAction;
use App\Actions\Instances\RestartInstanceProcessesAction;
use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Processes\ProcessSwampSafety;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;

/** Executor Nodes keep native runtime control so recovery never waits on that same executor. */
final readonly class InstanceExecutorRuntimeCommand implements OperationGatewayCommand
{
    public function __construct(private InstanceDeploymentGuards $guards, private InstanceDeploymentBudget $budget,
        private InstantiateProjectRuntimeDefinitionsAction $definitions, private RestartInstanceProcessesAction $processes,
        private ProcessSwampSafety $safety) {}

    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['instance_id', 'action'],
            'properties' => ['instance_id' => ['type' => 'integer', 'minimum' => 1],
                'action' => ['type' => 'string', 'enum' => ['install_definitions', 'restart']]]];
    }

    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        $intent = InstanceDeploymentIntent::admitted($operation);
        if (! $intent['runtime_synchronous'] || $input['instance_id'] !== $operation->target_id
            || ! in_array($operation->kind, ['instance.deploy', 'instance.rollback'], true)
            || ($input['action'] === 'install_definitions' && $intent['variant'] !== 'production')) {
            throw new ResourceOperationException('operation.execution_forbidden', 'This deployment does not own synchronous executor runtime control.', 422);
        }

        return $this->guards->around($operation, function (Instance $instance) use ($operation, $input): array {
            $this->budget->requireRemaining($operation);
            if (! $this->safety->requiresSynchronous($instance->node)) {
                throw new ResourceOperationException('deployment.identity_changed', 'The admitted executor runtime placement changed.', 409);
            }
            $ids = [];
            if ($input['action'] === 'install_definitions') {
                $this->definitions->execute($instance);
            } else {
                $this->processes->execute($instance, DeploymentRequest::withoutOutput(), function (int $id) use (&$ids): void {
                    $ids[] = $id;
                });
            }

            return ['ready' => true, 'process_ids' => $ids];
        });
    }
}
