<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Actions\DatabaseConnections\PrepareDatabaseChildAction;
use App\Domain\Instances\DatabaseClone\DatabaseCloneStep;
use App\Domain\Operations\OperationFamilyChildren;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * Verifies lifecycle ownership before the database family's normal protected admission.
 *
 * @phpstan-import-type ChildIntent from OperationFamilyChildren
 */
final readonly class InstanceDatabaseIntent
{
    public function isComplete(Instance $instance): bool
    {
        $target = DatabaseConnectionTarget::query()->with('databaseConnection')
            ->where('instance_id', $instance->id)->where('prefix', 'DB')->first();

        return $target !== null && $target->databaseConnection->owner_instance_id === $instance->id
            && in_array($target->databaseConnection->clone_step, [null, DatabaseCloneStep::Complete], true);
    }

    /** @return array<string, mixed> */
    public function record(Operation $operation): array
    {
        $instance = app(InstanceLifecycleState::class)->instance($operation);
        if ($operation->kind !== 'instance.create' || $instance->status !== InstanceState::Active) {
            throw new ResourceOperationException('operation.execution_forbidden', 'Database preparation requires the admitted active Instance.', 422);
        }
        $serverId = $operation->input['database_server_id'];
        if ($serverId !== null && ! DatabaseServer::query()->whereKey($serverId)->exists()) {
            throw new ResourceOperationException('instance.database_create_failed', 'The admitted Database server is unavailable.', 409);
        }
        $sourceId = $operation->input['database_clone_source_id'];
        if ($sourceId !== null) {
            $connection = DatabaseConnection::query()->find(ValidatedData::integer($operation->input['database_clone_connection_id']));
            if ($connection === null || $connection->driver->value !== $operation->input['database_clone_driver']
                || $connection->database_server_id !== $operation->input['database_clone_server_id']
                || ! DatabaseConnectionTarget::query()->where('instance_id', $sourceId)->where('prefix', 'DB')
                    ->where('database_connection_id', $connection->id)->exists()) {
                throw new ResourceOperationException('instance.database_clone_failed', 'The admitted default database changed before preparation.', 409);
            }
        }
        if ($serverId === null && $sourceId === null) {
            throw new ResourceOperationException('operation.execution_forbidden', 'This create has no database intent.', 422);
        }

        DB::transaction(function () use ($operation, $instance, $serverId, $sourceId): void {
            if (is_array($operation->state['lifecycle_database'] ?? null)) {
                return;
            }
            $admission = app(PrepareDatabaseChildAction::class);
            $child = $sourceId === null
                ? $admission->create($instance, DatabaseServer::query()->findOrFail(ValidatedData::integer($serverId)), $operation)
                : $admission->clone($instance, Instance::query()->findOrFail(ValidatedData::integer($sourceId)), $operation);
            app(OperationStore::class)->change($operation, ['state' => [...($operation->state ?? []),
                'lifecycle_database' => [...$child, 'key' => 'database']]]);
        });

        return ['database_required' => true];
    }

    /** @return ChildIntent */
    public function child(Operation $operation, string $key): array
    {
        $owner = $operation->target_id;
        $kind = ($operation->input['database_clone_source_id'] ?? null) !== null ? 'database.clone' : 'database.create';
        if ($key !== 'database' || $operation->kind !== 'instance.create') {
            throw new ResourceOperationException('operation.child_unproven', 'Database preparation requires its create parent.', 409);
        }
        $child = $operation->state['lifecycle_database'] ?? null;
        if (! is_array($child) || ($child['key'] ?? null) !== $key
            || ($child['kind'] ?? null) !== $kind || ! is_int($child['node_id'] ?? null) || ! is_array($child['input'] ?? null)
            || ($child['input']['owner_instance_id'] ?? null) !== $owner
            || ! is_int($child['input']['intent_id'] ?? null) || $child['input']['intent_id'] < 1) {
            throw new ResourceOperationException('operation.child_unproven', 'The owning database admission has not recorded this child.', 409);
        }

        return ['key' => $key, 'kind' => ValidatedData::string($child['kind']), 'node_id' => $child['node_id'],
            'input' => ValidatedData::object($child['input'])];
    }
}
