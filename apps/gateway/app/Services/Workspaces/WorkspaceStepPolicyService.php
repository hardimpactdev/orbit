<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Enums\WorkspaceLifecyclePhase;
use App\Models\App;
use App\Models\Instance;
use App\Models\WorkspaceStep;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

final readonly class WorkspaceStepPolicyService
{
    /**
     * @return EloquentCollection<int, WorkspaceStep>
     */
    public function stepsFor(
        App $app,
        WorkspaceLifecyclePhase $phase,
        Instance $instance,
    ): EloquentCollection {
        return $this->orderedSteps($app, $phase, $instance->id);
    }

    public function hasStepsFor(App $app, WorkspaceLifecyclePhase $phase, Instance $instance): bool
    {
        return $this->hasScopedStep($app, $phase, $instance->id);
    }

    public function findInstanceStep(
        App $app,
        WorkspaceLifecyclePhase $phase,
        int $stepId,
        Instance $instance,
    ): ?WorkspaceStep {
        $step = WorkspaceStep::find($stepId);

        if (! $step instanceof WorkspaceStep) {
            return null;
        }

        if ($step->app_id !== $app->id) {
            return null;
        }

        if ($step->phase !== $phase) {
            return null;
        }

        if ($step->instance_id !== $instance->id) {
            return null;
        }

        return $step;
    }

    public function remainingInstanceCount(
        App $app,
        WorkspaceLifecyclePhase $phase,
        Instance $instance,
    ): int {
        return (int) $this->scopedTableQuery($app, $phase, $instance->id)->count();
    }

    /**
     * @return EloquentCollection<int, WorkspaceStep>
     */
    private function orderedSteps(
        App $app,
        WorkspaceLifecyclePhase $phase,
        int $instanceId,
    ): EloquentCollection {
        /** @var list<int|string> $ids */
        $ids = $this
            ->scopedTableQuery($app, $phase, $instanceId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        /** @var array<int, WorkspaceStep> $steps */
        $steps = [];

        foreach ($ids as $id) {
            $idString = (string) $id;

            if (! ctype_digit($idString)) {
                continue;
            }

            $step = WorkspaceStep::find((int) $idString);

            if ($step instanceof WorkspaceStep) {
                $steps[] = $step;
            }
        }

        /** @var EloquentCollection<int, WorkspaceStep> $collection */
        $collection = new EloquentCollection($steps);

        return $collection;
    }

    private function hasScopedStep(App $app, WorkspaceLifecyclePhase $phase, int $instanceId): bool
    {
        return $this->scopedTableQuery($app, $phase, $instanceId)->exists();
    }

    private function scopedTableQuery(App $app, WorkspaceLifecyclePhase $phase, int $instanceId): QueryBuilder
    {
        return DB::table('workspace_steps')
            ->where('app_id', $app->id)
            ->where('phase', $phase->value)
            ->where('instance_id', $instanceId);
    }
}
