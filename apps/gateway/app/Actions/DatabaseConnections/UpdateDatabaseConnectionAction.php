<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Actions\DatabaseServers\RecordDatabaseOperationAction;
use App\Actions\Operations\StartOperationAction;
use App\Data\DatabaseConnections\DatabaseConnectionData;
use App\Data\DatabaseConnections\UpdateDatabaseConnectionData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\DatabaseConnections\DatabaseConnectionProfile;
use App\Domain\DatabaseConnections\DatabaseConnectionUpdateHook;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\Operation;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Support\Facades\DB;

final readonly class UpdateDatabaseConnectionAction
{
    /** @param iterable<DatabaseConnectionUpdateHook> $hooks */
    public function __construct(
        #[Tag(DatabaseConnectionUpdateHook::class)] private iterable $hooks,
        private RecordDatabaseOperationAction $operations,
        private StartOperationAction $start,
    ) {}

    public function execute(DatabaseConnection $connection, UpdateDatabaseConnectionData $data): Operation
    {
        return DB::transaction(function () use ($connection, $data): Operation {
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
            $server = $locked->server;
            $patch = $this->patch($attributes, $data);
            // Only a new index on a managed Valkey server needs a live check; every other change is record-only (R1).
            if (! ($server instanceof DatabaseServer && $server->driver === DatabaseDriver::Redis && $proposed->isDirty('database') && $proposed->database !== null)) {
                $locked->update($patch);
                DB::afterCommit(static function () use ($locked): void {
                    app(RecordEventBroadcaster::class)->broadcast(RecordEventType::DatabaseUpdated, $locked->id, DatabaseConnectionData::fromModel($locked)->toArray());
                });

                return $this->start->record('database.update', 'database_connection', $locked->id, $server->node_id ?? $this->gatewayId(),
                    [], ['backend' => 'active'], 'operator', request()->attributes->getString('orbit.request_id'));
            }
            app(SwampSshExecutionInput::class)->forNode($server->node);
            $container = app(DockerProcessRenderer::class)->containerName($server->process
                ?? throw new ResourceOperationException('database.server_command_failed', 'The server has no Process.', 502));

            return $this->operations->execute('database.update', 'database_connection', $locked->id, $server->node_id,
                ['server_id' => $server->id, 'container' => $container, 'index' => (int) $proposed->database], [], ['connection_update' => $patch]);
        });
    }

    private function gatewayId(): int
    {
        return app(NodeAccessAuthorizer::class)->activeGatewayId()
            ?? throw new ResourceOperationException('database.node_unavailable', 'This update needs an active Gateway Node to record it.', 422);
    }

    /** @param array<string, int|string|null> $attributes
     * @return array<string, int|string|null>
     */
    private function patch(array $attributes, UpdateDatabaseConnectionData $data): array
    {
        $provided = [
            'driver' => $data->driverProvided, 'node_id' => $data->nodeIdProvided,
            'host' => $data->hostProvided, 'port' => $data->portProvided,
            'database' => $data->databaseProvided, 'path' => $data->pathProvided,
            'username' => $data->usernameProvided, 'password' => $data->passwordProvided,
        ];
        if ($data->driverProvided) {
            foreach (['host', 'port', 'database', 'path'] as $field) {
                $provided[$field] = true;
            }
        }

        return array_intersect_key($attributes, array_filter($provided));
    }
}
