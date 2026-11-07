<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Models\Operation;
use App\Models\TaskVm;

/** Pure admitted VM/guest ownership shared by enrollment and its placed participants. */
final readonly class TaskVmEnrollmentOwner
{
    public static function owned(Operation $operation): ?TaskVm
    {
        if (! in_array($operation->kind, ['task_vm.enroll', 'task_vm.prepare_runtime', 'task_vm.provision', 'tasks.provision'], true)) {
            return null;
        }
        $id = $operation->input['task_vm_id'] ?? null;
        $vm = is_int($id) ? TaskVm::query()->find($id) : null;
        if ($vm === null || $vm->node_id === null
            || ($operation->target_type === 'task_vm' ? $vm->id !== $operation->target_id
                : ($operation->target_type !== 'task_group' || $vm->group_id !== $operation->target_id))) {
            return null;
        }
        $enrollment = $operation->input['enrollment'] ?? $operation->input;

        return is_array($enrollment) && ($enrollment['task_vm_id'] ?? null) === $vm->id
            && ($enrollment['guest_node_id'] ?? null) === $vm->node_id ? $vm : null;
    }
}
