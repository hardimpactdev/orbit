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
    public int $timeout = 900;

    // Allow the inline boot window plus launch and smoke checks, without counting queue time.
    #[\Override]
    public int $tries = 0;
}
