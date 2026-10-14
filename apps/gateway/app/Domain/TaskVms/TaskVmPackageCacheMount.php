<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Models\Project;
use App\Models\TaskVm;

interface TaskVmPackageCacheMount
{
    public function prepare(TaskVm $vm, Project $project): void;
}
