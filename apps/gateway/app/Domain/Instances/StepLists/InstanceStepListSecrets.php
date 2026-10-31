<?php

declare(strict_types=1);

namespace App\Domain\Instances\StepLists;

use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Projects\GatewaySetupSteps;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\ProjectLifecycleStepStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

final readonly class InstanceStepListSecrets implements OperationSecretSource
{
    public function __construct(private InstanceStepListExecution $execution, private ProjectLifecycleStepStore $steps) {}

    public function name(): string
    {
        return 'instance.step_list';
    }

    /** @return list<string> */
    public function targetTypes(): array
    {
        return ['instance'];
    }

    public function ownerId(Operation $operation): ?int
    {
        return $operation->kind === 'instance.run_steps' && $operation->target_type === 'instance' ? $operation->target_id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        $instance = $this->execution->instance($operation);
        if ($id !== $instance->id || $parameter !== 'program') {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Project step program is not admitted.', 422);
        }
        $phase = LifecyclePhase::from(ValidatedData::string($operation->input['list']));
        foreach ($this->steps->ordered($instance->project, $phase) as $step) {
            if ($key === $phase->value.'.'.$step->name) {
                if (GatewaySetupSteps::owns($step->command)) {
                    if ($phase !== LifecyclePhase::Setup) {
                        throw new ResourceOperationException('instance.teardown_step_failed', 'A setup-only operation cannot run during teardown.', 422);
                    }

                    return new OperationSecretValue(GatewaySetupSteps::program($step->command));
                }

                return new OperationSecretValue(['command' => $step->command], [$step->command]);
            }
        }
        throw new ResourceOperationException('operation.execution_forbidden', 'The Project step program is not admitted.', 422);
    }
}
