<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Models\AppSetupStep;
use App\Models\Instance;
use Illuminate\Database\Eloquent\Collection;

final class AppSetupStepListPayload
{
    /**
     * @return list<array<string, mixed>>
     */
    public function forInstance(Instance $instance): array
    {
        /** @var Collection<int, AppSetupStep> $steps */
        $steps = AppSetupStep::query()
            ->where('instance_id', $instance->id)
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
        $step->loadMissing('instance.app');

        return [
            'id' => $step->id,
            'app' => $step->instance?->app?->name,
            'instance' => $step->instance?->name,
            'order' => $step->sort_order,
            'command' => $step->command,
            'timeout_seconds' => $step->timeoutSeconds(),
        ];
    }
}
