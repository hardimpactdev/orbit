<?php

declare(strict_types=1);

namespace App\Actions\Observation;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskExtensionState;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use Illuminate\Support\Facades\DB;

/** Capture public observations once; this family never performs housekeeping. */
final readonly class StartObservationMaintenance
{
    public function __construct(private StartOperationAction $start, private NodeAccessAuthorizer $access, private SwampSshExecutionInput $ssh) {}

    public function execute(string $routine, ?string $requestId = null): Operation
    {
        if (! in_array($routine, ['maintenance', 'retention'], true)) {
            throw new ResourceOperationException('observation.input_invalid', 'Select maintenance or retention observations.', 422);
        }
        $nodeId = $this->access->activeGatewayId() ?? throw new ResourceOperationException('observation.gateway_missing', 'Observation requires the registered Gateway Node.', 503);
        if ($routine === 'retention' && ! in_array(config('queue.failed.driver'), ['database', 'database-uuids'], true)) {
            throw new ResourceOperationException('observation.failed_store_unsupported', 'Retention observations require the database failed-job store.', 422);
        }
        $tasks = app(TaskExtensionState::class)->enabled();
        $node = Node::query()->findOrFail($nodeId);
        $horizon = Process::query()->where('owner_type', Node::class)->where('owner_id', $nodeId)->where('name', 'horizon')->where('runtime', 'systemd')->first();
        $input = ['routine' => $routine, 'tasks_enabled' => $tasks, 'evaluated_at' => now()->toISOString(),
            'activity_seconds' => config()->integer('orbit.activity_interrupted_after'),
            'batch_hours' => config()->integer('retention.job_batch_hours'), 'failed_hours' => config()->integer('retention.failed_jobs_hours'),
            'ssh' => $routine === 'maintenance' ? $this->ssh->forNode($node)['target'] : null,
            'horizon_unit' => $horizon === null ? null : "orbit-process-{$horizon->id}-horizon.service",
            'activities' => $routine === 'maintenance' ? Activity::query()->where('status', 'running')->orderBy('id')->get(['id', 'created_at'])->toArray() : [],
            'batches' => $routine === 'retention' && $tasks ? DB::connection(config()->string('queue.batching.database'))->table(config()->string('queue.batching.table'))->get(['id', 'created_at', 'finished_at', 'cancelled_at'])->map(static fn (object $row): array => (array) $row)->all() : [],
            'failed' => $routine === 'retention' ? DB::connection(config()->string('queue.failed.database'))->table(config()->string('queue.failed.table'))->get(['id', 'failed_at'])->map(static fn (object $row): array => (array) $row)->all() : [],
        ];

        return $this->start->execute('observation.maintain', 'node', $nodeId, $nodeId, $input, 'observation', $requestId);
    }
}
