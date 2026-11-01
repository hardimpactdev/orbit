<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationPublicView;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\DatabaseExecutionIntent;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseDestroyOwnedProjection implements DirectOperationProjection, DirectOperationPublicView
{
    public function input(Operation $operation): array
    {
        $id = app(DatabaseIntentSource::class)->ownerId($operation);
        if ($id === null || $operation->kind !== 'database.destroy_owned' || $operation->target_type !== 'instance') {
            throw new ResourceOperationException('database.ownership_unproven', 'The owned removal does not match its admitted Instance.', 422);
        }
        $intent = DatabaseExecutionIntent::recorded($id);
        $admission = $intent->payload['admission'] ?? throw new ResourceOperationException('database.ownership_unproven', 'The owned removal has no admission snapshot.', 422);
        $input = array_intersect_key($admission['input'], array_flip(['servers', 'sqlite']));
        $rows = $input['servers'] ?? null;
        $passwords = $intent->payload['commands']['database-apply']['input']['participant_passwords'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || ! is_array($passwords)) {
            throw new ResourceOperationException('database.ownership_unproven', 'The owned removal snapshot is incomplete.', 422);
        }
        $copies = [];
        $servers = [];
        foreach ($rows as $row) {
            $server = ValidatedData::object($row);
            $serverId = ValidatedData::integer($server['server_id'] ?? null);
            $nodeId = ValidatedData::integer($server['node_id'] ?? null);
            $serverInput = ValidatedData::object($server['intent'] ?? null);
            $serverInput['owners'] = (object) ValidatedData::object($serverInput['owners'] ?? []);
            $server['intent'] = $serverInput;
            $copy = ['vault' => 'orbit-control-inputs', 'key' => 'database-destroy-owned-intent-'.$id.'-server-'.$serverId];
            if (! $operation->status->terminal()) {
                $participant = $passwords[$nodeId] ?? null;
                $password = is_array($participant) ? ($participant[$serverId] ?? null) : null;
                if (! is_string($password)) {
                    throw new ResourceOperationException('database.ownership_unproven', 'The admitted server password is missing.', 422);
                }
                $copy = app(SwampCommandStep::class)->executionCopy($copy['vault'], $copy['key'], json_encode(['password' => $password], JSON_THROW_ON_ERROR));
            }
            $servers[] = $server;
            $copies['server_'.$serverId] = $copy;
        }
        $input['servers'] = $servers;

        return ['intent' => $input, 'execution_secrets' => $copies];
    }

    public function assertPublicParticipant(Operation $operation, int $callerNodeId): void
    {
        $this->publicInput($operation, $callerNodeId);
    }

    public function publicInput(Operation $operation, int $callerNodeId): array
    {
        $input = [];
        foreach (['servers', 'sqlite'] as $field) {
            $rows = $operation->input[$field] ?? [];
            $input[$field] = is_array($rows) ? array_values(array_filter($rows,
                static fn (mixed $row): bool => is_array($row) && ($row['node_id'] ?? null) === $callerNodeId)) : [];
        }
        if ($input['servers'] === [] && $input['sqlite'] === []) {
            throw new ResourceOperationException('operation.executor_forbidden', 'This Node has no admitted database removal.', 403);
        }

        return $input;
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            $projected = app(DirectOperation::class)->project($operation, $data, array_intersect_key(ValidatedData::object($data['result'] ?? []), array_flip(['backend', 'error_code'])));
            if (! $terminal && $data['status'] === 'succeeded') {
                foreach (ValidatedData::integerList($operation->input['connection_ids'] ?? []) as $id) {
                    $connection = DatabaseConnection::query()->find($id);
                    if ($connection === null) {
                        continue;
                    }
                    DatabaseConnectionTarget::query()->where('database_connection_id', $id)->delete();
                    $connection->delete();
                    app(RecordEventBroadcaster::class)->broadcast(RecordEventType::DatabaseDeleted, $id, ['id' => $id, 'slug' => $connection->slug]);
                }
            }

            return $projected;
        });
    }
}
