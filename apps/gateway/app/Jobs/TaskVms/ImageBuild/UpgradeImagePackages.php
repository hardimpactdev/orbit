<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms\ImageBuild;

use App\Domain\TaskVms\TaskVmImageBuildStep;

final class UpgradeImagePackages extends ImageBuildJob
{
    // Allow three 300-second lock waits plus package work; remote calls total 1440 seconds.
    #[\Override]
    public int $timeout = 1500;

    public function step(): TaskVmImageBuildStep
    {
        return TaskVmImageBuildStep::UpgradePackages;
    }
}
