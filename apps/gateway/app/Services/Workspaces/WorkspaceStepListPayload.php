<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Enums\WorkspaceLifecyclePhase;
use App\Models\AppInstance;
use App\Models\Project;
use App\Models\WorkspaceStep;

class WorkspaceStepListPayload
{
    public function __construct(
        private readonly WorkspaceStepPolicyService $stepPolicy,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forApp(Project $app, WorkspaceLifecyclePhase $phase, AppInstance $instance): array
    {
        return $this->stepPolicy
            ->stepsFor($app, $phase, $instance)
            ->map(fn (WorkspaceStep $step): array => $this->forStep($step, $app))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function forStep(WorkspaceStep $step, ?Project $app = null): array
    {
        $step->loadMissing(['app', 'appInstance']);
        $app ??= $step->app;

        return [
            'id' => $step->id,
            'project' => $app?->name,
            'instance' => $step->appInstance->name,
            'phase' => $step->phase->value,
            'order' => $step->sort_order,
            'command' => $step->command,
            'timeout_seconds' => $step->timeoutSeconds(),
        ];
    }
}
