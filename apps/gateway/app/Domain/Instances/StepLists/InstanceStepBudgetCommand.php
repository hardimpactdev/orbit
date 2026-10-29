<?php

declare(strict_types=1);

namespace App\Domain\Instances\StepLists;

use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\ProjectLifecycleStepStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Records a public bounded command budget; it never runs the command. */
final readonly class InstanceStepBudgetCommand implements OperationGatewayCommand
{
    public function __construct(private InstanceStepListExecution $execution, private ProjectLifecycleStepStore $steps, private InstanceStepListBudget $budget) {}

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => ['instance_id' => ['type' => 'integer', 'minimum' => 1], 'step' => ['type' => 'string']],
            'required' => ['instance_id', 'step'], 'additionalProperties' => false];
    }

    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        $instance = $this->execution->instance($operation);
        $phase = LifecyclePhase::from(ValidatedData::string($operation->input['list']));
        foreach ($this->steps->ordered($instance->project, $phase) as $step) {
            if ($step->name !== $input['step']) {
                continue;
            }
            $budget = min($step->timeoutSeconds, $this->budget->remaining($operation));

            return ['budget_seconds' => $budget, 'timeout_seconds' => $step->timeoutSeconds,
                'command_timeout_seconds' => max(1, $budget - 5),
                ...($budget === 0 ? ['error_code' => 'command.deadline_exceeded', 'outcome' => 'deadline'] : [])];
        }
        throw new ResourceOperationException('operation.execution_forbidden', 'The budget step is not in this admitted Project list.', 422);
    }
}
