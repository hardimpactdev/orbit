<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms\ImageBuild;

use App\Domain\TaskVms\TaskVmImageBuildStep;

final class CleanImageBuilder extends ImageBuildJob
{
    public function step(): TaskVmImageBuildStep
    {
        return TaskVmImageBuildStep::Clean;
    }
}
