<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Instance;
use Illuminate\Support\Facades\DB;

/**
 * Record projection only. The authorized parent supplies its saved artifact and successful native result.
 *
 * @phpstan-import-type Artifact from PrepareOwnedDatabaseDestructionAction
 */
final readonly class ProjectOwnedDatabaseDestructionAction
{
    public function __construct(private PrepareOwnedDatabaseDestructionAction $artifacts, private RecordEventBroadcaster $broadcaster) {}

    /** @param Artifact $artifact
     * @param  array<string,mixed>  $result
     */
    public function execute(Instance $target, array $artifact, array $result): void
    {
        $expected = ['artifact_id' => $artifact['artifact_id'], 'owner_instance_id' => $artifact['owner_instance_id'],
            'node_id' => $artifact['node_id'], 'removed_connection_ids' => array_column($artifact['connections'], 'connection_id'), 'backend' => 'absent'];
        if (count($result) !== count($expected) || array_diff_key($result, $expected) !== []
            || array_any(array_keys($expected), static fn (string $key): bool => ($result[$key] ?? null) !== $expected[$key])) {
            throw new ResourceOperationException('operation.result_invalid', 'Owned database destruction requires its complete bounded native result.', 422);
        }
        DB::transaction(function () use ($target, $artifact): void {
            $current = Instance::query()->lockForUpdate()->findOrFail($target->id);
            if ($artifact['owner_instance_id'] !== $current->id
                || $artifact['node_id'] !== $current->node_id || $artifact['checkout'] !== $current->checkout_path) {
                $this->conflict();
            }
            $remaining = $this->artifacts->snapshot($current, lock: true);
            $admitted = array_column($artifact['connections'], null, 'connection_id');
            // A row removed by an earlier successful projection is fine; a changed or added row is not.
            foreach ($remaining as $connection) {
                if (($admitted[$connection['connection_id']] ?? null) !== $connection) {
                    $this->conflict();
                }
            }
            // An admitted row that changed owner must never be silently treated as already removed.
            if (DatabaseConnection::query()->whereIn('id', array_keys($admitted))->where(fn ($query) => $query
                ->whereNull('owner_instance_id')->orWhere('owner_instance_id', '!=', $current->id))->exists()) {
                $this->conflict();
            }
            $ids = array_column($remaining, 'connection_id');
            DatabaseConnectionTarget::query()->whereIn('database_connection_id', $ids)->delete();
            DatabaseConnection::query()->whereIn('id', $ids)->delete();
            foreach ($remaining as $connection) {
                DB::afterCommit(fn () => $this->broadcaster->broadcast(RecordEventType::DatabaseDeleted, $connection['connection_id'],
                    ['id' => $connection['connection_id'], 'slug' => $connection['slug']]));
            }
        });
    }

    private function conflict(): never
    {
        throw new ResourceOperationException('database.removal_conflict', 'The database owner, placement or owned connection set changed.', 409);
    }
}
