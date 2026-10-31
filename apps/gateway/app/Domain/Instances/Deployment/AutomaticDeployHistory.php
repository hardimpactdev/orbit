<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Models\Instance;
use App\Models\InstanceDeployment;

/** Gateway pause and rollback evidence, plus retry history for development defaults. */
final readonly class AutomaticDeployHistory
{
    /** The retired PHP runner's label, retained for existing history. */
    public const string Trigger = 'orbit:auto-deploy';

    /** Automatic labels contain a colon, which a manual caller's Node name cannot contain. */
    public const string SwampTrigger = 'swamp:auto-deploy';

    public const array Triggers = [self::Trigger, self::SwampTrigger];

    /** The error code of an automatic deployment that a pause stopped before it deployed anything. */
    public const string Paused = 'deployment.paused';

    public function __construct(private Instance $instance) {}

    /**
     * The newest manual deployment or rollback, in any state, after `auto_deploy` was last set. It pauses automatic
     * deployment until `auto_deploy` is set again. A rollback writes its row when a worker starts it; before that, its
     * operation holds the Instance, so the tick starts nothing.
     */
    public function pausedBy(): ?InstanceDeployment
    {
        return InstanceDeployment::query()
            ->where('instance_id', $this->instance->id)
            ->where('id', '>', $this->instance->auto_deploy_resumed_after)
            ->where(static fn ($query) => $query
                ->where('kind', InstanceDeployment::Rollback)
                ->orWhereNotIn('triggered_by', self::Triggers)
                ->orWhereNull('triggered_by'))
            ->latest('id')
            ->first();
    }

    /** The latest terminal automatic attempt after resume, only when it is an alertable failure. */
    public function failedAlert(): ?InstanceDeployment
    {
        $last = InstanceDeployment::query()
            ->where('instance_id', $this->instance->id)
            ->where('kind', 'deploy')
            ->whereIn('triggered_by', self::Triggers)
            ->where('status', '!=', 'running')
            ->where(static fn ($query) => $query->whereNull('error_code')->orWhere('error_code', '!=', self::Paused))
            ->where('id', '>', $this->instance->auto_deploy_resumed_after)
            ->latest('id')
            ->first();

        return $last instanceof InstanceDeployment && $last->status === 'failed' && is_string($last->commit) ? $last : null;
    }

    /** One observation supplies rollback blocks and a version of stored history, independent of display names. */
    public function snapshot(): AutomaticDeployHistorySnapshot
    {
        $rows = InstanceDeployment::query()->where('instance_id', $this->instance->id)->orderBy('id')->get([
            'id', 'instance_id', 'kind', 'release', 'branch', 'commit', 'started_at', 'finished_at',
            'duration_seconds', 'status', 'failed_step', 'error_code', 'selected_release', 'triggered_by', 'caller_node_id',
        ]);
        $stored = $rows->map(static fn (InstanceDeployment $row): array => $row->getAttributes())->all();

        return new AutomaticDeployHistorySnapshot(
            hash('sha256', json_encode($stored, JSON_THROW_ON_ERROR)),
            $rows->last()->id ?? 0,
            $this->rolledAwayFrom(array_values($rows->all())),
        );
    }

    /**
     * The commits that rollbacks moved away from: for each rollback in the retained history, the commit of the newest
     * succeeded deployment or rollback before it. They come from the rows, not from what a tick saw, so a rollback
     * that a resume or a manual deployment followed within a minute still counts.
     *
     * @return list<string>
     */
    public function rolledAway(): array
    {
        return $this->rolledAwayFrom(array_values(InstanceDeployment::query()
            ->where('instance_id', $this->instance->id)
            ->orderBy('id')
            ->get(['id', 'kind', 'status', 'commit'])->all()));
    }

    /** @param list<InstanceDeployment> $rows
     * @return list<string>
     */
    private function rolledAwayFrom(array $rows): array
    {
        $live = null;
        $commits = [];
        foreach ($rows as $deployment) {
            if ($deployment->kind === InstanceDeployment::Rollback && is_string($live) && $live !== $deployment->commit) {
                $commits[$live] = true;
            }
            if ($deployment->status === 'succeeded' && is_string($deployment->commit)) {
                $live = $deployment->commit;
            }
        }

        return array_keys($commits);
    }
}
