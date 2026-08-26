<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Models\App;
use App\Models\AppSetupStep;
use App\Models\Instance;

final readonly class CopyAppDevelopmentSetupSteps
{
    public function handle(App $app, Instance $instance): void
    {
        $steps = $app->developmentSetupSteps()->orderBy('sort_order')->orderBy('id')->get();

        foreach ($steps as $step) {
            AppSetupStep::query()->create([
                'instance_id' => $instance->id,
                'sort_order' => $step->sort_order,
                'command' => $step->command,
                'timeout_seconds' => $step->timeout_seconds,
            ]);
        }
    }
}
