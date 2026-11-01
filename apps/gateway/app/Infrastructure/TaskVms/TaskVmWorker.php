<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Task;
use App\Models\TaskVm;

/** Legacy workspace callers must not stop a VM or issue a plain-VM worker. */
final readonly class TaskVmWorker
{
    public function stopGroup(Task $group): bool
    {
        $vm = TaskVm::forGroup($group);

        return $vm instanceof TaskVm && $this->stopVm($vm);
    }

    public function stopVm(TaskVm $vm): bool
    {
        if ($vm->worker_stop_required) {
            throw new ResourceOperationException('operation.kind_unavailable', 'Retire the legacy guest execution through its owning native parent.', 409);
        }

        return false;
    }
}
