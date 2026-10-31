<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Operations\DirectOperationPrivateBindings;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

/** Existing Instance environment values, rendered only inside the declared private callback. */
final readonly class RouteEnvironmentMaterialSource implements OperationSecretSource
{
    public function name(): string
    {
        return 'route.environment_sync';
    }

    public function targetTypes(): array
    {
        return ['route'];
    }

    public function ownerId(Operation $operation): ?int
    {
        return in_array($operation->kind, ['route.create', 'route.converge', 'route.update', 'route.target_set', 'route.withdraw', 'route.destroy'], true)
            && $operation->target_type === 'route' ? $operation->target_id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        $nodeId = app(DirectOperationPrivateBindings::class)->ownerNode($operation, $parameter);
        if ($this->ownerId($operation) !== $id || ($operation->input['environment_fields'][(string) $nodeId] ?? null) !== $parameter) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Route environment field is not bound to this workload Node.', 403);
        }

        return new OperationSecretValue(app(NativeRouteEnvironmentMaterial::class)->forNode($operation, $nodeId));
    }
}
