<?php

declare(strict_types=1);

namespace App\Actions\Observation;

use App\Actions\Operations\StartOperationAction;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskExtensionState;
use App\Infrastructure\Gateway\GatewayQueueRedis;
use App\Models\Activity;
use App\Models\DatabaseConnection;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

/** Stores maintenance intent; native Swamp owns selection, snapshots and scheduling. */
final readonly class StartObservationMaintenance
{
    public function __construct(private StartOperationAction $start, private NodeAccessAuthorizer $access) {}

    public function execute(string $routine, ?string $requestId = null): Operation
    {
        $nodeId = $this->access->activeGatewayId() ?? throw new ResourceOperationException('observation.gateway_missing', 'Observation maintenance requires a Gateway Node.', 503);
        if ($routine === 'retention' && ! in_array(config('queue.failed.driver'), ['database', 'database-uuids'], true)) {
            throw new ResourceOperationException('observation.failed_store_unsupported', 'Native retention requires the configured database failed-job store.', 422);
        }
        $redis = null;
        if ($routine === 'maintenance' && GatewayQueueRedis::configured()) {
            $connection = DatabaseConnection::query()->where('slug', config('orbit.gateway_queue.connection'))->firstOrFail();
            if ($connection->driver !== DatabaseDriver::Redis || ! is_string($connection->host) || $connection->host === '') {
                throw new ResourceOperationException('observation.queue_invalid', 'The Gateway queue must use its registered Redis connection.', 422);
            }
            $redis = ['connection_id' => $connection->id, 'host' => $connection->host, 'port' => $connection->port ?? 6379,
                'database' => config()->integer('orbit.gateway_queue.redis_database', 1), 'prefix' => config()->string('horizon.prefix'),
                'queue_prefix' => GatewayQueueRedis::KeyPrefix, 'trim_job' => config()->integer('horizon.metrics.trim_snapshots.job', 24),
                'trim_queue' => config()->integer('horizon.metrics.trim_snapshots.queue', 24)];
        }
        $input = ['routine' => $routine, 'tasks_enabled' => app(TaskExtensionState::class)->enabled(),
            'activity_seconds' => config()->integer('orbit.activity_interrupted_after'),
            'batch_hours' => config()->integer('retention.job_batch_hours'), 'failed_hours' => config()->integer('retention.failed_jobs_hours'),
            'redis' => $redis];
        $input += $routine === 'maintenance'
            ? ['activities' => Activity::query()->where('status', 'running')->orderBy('id')->get(['id', 'created_at'])->toArray(), 'batches' => [], 'failed' => []]
            : ['activities' => [], 'batches' => $input['tasks_enabled'] ? DB::connection(config()->string('queue.batching.database'))->table(config()->string('queue.batching.table'))->get(['id', 'created_at', 'finished_at', 'cancelled_at'])->map(static fn (object $row): array => (array) $row)->all() : [],
                'failed' => DB::connection(config()->string('queue.failed.database'))->table(config()->string('queue.failed.table'))->get(['id', 'failed_at'])->map(static fn (object $row): array => (array) $row)->all()];
        if ($redis !== null) {
            $input['queue_redis'] = ['$secret' => 'observation.queue_redis', 'id' => $nodeId];
        }

        return $this->start->execute('observation.maintain', 'node', $nodeId, $nodeId, $input, 'observation', $requestId);
    }
}
