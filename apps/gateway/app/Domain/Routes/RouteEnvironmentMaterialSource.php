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
        return ['node', 'cluster'];
    }

    public function ownerId(Operation $operation): ?int
    {
        if (isset($operation->input['route_mutations']) && in_array($operation->target_type, ['node', 'cluster'], true)
            && str_starts_with($operation->kind, $operation->target_type.'.')) {
            return $operation->target_id;
        }

        return null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        $nodeId = app(DirectOperationPrivateBindings::class)->ownerNode($operation, $parameter);
        $fields = $operation->input['environment_fields'] ?? [];
        if (! is_array($fields) || $this->ownerId($operation) !== $id || $key !== '' || ($fields[(string) $nodeId] ?? null) !== $parameter) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Route environment field is not bound to this workload Node.', 403);
        }

        return new OperationSecretValue(app(NativeRouteMutationPlan::class)->environmentMaterial($operation, $nodeId));
    }
}
