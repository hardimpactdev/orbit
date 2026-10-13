<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms\ImageBuild;

use App\Domain\TaskVms\TaskVmImageBuildStep;
use App\Models\TaskVmImageBuild;
use Illuminate\Support\Carbon;

final class AwaitImageBuilderBoot extends ImageBuildJob
{
    public function step(): TaskVmImageBuildStep
    {
        return TaskVmImageBuildStep::AwaitBoot;
    }

    #[\Override]
    public int $timeout = 60;

    public function retryUntil(): \DateTimeInterface
    {
        $build = TaskVmImageBuild::query()->findOrFail($this->buildId);
        foreach ($build->steps as $entry) {
            if ($entry['step'] === $this->step()->value && $entry['started_at'] !== null) {
                return Carbon::parse($entry['started_at'])->addSeconds(600);
            }
        }

        return now()->addSeconds(600);
    }
}
