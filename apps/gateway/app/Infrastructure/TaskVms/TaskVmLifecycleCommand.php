<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Actions\TaskVms\ForgetTaskVmWorkspacesAction;
use App\Actions\TaskVms\PrepareTaskVmEnrollmentAction;
use App\Actions\TaskVms\StartTaskVmRuntimeOperationAction;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationTransport;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Operation;
use App\Models\TaskVm;
use App\Support\ValidatedData;

/** Gateway state projections only. Node and Route owners execute their own children. */
final readonly class TaskVmLifecycleCommand implements OperationTransport
{
    use TaskVmTransportSchema;

    public function __construct(private TaskVmProvider $provider, private TaskVmGuest $guest,
        private ForgetTaskVmWorkspacesAction $workspaces, private StartTaskVmRuntimeOperationAction $runtime) {}

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function run(Operation $operation, array $run, #[\SensitiveParameter] array $input): array
    {
        $vm = TaskVm::query()->findOrFail(ValidatedData::integer($input['task_vm_id']));
        if ($vm->id !== $operation->target_id || $vm->id !== ($operation->input['task_vm_id'] ?? null)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The VM does not match this Operation.', 422);
        }

        return match ($input['action'] ?? null) {
            'intent' => ['ready' => true],
            'prepare-enrollment' => app(PrepareTaskVmEnrollmentAction::class)->execute($operation, $vm),
            'enroll' => $this->enroll($operation, $vm),
            'stopped' => $this->stopped($vm),
            'destroying' => $this->destroying($operation, $vm),
            'forget-workspaces' => $this->forgetWorkspaces($operation, $vm),
            'forget' => $this->forget($operation, $vm),
            default => throw new ResourceOperationException('operation.execution_forbidden', 'The VM intent primitive is not declared.', 422),
        };
    }

    /** @return array<string, mixed> */
    private function enroll(Operation $operation, TaskVm $vm): array
    {
        $parent = $this->parent($operation, $vm);
        $this->owner($operation, 'prepare-enrollment', 'task_vm.prepare_enrollment');
        $prepared = $parent->state['enrollment'] ?? [];
        $vm->refresh();
        if (($prepared['node_id'] ?? null) !== $vm->node_id || $vm->node?->status !== LifecycleStatus::Active) {
            throw new ResourceOperationException('operation.completion_unproven', 'The reserved VM Node is not active.', 409);
        }
        if (($prepared['enrolled'] ?? false) !== true) {
            $owner = $this->owner($operation, 'node-enrollment', 'node.add');
            if ($owner->target_type !== 'node' || $owner->target_id !== $vm->node_id || ($owner->result['enrolled'] ?? null) !== true) {
                throw new ResourceOperationException('operation.completion_unproven', 'The VM has no proven owning enrollment child.', 409);
            }
        }
        $this->runtime->materialize($vm);

        return ['ready' => true, 'node_id' => $vm->node_id];
    }

    /** @return array{ready: bool} */
    private function stopped(TaskVm $vm): array
    {
        $state = $this->provider->observe($vm);
        if ($state === null) {
            throw new TaskVmException('task_vm.vm_missing', 'The task VM is missing; its workspace was not confirmed parked.');
        }
        if (! $state->settled || $state->running) {
            throw new TaskVmException($state->settled ? 'task_vm.park_failed' : 'task_vm.not_settled', 'The task VM was not confirmed stopped.');
        }

        return ['ready' => true];
    }

    /** @return array{ready: bool} */
    private function destroying(Operation $operation, TaskVm $vm): array
    {
        app(TaskVmPreservation::class)->assertAllowed($operation, $vm, $this->guest->observeSettled($vm));
        $vm->update(['state' => TaskVmState::Destroying, 'error_code' => null, 'error_message' => null]);

        return ['ready' => true];
    }

    /** @return array{ready: bool} */
    private function forget(Operation $operation, TaskVm $vm): array
    {
        if ($this->provider->observe($vm) !== null) {
            throw new TaskVmException('task_vm.vm_still_present', 'The VM must be absent before its records are removed.');
        }
        $parent = $this->parent($operation, $vm);
        $this->owner($operation, 'forget-workspaces', 'task_vm.forget_workspaces');
        if ($parent->kind !== 'task_vm.destroy' || $vm->node_id !== null) {
            throw new ResourceOperationException('operation.completion_unproven', 'The owning Node removal must finish before record cleanup.', 409);
        }
        $nodeId = $parent->input['guest_node_id'] ?? null;
        if ($nodeId !== null) {
            $removal = $this->owner($operation, 'node-removal', 'node.remove');
            if ($removal->target_type !== 'node' || $removal->target_id !== $nodeId) {
                throw new ResourceOperationException('operation.completion_unproven', 'The removal child names another Node.', 409);
            }
        }
        $vm->update(['pi_token' => null, 'worker_stop_required' => false]);

        return ['ready' => true];
    }

    /** @return array{ready:bool} */
    private function forgetWorkspaces(Operation $operation, TaskVm $vm): array
    {
        $parent = $this->parent($operation, $vm);
        if ($parent->kind !== 'task_vm.destroy' || $this->provider->observe($vm) !== null) {
            throw new ResourceOperationException('operation.completion_unproven', 'Workspace cleanup requires the absent VM and its destruction parent.', 409);
        }
        $this->owner($operation, 'provider-delete', 'task_vm.'.$vm->provider->value.'_delete');
        $this->workspaces->executeProven($vm, $parent);

        return ['ready' => true];
    }

    private function parent(Operation $operation, TaskVm $vm): Operation
    {
        $parent = Operation::query()->find($operation->parent_operation_id);
        if ($parent === null || $parent->target_type !== 'task_vm' || $parent->target_id !== $vm->id) {
            throw new ResourceOperationException('operation.completion_unproven', 'The VM projection requires its owning parent.', 409);
        }

        return $parent;
    }

    private function owner(Operation $operation, string $key, string $kind): Operation
    {
        $parent = Operation::query()->find($operation->parent_operation_id);
        if ($parent === null || $parent->target_type !== 'task_vm' || $parent->target_id !== $operation->target_id) {
            throw new ResourceOperationException('operation.completion_unproven', 'The VM projection requires its owning parent.', 409);
        }
        $owner = app(OperationChildren::class)->requireSucceeded($parent, $key);
        if ($owner->kind !== $kind) {
            throw new ResourceOperationException('operation.completion_unproven', 'The VM projection requires its exact owning child.', 409);
        }

        return $owner;
    }
}
