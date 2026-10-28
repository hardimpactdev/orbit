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
    public function name(): string { return 'route.php_pools'; }

    public function ownerId(Operation $operation): ?int
    {
        return in_array($operation->kind, ['route.withdraw_instance', 'route.cleanup_instance', 'route.offline_residue_clean'], true) ? $operation->id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        $plan = $operation->kind === 'route.offline_residue_clean' ? 'offline_residue_plan' : 'instance_runtime_plan';
        $configurations = $operation->state[$plan]['php']['configurations'] ?? null;
        if ($this->ownerId($operation) !== $id || $parameter !== 'configurations' || ! is_array($configurations)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The PHP pool render does not belong to this Operation.', 422);
        }

        return new OperationSecretValue((object) $configurations);
    }
}
