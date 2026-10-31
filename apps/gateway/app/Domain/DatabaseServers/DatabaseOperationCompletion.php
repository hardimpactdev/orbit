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
use App\Support\ValidatedData;

final readonly class DatabaseOperationCompletion implements OperationCompletion
{
    public function __construct(private DatabaseOperationExecution $execution, private RecordEventBroadcaster $broadcaster, private AttachDatabaseConnectionAction $attach) {}

    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        $this->execution->assertCompletion($operation, $outcome);
        $server = $operation->target_type === 'database_server' ? DatabaseServer::query()->find($operation->target_id) : null;
        $runs = ValidatedData::object($operation->state['executor_runs'] ?? []);
        $preflight = ValidatedData::object($runs['preflight'] ?? []);
        if ($outcome->status !== OperationStatus::Succeeded) {
            if (in_array($operation->kind, ['database.create', 'database.clone'], true)
                && $outcome->errorCode === 'database.name_conflict'
                && ($preflight['result'] ?? null) === ['error_code' => 'database.name_conflict']) {
                $intent = DatabaseExecutionIntent::recorded($operation->input['intent_id']);
                $effects = array_filter($runs, static fn (mixed $run, string $key): bool => ! ($intent->payload['commands'][$key]['read_only'] ?? false), ARRAY_FILTER_USE_BOTH);
                if ($effects === [] && isset($intent->payload['projection']['created_connection'])) {
                    $connection = DatabaseConnection::query()->find($intent->payload['projection']['created_connection']);
                    if ($connection !== null && ! $connection->targets()->exists()) {
                        $connection->delete();
                    }
                }
            }

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
            DatabaseConnection::query()->where('slug', $projection['adopted_connection'])->update(['database_server_id' => $server->id]);
        }
        foreach ($projection['delete_connections'] ?? [] as $id) {
            $connection = DatabaseConnection::query()->findOrFail($id);
            $connection->delete();
            $this->broadcaster->broadcast(RecordEventType::DatabaseDeleted, $id, ['id' => $id, 'slug' => $connection->slug]);
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
