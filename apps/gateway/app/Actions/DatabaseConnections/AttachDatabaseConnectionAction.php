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
    ): DatabaseConnectionAttachmentData {
        $normalizedPrefix = DatabaseConnectionPrefix::normalize($prefix);

        return $this->operations->run([$instance->id], function () use (
            $instance,
            $connection,
            $normalizedPrefix,
            $forClone,
            $network,
            $replace,
        ): DatabaseConnectionAttachmentData {
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

                DatabaseConnectionTarget::query()->updateOrCreate(
                    [
                        'instance_id' => $instance->id,
                        'prefix' => $normalizedPrefix,
                    ],
                    ['database_connection_id' => $connection->id, 'network' => $network],
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
                    changed: $forgotten->changed || $written->changed,
                    keyCount: $written->keyCount,
                );
            });
        });
    }
}
