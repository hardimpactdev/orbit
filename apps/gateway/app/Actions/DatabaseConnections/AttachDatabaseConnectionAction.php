<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Data\DatabaseConnections\DatabaseConnectionAttachmentData;
use App\Domain\DatabaseConnections\DatabaseConnectionEnvProjection;
use App\Domain\DatabaseConnections\DatabaseConnectionPrefix;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Instance;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

final readonly class AttachDatabaseConnectionAction
{
    public function __construct(
        private InstanceEnvironmentOperationLock $operations,
        private InstanceEnvironmentContextResolver $contexts,
        private InstanceEnvironmentStore $store,
        private DatabaseConnectionEnvProjection $projection,
    ) {}

    public function execute(
        Instance $instance,
        DatabaseConnection $connection,
        #[SensitiveParameter]
        ?string $prefix,
        bool $forClone = false,
        string $network = 'wireguard',
        bool $replace = false,
        ?bool $shared = null,
    ): DatabaseConnectionAttachmentData {
        return $this->operations->run([$instance->id], fn (): DatabaseConnectionAttachmentData => $this->project($instance, $connection, $prefix, $forClone, $network, $replace, $shared));
    }

    /**
     * Project stored connection facts after a direct Swamp effect; performs no Node work. `$shared` marks the attachment as one a
     * container clone keeps (docs/reference/database-connections.md#share-a-connection-with-clones): true or false sets the mark,
     * null keeps the recorded mark, and a new attachment without it is not shared.
     */
    public function project(Instance $instance, DatabaseConnection $connection, ?string $prefix, bool $forClone = false, string $network = 'wireguard', bool $replace = false, ?bool $shared = null): DatabaseConnectionAttachmentData
    {
        $normalizedPrefix = DatabaseConnectionPrefix::normalize($prefix);
        $instance->refresh();

        if ($connection->driver === DatabaseDriver::Sqlite && $connection->node_id !== $instance->node_id) {
            throw new ResourceOperationException(
                errorCode: 'database.node_mismatch',
                message: 'A SQLite connection must be on the same Node as the Instance.',
                status: 422,
            );
        }

        $context = $forClone
            ? $this->contexts->resolveForClone($instance, requireActiveNode: false)
            : $this->contexts->resolve($instance, requireActiveNode: false);
        $projected = $this->projection->project($connection, $normalizedPrefix, $network, $instance->node);

        return DB::transaction(function () use (
            $instance,
            $connection,
            $normalizedPrefix,
            $context,
            $projected,
            $network,
            $replace,
            $shared,
        ): DatabaseConnectionAttachmentData {
            $stored = $this->store->values($instance);
            $conflicts = array_intersect($this->projection->driftedKeys($stored, $projected), array_keys($stored));

            if (! $replace && $conflicts !== []) {
                throw new ResourceOperationException(
                    errorCode: 'database.environment_conflict',
                    message: 'The attachment would change stored connection keys. Set replace to true to allow it.',
                    status: 409,
                );
            }

            $recorded = DatabaseConnectionTarget::query()->where('instance_id', $instance->id)->where('prefix', $normalizedPrefix)->first();
            $wasShared = $recorded instanceof DatabaseConnectionTarget && $recorded->shared;
            // The mark the attachment has afterwards: an add without it keeps the recorded one, so every add is checked.
            $this->assertShareable($instance, $connection, $normalizedPrefix, $shared ?? $wasShared);
            $markChanged = $shared !== null && $wasShared !== $shared;
            DatabaseConnectionTarget::query()->updateOrCreate(
                [
                    'instance_id' => $instance->id,
                    'prefix' => $normalizedPrefix,
                ],
                ['database_connection_id' => $connection->id, 'network' => $network, ...($shared === null ? [] : ['shared' => $shared])],
            );

            $forgotten = $this->store->forget($context, $projected['forget'], 'attach');
            $written = $this->store->putMany($context, $projected['values'], 'attach');

            return new DatabaseConnectionAttachmentData(
                instanceId: $instance->id,
                slug: $connection->slug,
                prefix: $normalizedPrefix,
                keys: $projected['keys'],
                host: $projected['host'],
                port: $projected['port'],
                operation: 'attach',
                changed: $forgotten->changed || $written->changed || $markChanged,
                keyCount: $written->keyCount,
            );
        });
    }

    /**
     * A shared attachment is a SQL connection that is never the Instance's own database: not under the DB prefix, not the connection
     * its DB keys come from, and not a database Orbit made for this Instance. Each of these goes to a clone as its own database. A
     * Valkey or SQLite connection is never shared: a clone gets its own Valkey user, and a SQLite file lives in the workspace. An add
     * under DB is refused too while the Instance shares that connection under another prefix.
     */
    private function assertShareable(Instance $instance, DatabaseConnection $connection, string $prefix, bool $shared): void
    {
        if (! $shared) {
            if ($prefix === DatabaseConnectionPrefix::Default && DatabaseConnectionTarget::query()->where('instance_id', $instance->id)
                ->where('database_connection_id', $connection->id)->where('prefix', '!=', $prefix)->where('shared', true)->exists()) {
                $this->refuseOwn();
            }

            return;
        }
        if (! in_array($connection->driver, [DatabaseDriver::Mysql, DatabaseDriver::Pgsql], true)) {
            throw new ResourceOperationException(
                errorCode: 'database.shared_driver_unsupported',
                message: 'Only a mysql or pgsql connection can be shared with clones.',
                status: 422,
            );
        }
        $default = DatabaseConnectionTarget::query()->where('instance_id', $instance->id)
            ->where('prefix', DatabaseConnectionPrefix::Default)->value('database_connection_id');
        if ($prefix === DatabaseConnectionPrefix::Default || $connection->id === $default || $connection->owner_instance_id === $instance->id) {
            $this->refuseOwn();
        }
    }

    private function refuseOwn(): never
    {
        throw new ResourceOperationException(
            errorCode: 'database.shared_own_database',
            message: 'This is the Instance\'s own database. A clone gets its own database; only another connection can be shared.',
            status: 422,
        );
    }
}
