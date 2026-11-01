<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Nodes\NodeRemovalInput;
use App\Domain\Nodes\NodeRemovalPlan;
use App\Domain\Nodes\NodeRemovalPolicy;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskFinalReview;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Route;
use App\Models\TaskVm;

/** Immutable same-VM cleanup intent; no provider or guest effect at admission. */
final readonly class TaskVmDestroyIntent
{
    public function __construct(private TaskVmProviderWorkflowInput $provider, private TaskVmModelKeyInputs $models, private SwampCommandStep $shared) {}

    /** @return array<string,mixed> */
    public function capture(TaskVm $vm): array
    {
        if ($vm->provider !== TaskVmProviderKind::Incus) {
            throw new ResourceOperationException('operation.kind_unavailable', 'UpCloud destruction requires its native cleanup follow-up.', 503);
        }
        $this->preserved($vm);
        $this->available($vm);
        $host = Node::query()->findOrFail($vm->host_node_id);
        if ($host->status !== LifecycleStatus::Active || $vm->name !== 'tvm-'.$vm->id) {
            throw new ResourceOperationException('task_vm.destroy_refused', 'Destroy requires its exact VM and active Incus host.', 409);
        }
        $node = $vm->node;
        if ($vm->node_id !== null && (! $node instanceof Node || $node->name !== $vm->name || $node->wireguard_ip !== $vm->wireguard_ip
            || TaskVm::query()->where('node_id', $node->id)->whereKeyNot($vm->id)->exists())) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The cleanup no longer owns its guest Node.', 422);
        }
        $snapshot = $node instanceof Node ? app(NodeRemovalPlan::class)->build($node, offline: true, machineDeleted: true) : null;
        $removal = $snapshot === null ? null : app(NodeRemovalInput::class)->capture($snapshot);
        $model = null;
        if ($vm->model_key !== null) {
            $gateway = Node::query()->where('status', LifecycleStatus::Active)
                ->whereHas('roles', static fn ($q) => $q->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))->sole();
            $model = $this->models->capture($vm, $gateway->id, $this->shared->executionCopy(...));
        }
        $provider = $this->provider->capture($vm);

        return ['task_vm_id' => $vm->id, 'node_id' => $host->id, 'guest_node_id' => $vm->node_id,
            'group_id' => $vm->group_id, 'name' => $vm->name, 'wireguard_ip' => $vm->wireguard_ip,
            'provider' => $vm->provider->value, 'provider_id' => $vm->provider_id, 'model_key_required' => $vm->model_key !== null,
            'model_intent' => $model['intent'] ?? null, 'node_removal' => $snapshot, 'removal' => $removal['intent'] ?? null,
            ...$provider, 'execution_secrets' => $model['execution_secrets'] ?? []];
    }

    public function owned(Operation $operation, bool $lock = false): TaskVm
    {
        $vm = TaskVm::query()->when($lock, static fn ($q) => $q->lockForUpdate())->findOrFail($operation->target_id);
        $input = $operation->input;
        if ($operation->kind !== 'task_vm.destroy' || $operation->target_type !== 'task_vm'
            || ($input['task_vm_id'] ?? null) !== $vm->id || ($input['node_id'] ?? null) !== $operation->node_id
            || $vm->host_node_id !== $operation->node_id || ($input['guest_node_id'] ?? null) !== $vm->node_id
            || ($input['group_id'] ?? null) !== $vm->group_id || ($input['name'] ?? null) !== $vm->name
            || ($input['wireguard_ip'] ?? null) !== $vm->wireguard_ip || ($input['provider'] ?? null) !== $vm->provider->value
            || ($input['provider_id'] ?? null) !== $vm->provider_id || ($input['model_key_required'] ?? null) !== ($vm->model_key !== null)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'Destroy no longer owns its admitted VM, provider and cleanup scope.', 422);
        }
        $this->preserved($vm);
        $this->available($vm);

        return $vm;
    }

    /** @return array<string,mixed> */
    public function input(Operation $operation): array
    {
        $this->owned($operation);

        return TaskVmProviderWorkflowInput::envelope($operation->input);
    }

    private function preserved(TaskVm $vm): void
    {
        if ($vm->group !== null && TaskFinalReview::unpublishedRemovalCommit($vm->group) !== null) {
            throw new ResourceOperationException('task_vm.workspace_not_preserved', 'Publish the approved Task work before removing its VM.', 409);
        }
    }

    private function available(TaskVm $vm): void
    {
        if (Operation::query()->whereNotIn('status', OperationStatus::terminalValues())
            ->where(static fn ($q) => $q->where(static fn ($q) => $q->where('target_type', 'node')->where('target_id', $vm->node_id))
                ->when($vm->group_id !== null, static fn ($q) => $q->orWhere(static fn ($q) => $q->where('target_type', 'task_group')->where('target_id', $vm->group_id))))->exists()) {
            throw new ResourceOperationException('operation.target_busy', 'The guest Node or task group Operation must settle before deleting this VM.', 409);
        }
        if ($vm->node instanceof Node) {
            app(NodeRemovalPolicy::class)->protected($vm->node, Node::query()->findOrFail($vm->host_node_id));
            app(NodeRemovalPolicy::class)->resources($vm->node, offline: true);
        }
        if ($vm->node_id !== null && (Instance::query()->where('node_id', $vm->node_id)->exists()
            || Route::query()->where(static fn ($q) => $q->where('node_id', $vm->node_id)->orWhere('generation_basis_node_id', $vm->node_id)
                ->orWhere('transition_node_id', $vm->node_id)->orWhereHas('targets.instance', static fn ($q) => $q->where('node_id', $vm->node_id)))->exists())) {
            throw new ResourceOperationException('operation.kind_unavailable', 'Remove the owning workspace and Route through their native parent before deleting this VM.', 409);
        }
    }
}
