<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Task;
use App\Models\TaskVm;

/** Allocation stays unavailable until its owning native provision admission lands. */
final readonly class AllocateTaskVmAction
{
    public function execute(Task $group): TaskVm
    {
        throw new ResourceOperationException('operation.kind_unavailable', 'Task VM allocation requires its owning native provision root.', 409);
    }
}
