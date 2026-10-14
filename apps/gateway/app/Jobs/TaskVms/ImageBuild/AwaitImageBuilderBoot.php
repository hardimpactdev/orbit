<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms\ImageBuild;

use App\Domain\TaskVms\TaskVmImageBuildStep;

final class AwaitImageBuilderBoot extends ImageBuildJob
{
    public function step(): TaskVmImageBuildStep
    {
        return TaskVmImageBuildStep::AwaitBoot;
    }

    #[\Override]
    public int $timeout = 660;

    // Boot polling stays in one worker run, excluding all queue waits.
    #[\Override]
    public int $tries = 0;
}
