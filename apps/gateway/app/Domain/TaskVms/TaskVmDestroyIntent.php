<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Nodes\NodeRemovalInput;
use App\Domain\Nodes\NodeRemovalPlan;
use App\Domain\Nodes\NodeRemovalPolicy;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Routes\NativeRouteLifecycleInput;
use App\Domain\Routes\NativeRouteRemovalIntent;
use App\Domain\Routes\RouteExecutionProof;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskFinalReview;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;
use Illuminate\Database\Eloquent\Collection;

/** Immutable same-VM cleanup intent; no provider or guest effect at admission. */
final readonly class TaskVmDestroyIntent
{
    public function __construct(private TaskVmProviderWorkflowInput $provider, private TaskVmModelKeyInputs $models, private SwampCommandStep $shared) {}

    /** @return array<string,mixed> */
    public function capture(TaskVm $vm): array
    {
        return $this->captureCleanup($vm);
    }

    /** The owning Tasks parent removes this workspace before executing the destroy child.
     * @return array<string,mixed>
     */
    public function captureForWorkspace(TaskVm $vm, Instance $ownedInstance): array
    {
        $workspace = Instance::query()->findOrFail($ownedInstance->id);
        if ($vm->node_id === null || $workspace->node_id !== $vm->node_id) {
            throw new ResourceOperationException('task_vm.workspace_mismatch', 'Cleanup requires the workspace on its exact VM Node.', 409);
        }

        return [...$this->captureCleanup($vm, $workspace), 'instance_id' => $workspace->id,
            'workspace_routed' => $workspace->task_workspace_routed,
            'project_id' => $workspace->project_id, 'checkout_path' => $workspace->checkout_path];
    }

    /** Read-only revalidation of the admitted parent before dispatch.
     * @param  array<string,mixed>  $captured
     */
    public function ownedForWorkspace(TaskVm $vm, ?Instance $instance, array $captured, int $operationId): TaskVm
    {
        $vm = TaskVm::query()->findOrFail($vm->id);
        $workspace = $instance === null ? null : Instance::query()->findOrFail($instance->id);
        $operation = Operation::query()->find($operationId);
        $this->assertCaptured($vm, $captured);
        if (! $operation instanceof Operation || $operation->status->terminal()
            || ! in_array($operation->kind, ['tasks.cancel', 'tasks.maintenance_finish'], true)
            || $operation->target_type !== 'task_group' || $operation->target_id !== $vm->group_id
            || $operation->node_id !== ($captured['node_id'] ?? null)
            || ($captured['instance_id'] ?? null) !== $workspace?->id
            || ($workspace !== null && ($captured['project_id'] ?? null) !== $workspace->project_id)
            || ($captured['checkout_path'] ?? null) !== $workspace?->checkout_path
            || ($captured['workspace_routed'] ?? null) !== $workspace?->task_workspace_routed
            || $vm->group_id !== $operation->target_id
            || ($workspace !== null && ($vm->node_id === null || $workspace->node_id !== $vm->node_id))) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The ending parent no longer owns its admitted VM and workspace.', 422);
        }
        if ($this->executor($vm)->id !== $operation->node_id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The ending parent no longer owns its admitted provider executor.', 422);
        }
        $this->guestIdentity($vm);
        $this->preserved($vm);
        $this->available($vm, $workspace, $operation);
        $routes = $workspace?->routes()->orderBy('routes.id')->get() ?? new Collection;
        $capturedRoutes = RouteExecutionProof::objects($captured['workspace_routes'] ?? []);
        if ($routes->modelKeys() !== array_column($capturedRoutes, 'route_id')) {
            throw new ResourceOperationException('operation.execution_forbidden', 'Workspace Routes changed after cleanup admission.', 422);
        }
        foreach ($routes as $index => $route) {
            if ($route->domain !== ($capturedRoutes[$index]['domain'] ?? null)) {
                throw new ResourceOperationException('operation.execution_forbidden', 'Workspace Route identity changed after admission.', 422);
            }
        }

        return $vm;
    }

    /** @return array<string,mixed> */
    private function captureCleanup(TaskVm $vm, ?Instance $workspace = null): array
    {
        $this->preserved($vm);
        $this->available($vm, $workspace);
        $host = $this->executor($vm);
        $this->guestIdentity($vm);
        $node = $vm->node;
        $workspaceRoutes = [];
        if ($workspace !== null && $node instanceof Node) {
            $routeIds = app(NodeRemovalPolicy::class)->workspaceRouteIds($node, $workspace);
            foreach ($workspace->routes()->whereIn('routes.id', $routeIds)->orderBy('routes.id')->get() as $route) {
                $capture = app(NativeRouteLifecycleInput::class)->capture($route, [], removing: true, deletedWorkloadNodeId: $node->id, restorable: false);
                if ($capture['execution_secrets'] !== []) {
                    throw new \LogicException('Deleted workspace withdrawal must not write guest environment material.');
                }
                $workspaceRoutes[] = [...$capture['intent'], 'domain' => $route->domain];
                app(NativeRouteRemovalIntent::class)->beginWithdrawal($route);
            }
        }
        $snapshot = $node instanceof Node ? app(NodeRemovalPlan::class)->build($node, offline: true, machineDeleted: true) : null;
        $removal = $snapshot === null ? null : app(NodeRemovalInput::class)->capture($snapshot, $workspace);
        $model = null;
        if ($vm->model_key !== null) {
            $gateway = Node::query()->where('status', LifecycleStatus::Active)
                ->whereHas('roles', static fn ($q) => $q->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))->sole();
            $model = $this->models->capture($vm, $gateway->id, $this->shared->executionCopy(...));
        }
        $provider = $this->provider->capture($vm);

        return ['task_vm_id' => $vm->id, 'node_id' => $host->id, 'guest_node_id' => $vm->node_id,
            'workspace_routes' => $workspaceRoutes, 'group_id' => $vm->group_id, 'name' => $vm->name, 'wireguard_ip' => $vm->wireguard_ip,
            'provider' => $vm->provider->value, 'provider_id' => $vm->provider_id, 'model_key_required' => $vm->model_key !== null,
            'model_intent' => $model['intent'] ?? null, 'node_removal' => $snapshot, 'removal' => $removal['intent'] ?? null,
            ...$provider, 'execution_secrets' => [...$provider['execution_secrets'], ...($model['execution_secrets'] ?? [])]];
    }

    public function owned(Operation $operation, bool $lock = false): TaskVm
    {
        $vm = TaskVm::query()->when($lock, static fn ($q) => $q->lockForUpdate())->findOrFail($operation->target_id);
        $input = $operation->input;
        if ($operation->kind !== 'task_vm.destroy' || $operation->target_type !== 'task_vm'
            || ($input['node_id'] ?? null) !== $operation->node_id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'Destroy requires its admitted standalone Operation.', 422);
        }
        $this->assertCaptured($vm, $input);
        $this->preserved($vm);
        $this->available($vm, ownOperation: $operation);

        return $vm;
    }

    /** Same captured cleanup identity, independent of the owning parent's Operation kind.
     * @param  array<string,mixed>  $input
     */
    public function assertCaptured(TaskVm $vm, array $input): void
    {
        if (($input['task_vm_id'] ?? null) !== $vm->id
            || ($vm->provider === TaskVmProviderKind::Incus && ($input['node_id'] ?? null) !== $vm->host_node_id)
            || ($input['guest_node_id'] ?? null) !== $vm->node_id || ($input['group_id'] ?? null) !== $vm->group_id
            || ($input['name'] ?? null) !== $vm->name || ($input['wireguard_ip'] ?? null) !== $vm->wireguard_ip
            || ($input['provider'] ?? null) !== $vm->provider->value || ($input['provider_id'] ?? null) !== $vm->provider_id
            || ($input['model_key_required'] ?? null) !== ($vm->model_key !== null)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'Destroy no longer owns its admitted VM, provider and cleanup scope.', 422);
        }
    }

    /** Final projection runs under its parent's terminal lock; the owning group root is still active. */
    public function assertRemovedWorkspace(TaskVm $vm): void
    {
        $this->preserved($vm);
        $this->resources($vm);
    }

    /** @return array<string,mixed> */
    public function input(Operation $operation): array
    {
        $this->owned($operation);

        return TaskVmProviderWorkflowInput::envelope($operation->input);
    }

    private function executor(TaskVm $vm): Node
    {
        $host = $vm->provider === TaskVmProviderKind::Incus ? Node::query()->findOrFail($vm->host_node_id) : Node::query()->where('status', LifecycleStatus::Active)
            ->whereHas('roles', static fn ($q) => $q->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))->sole();
        if ($host->status !== LifecycleStatus::Active || $vm->name !== 'tvm-'.$vm->id) {
            throw new ResourceOperationException('task_vm.destroy_refused', 'Destroy requires its exact VM and active provider executor.', 409);
        }

        return $host;
    }

    private function guestIdentity(TaskVm $vm): void
    {
        $node = $vm->node;
        if ($vm->node_id !== null && (! $node instanceof Node || $node->name !== $vm->name || $node->wireguard_ip !== $vm->wireguard_ip
            || TaskVm::query()->where('node_id', $node->id)->whereKeyNot($vm->id)->exists())) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The cleanup no longer owns its guest Node.', 422);
        }
    }

    private function preserved(TaskVm $vm): void
    {
        if ($vm->group !== null && TaskFinalReview::unpublishedRemovalCommit($vm->group) !== null) {
            throw new ResourceOperationException('task_vm.workspace_not_preserved', 'Publish the approved Task work before removing its VM.', 409);
        }
    }

    private function available(TaskVm $vm, ?Instance $workspace = null, ?Operation $ownOperation = null): void
    {
        $operations = Operation::query()->whereNotIn('status', OperationStatus::terminalValues())
            ->where(static fn ($q) => $q->where(static fn ($q) => $q->where('target_type', 'task_vm')->where('target_id', $vm->id))
                ->orWhere(static fn ($q) => $q->where('target_type', 'node')->where('target_id', $vm->node_id))
                ->when($vm->group_id !== null, static fn ($q) => $q->orWhere(static fn ($q) => $q->where('target_type', 'task_group')->where('target_id', $vm->group_id))));
        if ($ownOperation !== null) {
            $operations->whereKeyNot($ownOperation->id);
        }
        if ($operations->exists()) {
            throw new ResourceOperationException('operation.target_busy', 'The VM, guest Node or task group Operation must settle before deleting this VM.', 409);
        }
        $this->resources($vm, $workspace);
    }

    private function resources(TaskVm $vm, ?Instance $workspace = null): void
    {
        if ($vm->node instanceof Node) {
            app(NodeRemovalPolicy::class)->protected($vm->node, $vm->provider === TaskVmProviderKind::Incus ? Node::query()->findOrFail($vm->host_node_id) : Node::query()->where('status', LifecycleStatus::Active)->whereHas('roles', static fn ($q) => $q->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))->sole(), $workspace);
            app(NodeRemovalPolicy::class)->resources($vm->node, offline: true, removingWorkspace: $workspace);
        }
    }
}
