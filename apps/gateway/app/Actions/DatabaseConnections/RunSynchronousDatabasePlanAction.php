<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Domain\DatabaseServers\DatabaseOwnership;
use App\Domain\DatabaseServers\DatabaseServerAdmin;
use App\Domain\DatabaseServers\MysqlStatements;
use App\Domain\DatabaseServers\PreparedDatabaseOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Activity;
use App\Models\DatabaseConnection;
use App\Models\DatabaseExecutionIntent;
use App\Models\DatabaseServer;
use App\Support\ValidatedData;
use Illuminate\Support\Str;

/** Retained lifecycle callers execute the same guarded SQL plan without admitting an Operation. */
final readonly class RunSynchronousDatabasePlanAction
{
    public function __construct(private DatabaseServerAdmin $admin, private DatabaseOwnership $ownership) {}

    /** The caller holds DatabaseRemovalLock across preparation, effects and record updates. */
    public function execute(PreparedDatabaseOperation $prepared): void
    {
        $intent = DatabaseExecutionIntent::recorded($prepared->input['intent_id']);
        $server = DatabaseServer::query()->findOrFail($prepared->targetId);
        $connectionId = ValidatedData::integer($prepared->input['connection_id']);
        $effectStarted = false;
        try {
            foreach ($intent->payload['commands'] as $key => $command) {
                if ($command['cleanup'] ?? false) {
                    continue;
                }
                if (isset($command['reset_database'])) {
                    $this->ownership->assertMysqlNames(DatabaseConnection::query()->findOrFail($connectionId), [$command['reset_database']]);
                }
                $effectStarted = $effectStarted || ! ($command['read_only'] ?? false);
                $rows = $this->admin->execute($server, ValidatedData::string($command['input']['sql']));
                if (($command['expect_empty'] ?? false) && $rows !== []) {
                    throw new ResourceOperationException($command['refusal'] ?? 'database.name_conflict', 'The database plan refused a conflicting live database state.', 409);
                }
                $created = $intent->payload['projection']['database_creates'][$key] ?? null;
                if ($created !== null || isset($command['grant_prefix'])) {
                    $this->recordCreation($connectionId, $created ?? $command['grant_prefix'], isset($command['grant_prefix']));
                }
            }
        } catch (ResourceOperationException $exception) {
            if (isset($intent->payload['projection']['created_connection'])) {
                $connection = DatabaseConnection::query()->find($connectionId);
                if ($connection !== null && ! $connection->targets()->exists()) {
                    $refusedBeforeEffect = ! $effectStarted && $exception->errorCode === 'database.name_conflict';
                    $absent = $connection->driver->value === 'mysql' && $connection->creation_receipts === null
                        && $this->admin->execute($server, (new MysqlStatements)->databasesNamed($connection->database ?? '', $connection->test_database)) === [];
                    if ($refusedBeforeEffect || $absent) {
                        $connection->delete();
                    }
                }
            }
            throw $exception;
        } finally {
            try {
                foreach ($intent->payload['commands'] as $command) {
                    if ($command['cleanup'] ?? false) {
                        $this->admin->execute($server, ValidatedData::string($command['input']['sql']));
                    }
                }
            } finally {
                $intent->delete();
            }
        }
    }

    private function recordCreation(int $connectionId, string $database, bool $prefix): void
    {
        $connection = DatabaseConnection::query()->findOrFail($connectionId);
        $proof = ['database' => $database, 'username' => $connection->username, 'server_id' => $connection->database_server_id,
            ...($prefix ? ['scope' => 'prefix'] : [])];
        $activity = Activity::query()->create(['request_id' => (string) Str::uuid(), 'command' => 'database:lifecycle:create',
            'description' => 'Synchronous managed database creation proof.', 'subject_type' => DatabaseConnection::class,
            'subject_id' => $connectionId, 'status' => 'succeeded', 'exit_code' => 0,
            'properties' => ['connection_id' => $connectionId, 'node_id' => $connection->server?->node_id, 'receipt' => $proof]]);
        $proofs = array_values(array_filter($connection->creation_receipts ?? [], static fn (array $recorded): bool => $recorded['database'] !== $database
            || ($recorded['scope'] ?? null) !== ($proof['scope'] ?? null)));
        $connection->forceFill(['creation_receipts' => [...$proofs, [...$proof, 'activity_id' => $activity->id]]])->save();
    }
}
