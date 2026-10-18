<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Fleet\ConvergeFleetRecipeAction;
use App\Actions\Fleet\MergeNightlyRecipePullRequestsAction;
use App\Actions\Fleet\OpenNightlyRecipePullRequestAction;
use App\Actions\Fleet\OpenPhpBuildPullRequestAction;
use App\Domain\Fleet\FleetServingRelease;
use App\Domain\Fleet\NightlyRecipePendingDeployment;
use App\Domain\Fleet\RecipeMaintenanceAlerts;
use App\Domain\Gateway\GatewayQueue;
use App\Jobs\TaskVms\TaskVmJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class MaintainMachineRecipe implements ShouldBeUnique, ShouldQueue
{
    use TaskVmJob;

    public int $tries = 1;

    public int $timeout = 1600;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 1800;

    public function __construct(public bool $snapshot = false)
    {
        $this->onTaskVmQueue(GatewayQueue::Builds);
    }

    public function uniqueId(): string
    {
        return $this->snapshot ? 'snapshot-pr' : 'fleet';
    }

    public function handle(FleetServingRelease $serving, OpenNightlyRecipePullRequestAction $pullRequest, ConvergeFleetRecipeAction $fleet, MergeNightlyRecipePullRequestsAction $merge, OpenPhpBuildPullRequestAction $php): void
    {
        if ($serving->read()['state'] !== FleetServingRelease::Adopted || $serving->supersedes()) {
            return;
        }
        if ($this->snapshot) {
            $date = now('Europe/Amsterdam');
            $key = 'machine-recipe:nightly-completed:'.$date->format('Ymd');
            if (Cache::has($key)) {
                return;
            }
            try {
                Log::info('Nightly recipe snapshot PR.', ['url' => $pullRequest->execute()]);
                Log::info('Nightly PHP build PR.', ['url' => $php->execute()]);
                Cache::put($key, true, $date->copy()->endOfDay());
            } catch (NightlyRecipePendingDeployment $wait) {
                Log::info($wait->getMessage());
            }
        } else {
            $merge->execute();
            $fleet->execute();
        }
    }

    public function failed(?Throwable $error): void
    {
        app(RecipeMaintenanceAlerts::class)->halted($this->snapshot
            ? 'Nightly recipe snapshot PR failed. Inspect the failed Gateway job; the fleet was not advanced by this job.'
            : 'Recipe maintenance job failed or timed out. Inspect the build report and Doctor; an interrupted Node visit halts on the next tick.');
    }
}
