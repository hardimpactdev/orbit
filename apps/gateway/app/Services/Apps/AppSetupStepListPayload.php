<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Models\App;
use App\Models\AppSetupStep;
use Illuminate\Database\Eloquent\Collection;

final class AppSetupStepListPayload
{
    /**
     * @return list<array<string, mixed>>
     */
    public function forApp(App $app): array
    {
        /** @var Collection<int, AppSetupStep> $steps */
        $steps = AppSetupStep::query()
            ->where('app_id', $app->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $steps
            ->map(fn (AppSetupStep $step): array => $this->forStep($step))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function forStep(AppSetupStep $step): array
    {
        $step->loadMissing('app');

        return [
            'id' => $step->id,
            'app' => $step->app?->name,
            'order' => $step->sort_order,
            'command' => $step->command,
            'timeout_seconds' => $step->timeoutSeconds(),
        ];
    }
}
