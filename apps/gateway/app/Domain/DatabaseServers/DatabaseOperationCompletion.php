<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Actions\DatabaseConnections\AttachDatabaseConnectionAction;
use App\Data\DatabaseConnections\DatabaseConnectionData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Models\DatabaseConnection;
use App\Models\DatabaseExecutionIntent;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Process;

final readonly class DatabaseOperationCompletion implements OperationCompletion
{
    public function __construct(private DatabaseOperationExecution $execution, private RecordEventBroadcaster $broadcaster, private AttachDatabaseConnectionAction $attach) {}

    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        $this->execution->assertCompletion($operation, $outcome);
        $server = $operation->target_type === 'database_server' ? DatabaseServer::query()->find($operation->target_id) : null;
        if ($outcome->status !== OperationStatus::Succeeded) {
            if (str_starts_with($operation->kind, 'database_server.') && $server !== null) {
                $server->update(['status' => $server->status->value === 'active' ? 'active' : 'failed', 'error_code' => $outcome->errorCode,
                    'failed_step' => $operation->steps()->where('status', 'failed')->value('name')]);
            }

            return;
        }
        $intent = DatabaseExecutionIntent::recorded($operation->input['intent_id']);
        $projection = $intent->payload['projection'];
        if (isset($projection['server']) && $server !== null) {
            $server->update($projection['server']);
        }
        if (isset($projection['process']) && $server?->process !== null) {
            $server->process->fill($projection['process'])->fill(['status' => 'active', 'failed_step' => null, 'error_code' => null])->save();
        }
        if (isset($projection['adopted_connection']) && $server !== null) {
            DatabaseConnection::query()->where('slug', $projection['adopted_connection'])->first()?->update(['database_server_id' => $server->id]);
        }
        foreach ($projection['delete_connections'] ?? [] as $id) {
            $connection = DatabaseConnection::query()->findOrFail($id);
            $connection->delete();
            $this->broadcaster->broadcast(RecordEventType::DatabaseDeleted, $id, ['id' => $id, 'slug' => $connection->slug]);
        }
        if ($projection['delete_server'] ?? false) {
            $server?->delete();
            Process::query()->whereKey($projection['process_id'] ?? null)->delete();
        }
        if (isset($projection['created_connection'])) {
            $connection = DatabaseConnection::query()->findOrFail($projection['created_connection']);
            if ($projection['clone'] ?? false) {
                $connection->update(['clone_step' => 'complete']);
            }
            if (isset($projection['attach_instance'])) {
                $this->attach->execute(Instance::query()->findOrFail($projection['attach_instance']), $connection, 'DB', replace: true);
            }
            $this->broadcaster->broadcast(RecordEventType::DatabaseCreated, $connection->id, DatabaseConnectionData::fromModel($connection)->toArray());
        }
    }
}
