<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms\ImageBuild;

use App\Domain\TaskVms\TaskVmImageBuildStep;

final class SmokeImage extends ImageBuildJob
{
    public function step(): TaskVmImageBuildStep
    {
        return TaskVmImageBuildStep::Smoke;
    }

    #[\Override]
    public int $timeout = 60;

    // The step runner enforces the deadline from its durable started_at, not queue submission.
    #[\Override]
    public int $tries = 0;
}
