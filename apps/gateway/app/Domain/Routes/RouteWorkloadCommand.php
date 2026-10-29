<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;

/** Captures input and checks proven results; it performs no Node work. */
final readonly class RouteWorkloadCommand implements OperationGatewayCommand
{
    public function __construct(private RouteWorkloadInput $inputs) {}

    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['action', 'route_id', 'instance_id'],
            'properties' => ['action' => ['enum' => ['prepare', 'prove']], 'route_id' => ['type' => 'integer', 'minimum' => 1], 'instance_id' => ['type' => 'integer', 'minimum' => 1]]];
    }

    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        if ($operation->kind !== 'route.workload_prepare' || $input['route_id'] !== $operation->input['route_id'] || $input['instance_id'] !== $operation->input['instance_id']) {
            throw new ResourceOperationException('operation.execution_forbidden', 'This workload preparation was not admitted.', 422);
        }
        $operation->refresh();
        $instance = Instance::query()->with('node')->findOrFail(ValidatedData::integer($input['instance_id']));
        $route = Route::query()->findOrFail(ValidatedData::integer($input['route_id']));
        $sourceOnly = $operation->parent?->kind === 'route.target_set'
            && data_get($operation->parent->state, 'route_plan.mode') === 'target_binding'
            && str_starts_with($operation->child_key ?? '', 'target-source-access.');
        if ($instance->node_id !== $operation->node_id || (! $sourceOnly && ! $route->sites_published)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'Prepare the stored workload after publishing its certificate-backed site intent.', 422);
        }
        if ($input['action'] === 'prove') {
            RouteExecutionProof::preceding($operation, app(RouteWorkloadExecution::class)->context($operation)['descriptors'], 'workload-proof');

            return ['ready' => true];
        }
        if (! is_array($operation->state['route_workload_plan'] ?? null)) {
            $plan = [...$this->inputs->forInstance($instance, $route, $operation->node_id), 'source_only' => $sourceOnly];
            $operation->update(['state' => [...($operation->state ?? []), 'route_workload_plan' => $plan]]);
        }

        return ['prepared' => true];
    }
}
