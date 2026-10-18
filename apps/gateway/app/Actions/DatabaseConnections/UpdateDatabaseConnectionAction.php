<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Data\DatabaseConnections\DatabaseConnectionData;
use App\Data\DatabaseConnections\UpdateDatabaseConnectionData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\DatabaseConnections\DatabaseConnectionProfile;
use App\Domain\DatabaseConnections\DatabaseConnectionUpdateHook;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Support\Facades\DB;

final readonly class UpdateDatabaseConnectionAction
{
    /** @param iterable<DatabaseConnectionUpdateHook> $hooks */
    public function __construct(
        #[Tag(DatabaseConnectionUpdateHook::class)] private iterable $hooks,
        private RecordEventBroadcaster $broadcaster,
    ) {}

    public function execute(DatabaseConnection $connection, UpdateDatabaseConnectionData $data): DatabaseConnection
    {
        $result = DB::transaction(function () use ($connection, $data): DatabaseConnection {
            $locked = DatabaseConnection::query()->lockForUpdate()->findOrFail($connection->id);
            $attributes = DatabaseConnectionProfile::fromUpdate($locked, $data)->attributes();
            unset($attributes['slug']);

            if (! $data->passwordProvided) {
                unset($attributes['password']);
            }

            $proposed = clone $locked;
            $proposed->fill($attributes);

            if ($proposed->driver === DatabaseDriver::Sqlite) {
                foreach ($locked->targets()->with('instance')->get() as $target) {
                    if ($target->instance->node_id !== $proposed->node_id) {
                        throw new ResourceOperationException(
                            errorCode: 'database.node_mismatch',
                            message: 'A SQLite connection must be on the same Node as every attached Instance.',
                            status: 422,
                        );
                    }
                }
            }

            foreach ($this->hooks as $hook) {
                $hook->validate($proposed);
            }
            $locked->update($attributes);

            return $locked->refresh();
        });

        DB::afterCommit(function () use ($result): void {
            $this->broadcaster->broadcast(
                RecordEventType::DatabaseUpdated,
                $result->id,
                DatabaseConnectionData::fromModel($result)->toArray(),
            );

            foreach ($this->hooks as $hook) {
                $hook->afterCommit($result);
            }
        });

        return $result;
    }
}
