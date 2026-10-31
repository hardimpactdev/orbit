<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationPrivateBindings;
use App\Models\Operation;
use App\Models\TaskVm;

/** Existing Node material keeps guest custody while the owning parent validates its intent. */
final readonly class NodeEnrollmentPrivateOwner
{
    public function taskVm(Operation $operation, string $field): ?int
    {
        $sources = ['secret_hash' => 'node.agent_secret_hash', 'worker_credentials' => 'node.worker_credentials'];
        if (! isset($sources[$field]) || ! in_array($operation->kind, ['task_vm.provision', 'tasks.provision', 'task_vm.enroll', 'task_vm.prepare_runtime'], true)
            || $operation->status->terminal()) {
            return null;
        }
        $input = $operation->input;
        $guestId = $input['guest_node_id'] ?? null;
        $vmId = $input['task_vm_id'] ?? null;
        $reference = $input[$field] ?? null;
        if (! is_int($guestId) || $guestId < 1 || ! is_int($vmId) || $vmId < 1
            || ! is_array($reference) || count($reference) !== 2
            || ($reference['$secret'] ?? null) !== $sources[$field] || ($reference['id'] ?? null) !== $guestId) {
            return null;
        }
        $vm = TaskVm::query()->find($vmId);
        if ($vm === null || $vm->node_id !== $guestId
            || ($operation->kind === 'tasks.provision'
                ? $operation->target_type !== 'task_group' || $operation->target_id !== $vm->group_id
                : $operation->target_type !== 'task_vm' || $operation->target_id !== $vm->id)) {
            return null;
        }

        // The family owns its canonical target, placement and parent predicate.
        app(DirectOperation::class)->projection($operation)->input($operation);

        return app(DirectOperationPrivateBindings::class)->ownerNode($operation, $field) === $guestId ? $guestId : null;
    }
}
