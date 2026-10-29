<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationFamilyChildren;
use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;

final readonly class InstanceProjectionExecution implements OperationCompletion, OperationFamilyChildren, OperationFamilyExecution
{
    public function __construct(private InstanceDeploymentIntent $intents, private OperationChildren $children) {}

    private function instance(Operation $operation): Instance
    {
        $instance = Instance::query()->findOrFail($operation->target_id);
        $this->intents->assertDevelopment($instance);
        if ($operation->target_type !== 'instance' || $operation->node_id !== $instance->node_id
            || ($operation->input['instance_id'] ?? null) !== $instance->id
            || ($operation->input['route_id'] ?? null) !== $instance->authoritativeRoute()?->id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The pending projection changed after admission.', 422);
        }

        return $instance;
    }

    public function childContext(Operation $parent): array
    {
        $this->instance($parent);

        return ['intent' => [], 'children' => [['key' => 'projection', 'kind' => 'route.converge', 'node_id' => $parent->node_id,
            'input' => ['route_id' => $parent->input['route_id'], 'instance_id' => $parent->target_id]]]];
    }

    public function context(Operation $operation): array
    {
        $this->instance($operation);

        return ['descriptors' => [
            ['key' => 'child.projection', 'step' => 'projection', 'transport' => 'gateway', 'name' => 'instance.child-receipt',
                'input' => ['child_key' => 'projection'], 'resumable' => true, 'required' => true],
            ['key' => 'verification', 'step' => 'verification', 'transport' => 'gateway', 'name' => 'instance.projection-receipt',
                'input' => ['instance_id' => $operation->target_id], 'resumable' => true, 'required' => true],
        ]];
    }

    public function input(Operation $operation, array $run): array
    {
        foreach ($this->context($operation)['descriptors'] as $descriptor) {
            if ($descriptor['key'] === $run['key'] && $descriptor['name'] === $run['name'] && $descriptor['transport'] === $run['transport']) {
                return $descriptor['input'];
            }
        }
        throw new ResourceOperationException('operation.execution_forbidden', 'The projection invocation is not admitted.', 422);
    }

    public function beforeRun(Operation $operation, array $run): void
    {
        $this->instance($operation);
        if ($run['key'] === 'verification') {
            $this->children->requireSucceeded($operation, 'projection');
        }
    }

    public function received(Operation $operation, array $run, array $receipt): array
    {
        if ($run['key'] === 'verification' && ($receipt['confirmed'] ?? false) === true) {
            $this->children->requireSucceeded($operation, 'projection');
            $this->instance($operation)->update(['development_projection_pending' => false]);
        }

        return $receipt;
    }

    /** @return array{instance_id:int,confirmed:true} */
    public function result(Operation $operation): array
    {
        $this->instance($operation);
        $this->children->requireSucceeded($operation, 'projection');

        return ['instance_id' => $operation->target_id, 'confirmed' => true];
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void
    {
        if ($outcome->status === OperationStatus::Succeeded && $outcome->result !== $this->result($operation)) {
            throw new ResourceOperationException('operation.completion_unproven', 'Projection repair needs its bound Route child.', 409);
        }
    }

    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        $this->assertCompletion($operation, $outcome);
    }

    public function cancellationBoundary(Operation $operation): ?array
    {
        return null;
    }
}
