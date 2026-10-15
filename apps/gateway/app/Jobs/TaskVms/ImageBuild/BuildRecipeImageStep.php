<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms\ImageBuild;

use App\Domain\TaskVms\TaskVmImageBuildStep;

final class BuildRecipeImageStep extends ImageBuildJob
{
    public function __construct(int $buildId, public string $profile, public string $phase)
    {
        parent::__construct($buildId);
        $this->timeout = 1500;
    }

    public function step(): TaskVmImageBuildStep
    {
        return TaskVmImageBuildStep::Recipe;
    }

    public function program(): string
    {
        return $this->profile.':'.$this->phase;
    }
}
