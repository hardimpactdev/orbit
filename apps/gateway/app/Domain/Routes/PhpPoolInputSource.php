<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

/** Hydrates the exact stored configuration map, including the empty object that withdraws every stale pool. */
final readonly class PhpPoolInputSource implements OperationSecretSource
{
    public function name(): string
    {
        return 'route.php_pools';
    }

    /** @return list<string> */
    public function targetTypes(): array
    {
        return ['instance_routes', 'route_workload', 'route_effect', 'node_route_residue', 'node'];
    }

    public function ownerId(Operation $operation): ?int
    {
        return in_array($operation->kind, ['route.withdraw_instance', 'route.cleanup_instance', 'route.offline_residue_clean', 'route.workload_prepare', ...RouteNodeEffectExecution::Kinds], true) ? $operation->id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        $plan = match ($operation->kind) {
            'route.offline_residue_clean' => 'offline_residue_plan', 'route.workload_prepare' => 'route_workload_plan',
            'route.shared_php_withdraw', 'route.transfer_php_withdraw' => 'route_effect_plan', default => 'instance_runtime_plan',
        };
        $configurations = data_get($operation->state, $plan.'.php.configurations');
        if ($this->ownerId($operation) !== $id || $parameter !== 'configurations' || ! is_array($configurations)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The PHP pool render does not belong to this Operation.', 422);
        }

        return new OperationSecretValue((object) $configurations);
    }
}
