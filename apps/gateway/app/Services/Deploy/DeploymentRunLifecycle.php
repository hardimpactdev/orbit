<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Models\DeploymentRun;
use App\Models\Instance;
use App\Services\Operations\DatabaseLockRetry;
use Illuminate\Support\Carbon;

final readonly class DeploymentRunLifecycle
{
    public function __construct(
        private DatabaseLockRetry $databaseLockRetry,
    ) {}

    public function start(Instance $instance, Carbon $startedAt): DeploymentRun
    {
        return $this->databaseLockRetry->transaction(static function () use ($instance, $startedAt): DeploymentRun {
            $instanceQuery = Instance::query()->whereKey($instance->id);
            $instanceQuery->lockForUpdate();
            $lockedInstance = $instanceQuery->firstOrFail();
            $run = DeploymentRun::query()->create([
                'instance_id' => $lockedInstance->id,
                'status' => 'running',
                'exit_code' => null,
                'started_at' => $startedAt,
            ]);

            $lockedInstance->forceFill([
                'latest_deployment_status' => 'running',
                'latest_deployment_run_id' => $run->id,
            ])->save();

            return $run;
        });
    }

    public function completed(DeploymentRun $run): DeploymentRun
    {
        return $this->finish($run, status: 'completed', exitCode: 0);
    }

    public function failed(DeploymentRun $run, int $exitCode = 1): DeploymentRun
    {
        return $this->finish($run, status: 'failed', exitCode: $exitCode);
    }

    private function finish(DeploymentRun $run, string $status, int $exitCode): DeploymentRun
    {
        return $this->databaseLockRetry->transaction(static function () use ($run, $status, $exitCode): DeploymentRun {
            $runQuery = DeploymentRun::query()->whereKey($run->id);
            $runQuery->lockForUpdate();
            $lockedRun = $runQuery->firstOrFail();

            if ($lockedRun->status !== 'running') {
                return $lockedRun;
            }

            $finishedAt = Carbon::now();
            $startedAt = $lockedRun->started_at ?? $finishedAt;

            $lockedRun->forceFill([
                'status' => $status,
                'exit_code' => $exitCode,
                'finished_at' => $finishedAt,
                'duration_ms' => (int) $startedAt->diffInMilliseconds($finishedAt),
            ])->save();

            Instance::query()
                ->whereKey($lockedRun->instance_id)
                ->where('latest_deployment_run_id', $lockedRun->id)
                ->update(['latest_deployment_status' => $status]);

            return $lockedRun;
        });
    }
}
