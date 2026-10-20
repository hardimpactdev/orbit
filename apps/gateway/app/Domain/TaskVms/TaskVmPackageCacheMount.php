<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Infrastructure\TaskVms\TaskVmPackageCache;
use App\Models\Project;
use App\Models\TaskVm;
use Illuminate\Container\Attributes\Bind;

#[Bind(TaskVmPackageCache::class)]
interface TaskVmPackageCacheMount
{
    public function prepare(TaskVm $vm, Project $project): void;
}
