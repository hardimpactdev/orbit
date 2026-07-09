<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Enums\WorkspaceLifecyclePhase;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\WorkspaceStep;

class WorkspaceStepListPayload
{
    public function __construct(
        private readonly WorkspaceStepPolicyService $stepPolicy,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forApp(App $app, WorkspaceLifecyclePhase $phase, ?AppInstance $instance = null): array
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
    public function forStep(WorkspaceStep $step, ?App $app = null): array
    {
        $step->loadMissing(['app', 'appInstance']);
        $app ??= $step->app;

        $payload = [
            'id' => $step->id,
            'app' => $app?->name,
            'phase' => $step->phase->value,
            'order' => $step->sort_order,
            'command' => $step->command,
            'timeout_seconds' => $step->timeoutSeconds(),
        ];

        if ($step->app_instance_id !== null) {
            $payload['app_instance'] = $step->appInstance?->name;
        }

        return $payload;
    }
}
