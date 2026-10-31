<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmPackageCacheMount;
use App\Models\Project;
use App\Models\TaskVm;

/** Workspace cache effects belong to the native Task parent. */
final readonly class TaskVmPackageCache implements TaskVmPackageCacheMount
{
    public const string HostPath = '/var/lib/orbit/package-cache';

    public const string GuestPath = '/opt/orbit/package-cache';

    public const string Device = 'package-cache';

    public function prepare(TaskVm $vm, Project $project): void
    {
        throw new ResourceOperationException('operation.kind_unavailable', 'Workspace cache preparation requires its native Task parent.', 409);
    }
}
