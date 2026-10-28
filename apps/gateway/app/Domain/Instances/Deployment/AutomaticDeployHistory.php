<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Data\Instances\InstanceDeploymentData;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use Illuminate\Support\Carbon;

/** Gateway pause and rollback evidence, plus retry history for development defaults. */
final readonly class AutomaticDeployHistory
{
    /** The `triggered_by` of an automatic deployment. It contains a colon, so no Node name can take it. */
    public const string Trigger = 'orbit:auto-deploy';

    /** The error code of an automatic deployment that a pause stopped before it deployed anything. */
    public const string Paused = 'deployment.paused';

    public const int MaxAttempts = 3;

    public const int RetryMinutes = 10;

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
                ->orWhere('triggered_by', '!=', self::Trigger)
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
            ->where('triggered_by', self::Trigger)
            ->where('status', '!=', 'running')
            ->where(static fn ($query) => $query->whereNull('error_code')->orWhere('error_code', '!=', self::Paused))
            ->where('id', '>', $this->instance->auto_deploy_resumed_after)
            ->latest('id')
            ->first();

        return $last instanceof InstanceDeployment && $last->status === 'failed' && is_string($last->commit) ? $last : null;
    }

    /** One observation supplies both rollback blocks and the version of the retained public history. */
    public function snapshot(): AutomaticDeployHistorySnapshot
    {
        $rows = InstanceDeployment::query()->where('instance_id', $this->instance->id)->orderBy('id')->get([
            'id', 'instance_id', 'kind', 'release', 'branch', 'commit', 'started_at', 'finished_at',
            'duration_seconds', 'status', 'failed_step', 'error_code', 'selected_release', 'triggered_by',
        ]);
        $public = $rows->map(static fn (InstanceDeployment $row): array => InstanceDeploymentData::fromModel($row)->toArray())->all();

        return new AutomaticDeployHistorySnapshot(
            hash('sha256', json_encode($public, JSON_THROW_ON_ERROR)),
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

    /**
     * Commits that may not deploy automatically until the next manual deployment.
     *
     * @return list<string>
     */
    public function blocked(): array
    {
        return array_keys(array_filter(
            $this->failures(),
            static fn (array $failure): bool => $failure['attempts'] >= self::MaxAttempts,
        ));
    }

    /** Whether the commit failed fewer than {@see self::MaxAttempts} times and its retry is not due yet. */
    public function backingOff(string $commit): bool
    {
        $failure = $this->failures()[$commit] ?? null;

        if ($failure === null || $failure['attempts'] >= self::MaxAttempts) {
            return false;
        }

        $wait = self::RetryMinutes * 2 ** ($failure['attempts'] - 1);

        return $failure['last']->gt(Carbon::now()->subMinutes($wait));
    }

    /**
     * Failed automatic development deployments since the last manual deployment, by commit.
     *
     * @return array<string, array{attempts: int, last: Carbon}>
     */
    private function failures(): array
    {
        $manual = InstanceDeployment::query()
            ->where('instance_id', $this->instance->id)
            ->where('kind', 'deploy')
            ->where(static fn ($query) => $query->where('triggered_by', '!=', self::Trigger)->orWhereNull('triggered_by'))
            ->max('id');
        $failures = [];

        foreach (InstanceDeployment::query()
            ->where('instance_id', $this->instance->id)
            ->where('kind', 'deploy')
            ->where('triggered_by', self::Trigger)
            ->where('status', 'failed')
            ->whereNotNull('commit')
            ->where(static fn ($query) => $query->whereNull('error_code')->orWhere('error_code', '!=', self::Paused))
            ->where('id', '>', is_int($manual) ? $manual : 0)
            ->orderBy('id')
            ->get() as $deployment) {
            $commit = (string) $deployment->commit;
            $previous = $failures[$commit] ?? ['attempts' => 0];
            $failures[$commit] = [
                'attempts' => $previous['attempts'] + 1,
                'last' => $deployment->finished_at ?? $deployment->started_at,
            ];
        }

        return $failures;
    }
}
