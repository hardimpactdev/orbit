<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\TaskVms\TaskVmPackageCacheMount;
use App\Models\Project;
use App\Models\TaskVm;

final class FakeTaskVmPackageCacheMount implements TaskVmPackageCacheMount
{
    /** @var list<array{vm: string, project_id: int}> */
    public array $prepared = [];

    public function prepare(TaskVm $vm, Project $project): void
    {
        $this->prepared[] = ['vm' => $vm->name, 'project_id' => $project->id];
    }
}
