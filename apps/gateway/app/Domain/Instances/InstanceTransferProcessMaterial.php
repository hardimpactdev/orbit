<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Processes\ProcessInstallationBatch;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

/** Reuses the existing Process installation material under fixed transfer fields. */
final readonly class InstanceTransferProcessMaterial implements OperationSecretSource
{
    public function name(): string
    {
        return 'instance.transfer_processes';
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
        if ($this->ownerId($operation) !== $id || $key !== '' || ! in_array($parameter, ['source_installations', 'destination_installations'], true)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The installation reference is not owned by this Instance transfer.', 403);
        }

        return app(ProcessInstallationBatch::class)->material($operation, $parameter);
    }
}
