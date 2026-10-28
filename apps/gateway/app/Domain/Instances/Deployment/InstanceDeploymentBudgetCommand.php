<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

/** Records the bounded script timeout before Swamp starts the protected command. */
final readonly class InstanceDeploymentBudgetCommand implements OperationGatewayCommand
{
    public function __construct(private InstanceDeploymentBudget $budget) {}

    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['instance_id', 'step'],
            'properties' => ['instance_id' => ['type' => 'integer', 'minimum' => 1], 'step' => ['type' => 'string']]];
    }

    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        foreach (InstanceDeploymentIntent::admitted($operation)['steps'] as $step) {
            if ($input['step'] !== $step['phase'].'.'.$step['name']) {
                continue;
            }
            $remaining = $this->budget->requireRemaining($operation);

            return ['budget_seconds' => $remaining, 'timeout_seconds' => $step['timeout_seconds'],
                'command_timeout_seconds' => min($step['timeout_seconds'], max(1, $remaining - 5))];
        }
        throw new ResourceOperationException('operation.execution_forbidden', 'The budget step is not in this deployment.', 422);
    }
}
