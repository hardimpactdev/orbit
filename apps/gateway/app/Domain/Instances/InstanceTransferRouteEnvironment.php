<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Operations\DirectOperationPrivateBindings;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Routes\NativeRouteTransferPlan;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Existing application URL values, scoped to the authoritative admitted participant. */
final readonly class InstanceTransferRouteEnvironment implements OperationSecretSource
{
    public function name(): string
    {
        return 'instance.transfer_route_environment';
    }

    public function targetTypes(): array
    {
        return ['instance'];
    }

    public function ownerId(Operation $operation): ?int
    {
        return in_array($operation->kind, ['instance.transfer', 'instance.transfer_rollback'], true)
            && $operation->target_type === 'instance' && ($operation->input['instance_id'] ?? null) === $operation->target_id
            ? $operation->target_id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        if ($this->ownerId($operation) !== $id || $key !== '' || ! in_array($parameter, ['source_route_environment', 'destination_route_environment'], true)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The URL reference is not owned by this Instance transfer.', 403);
        }
        $owner = app(DirectOperationPrivateBindings::class)->ownerNode($operation, $parameter);
        $expected = $parameter === 'source_route_environment' ? 'source_node_id' : 'destination_node_id';
        if ($owner !== (ValidatedData::object($operation->input['direct_transfer'])[$expected] ?? null)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The URL material has another admitted owner.', 403);
        }

        return new OperationSecretValue(app(NativeRouteTransferPlan::class)->environmentMaterial($operation, $owner));
    }
}
