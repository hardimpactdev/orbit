<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms\ImageBuild;

use App\Domain\TaskVms\TaskVmImageBuildStep;

final class RunImageProgram extends ImageBuildJob
{
    public function step(): TaskVmImageBuildStep
    {
        return TaskVmImageBuildStep::Program;
    }

    public function __construct(int $buildId, public string $name)
    {
        parent::__construct($buildId);
    }

    public function program(): string
    {
        return $this->name;
    }
}
