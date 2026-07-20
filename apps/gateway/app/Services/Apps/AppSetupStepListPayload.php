<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Models\AppInstance;
use App\Models\AppSetupStep;
use Illuminate\Database\Eloquent\Collection;

final class AppSetupStepListPayload
{
    /**
     * @return list<array<string, mixed>>
     */
    public function forAppInstance(AppInstance $instance): array
    {
        /** @var Collection<int, AppSetupStep> $steps */
        $steps = AppSetupStep::query()
            ->where('app_instance_id', $instance->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return array_values(
            $steps
                ->map(fn (AppSetupStep $step): array => $this->forStep($step))
                ->values()
                ->all(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function forStep(AppSetupStep $step): array
    {
        $step->loadMissing('appInstance.project');

        return [
            'id' => $step->id,
            'project' => $step->appInstance?->project?->name,
            'instance' => $step->appInstance?->name,
            'order' => $step->sort_order,
            'command' => $step->command,
            'timeout_seconds' => $step->timeoutSeconds(),
        ];
    }
}
