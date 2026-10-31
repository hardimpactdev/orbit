<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationPublicView;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseDestroyOwnedProjection implements DirectOperationProjection, DirectOperationPublicView
{
    public function input(Operation $operation): array
    {
        return array_intersect_key($operation->input, array_flip(['servers', 'sqlite']));
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
