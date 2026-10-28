<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Data\Instances\InstanceDeploymentData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Records one deployments row per `instance:deploy` or `instance:rollback` run:
 * one write when the run starts, and one write when it finishes. Retains only
 * the most recent rows per Instance. Each write broadcasts the row, so a
 * connected client refreshes the Instance's deployment history without polling.
 */
final readonly class InstanceDeploymentRecorder
{
    public const int RETAINED_PER_INSTANCE = 50;

    public function __construct(
        private ?RecordEventBroadcaster $broadcaster = null,
    ) {}

    public function start(Instance $instance, string $triggeredBy, string $kind = 'deploy', ?int $callerNodeId = null): InstanceDeployment
    {
        $deployment = InstanceDeployment::query()->create([
            'instance_id' => $instance->id,
            'kind' => $kind,
            'branch' => $instance->placedOnAppDev() && $instance->name === 'default'
                ? $instance->project->default_branch
                : ($instance->deployment_branch ?? $instance->branch),
            'started_at' => Carbon::now(),
            'status' => 'running',
            'triggered_by' => $triggeredBy,
            'caller_node_id' => $callerNodeId,
        ]);

        $this->broadcast(RecordEventType::DeploymentCreated, $deployment);

        return $deployment;
    }

    /** @param list<array<string, mixed>> $events */
    public function finish(InstanceDeployment $deployment, DeploymentResult $result, array $events): void
    {
        $startedAt = $deployment->started_at;
        $finishedAt = Carbon::now();

        DB::transaction(function () use ($deployment, $result, $events, $startedAt, $finishedAt): void {
            $deployment->update([
                'release' => $result->release?->name,
                'commit' => $result->commit,
                'status' => $result->succeeded ? 'succeeded' : 'failed',
                'failed_step' => $result->failure?->boundary->value,
                'error_code' => $result->failure?->errorCode,
                'selected_release' => $result->selectedRelease?->name,
                'finished_at' => $finishedAt,
                'duration_seconds' => max(0, $finishedAt->diffInSeconds($startedAt)),
                'events' => $events,
            ]);

            $instance = Instance::query()->lockForUpdate()->findOrFail($deployment->instance_id);
            if ($instance->placedOnAppProd()) {
                $state = AutomaticDeployState::of($instance);
                foreach (new AutomaticDeployHistory($instance)->rolledAway() as $commit) {
                    $state->block($commit);
                }
                $state->save($instance);
            }

            $this->prune($deployment->instance_id);
        });
        $this->broadcast(RecordEventType::DeploymentUpdated, $deployment);
    }

    private function broadcast(RecordEventType $type, InstanceDeployment $deployment): void
    {
        ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
            $type,
            $deployment->id,
            InstanceDeploymentData::fromModel($deployment)->toArray(),
        );
    }

    private function prune(int $instanceId): void
    {
        $total = InstanceDeployment::query()->where('instance_id', $instanceId)->count();

        if ($total <= self::RETAINED_PER_INSTANCE) {
            return;
        }

        $staleIds = InstanceDeployment::query()
            ->where('instance_id', $instanceId)
            ->orderBy('started_at')
            ->orderBy('id')
            ->limit($total - self::RETAINED_PER_INSTANCE)
            ->pluck('id');

        InstanceDeployment::query()->whereIn('id', $staleIds)->delete();
    }
}
