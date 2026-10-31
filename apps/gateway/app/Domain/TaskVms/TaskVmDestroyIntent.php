<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Nodes\NodeRemovalInput;
use App\Domain\Nodes\NodeRolePlacement;
use App\Domain\Nodes\NodeWireGuardInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskFinalReview;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Route;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use LogicException;

/** Records destruction scope. It never reads power, stops a worker or deletes a resource. */
final readonly class TaskVmDestroyIntent
{
    public function __construct(private TaskVmSettings $settings, private AgentDelivery $agents, private TaskVmNodeRemovalPlan $nodes, private ModelKeyIssuer $modelKeys) {}

    /** @return array<string,mixed> */
    public function capture(TaskVm $vm): array
    {
        $this->preserved($vm);
        $host = $vm->provider === TaskVmProviderKind::Incus ? Node::query()->findOrFail($vm->host_node_id)
            : Node::query()->where('status', LifecycleStatus::Active)->whereHas('roles', static fn ($q) => $q->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))->firstOrFail();
        $location = $vm->provider === TaskVmProviderKind::Incus ? $this->settings->host($host->id) : null;
        $gateway = Node::query()->where('status', LifecycleStatus::Active)->whereHas('roles', static fn ($q) => $q->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))->firstOrFail();
        $issuerId = $vm->node_id === null && $vm->group_id === null && ! $vm->worker_stop_required ? $host->id : filter_var(config('dependency_detection.services_node_id'), FILTER_VALIDATE_INT);
        if (! is_int($issuerId) || ! Node::query()->whereKey($issuerId)->where('status', LifecycleStatus::Active)->exists()) {
            throw new ResourceOperationException('operation.kind_unavailable', 'The existing worker issuer is unavailable.', 409);
        }
        if ($vm->model_key !== null && $vm->node_id === null) {
            throw new ResourceOperationException('operation.kind_unavailable', 'Model-key cleanup requires its recorded guest owner.', 409);
        }
        $routerId = $vm->model_key === null ? null : $this->modelKeys->ownerNodeId();
        $nodeRemoval = $vm->node_id === null ? null : $this->nodes->deletedTaskVm($vm, Node::query()->findOrFail($vm->node_id));
        $workspaceIds = $this->workspaces($vm);
        $routeIds = $this->routes($vm);
        $this->available(['node_removal' => $nodeRemoval, 'workspace_ids' => $workspaceIds, 'route_ids' => $routeIds]);
        $cleanup = $nodeRemoval === null ? null : app(NodeRemovalInput::class)->capture($nodeRemoval, $gateway);
        $nodeRemoval = $cleanup === null ? null : ValidatedData::object($cleanup['snapshot']);
        $cleanupNodeIds = $nodeRemoval === null ? [] : $this->cleanupNodeIds($nodeRemoval);
        $executionNodeIds = array_values(array_unique([$host->id, $gateway->id, $issuerId, ...$cleanupNodeIds]));
        if (Node::query()->whereKey($executionNodeIds)->get()->contains(static fn (Node $node): bool => ! app(NodeRolePlacement::class)->allows($node))
            || ($cleanup !== null && ($cleanup['metrics_fleet'] !== null || $cleanup['restore_metrics_fleet'] !== null))) {
            throw new ResourceOperationException('operation.kind_unavailable', 'Every required Node cleanup effect needs its native executor and contributor.', 409);
        }
        $wireguard = $nodeRemoval === null || $nodeRemoval['hub_id'] === null ? null
            : app(NodeWireGuardInput::class)->captureForTaskVm(Node::query()->findOrFail($vm->node_id), $gateway, $vm->node_id);
        $hubId = $wireguard['hub_id'] ?? null;

        $input = ['removal_id' => (string) Str::uuid(), 'task_vm_id' => $vm->id, 'node_id' => $host->id,
            'guest_node_id' => $vm->node_id, 'group_id' => $vm->group_id, 'name' => $vm->name,
            'guest_user' => $vm->node?->user, 'model_origin' => $vm->ai_router_origin,
            'gateway_node_id' => $gateway->id, 'issuer_node_id' => $issuerId, 'model_router_node_id' => $routerId,
            'wireguard_ip' => $vm->wireguard_ip, 'provider' => $vm->provider->value, 'provider_id' => $vm->provider_id,
            'agent_sha256' => $this->agents->pin($host), 'deadline_at' => now()->addSeconds(1500)->timestamp,
            'worker_stop_required' => (bool) $vm->worker_stop_required, 'model_key_required' => $vm->model_key !== null,
            'workspace_ids' => $workspaceIds, 'route_ids' => $routeIds,
            'node_removal' => $nodeRemoval, 'proxy_node_ids' => app(NodeRolePlacement::class)->proxyNodeIds(),
            '_wireguard' => $wireguard, '_wireguard_restore' => $wireguard === null ? null : NodeWireGuardInput::restorationSnapshot($wireguard),
            'node_placements' => array_map(static fn (int $nodeId): array => ['effect_node_id' => $nodeId, 'execution_node_id' => $nodeId, 'transport' => 'local'], $cleanupNodeIds),
            'vm' => $location === null ? null : ['project' => $location->project, 'name' => $vm->name, 'cidr' => $location->cidr],
            'pool' => $location?->pool,
            'participant_node_ids' => array_values(array_unique(array_filter([$host->id, $vm->node_id, $gateway->id, $issuerId, $routerId, $hubId, ...$cleanupNodeIds]))),
            'private_bindings' => [
                'configuration' => $hubId === null ? ['owner_node_id' => $gateway->id, 'read_node_ids' => [], 'write_node_ids' => []]
                    : ['owner_node_id' => $hubId, 'read_node_ids' => [$gateway->id], 'write_node_ids' => []],
                'upcloud_token' => ['owner_node_id' => $host->id,
                    'read_node_ids' => $vm->provider === TaskVmProviderKind::UpCloud ? [$host->id] : [], 'write_node_ids' => []],
                'model_key' => ['owner_node_id' => $vm->node_id ?? $host->id,
                    'read_node_ids' => $vm->model_key === null ? [] : [$gateway->id], 'write_node_ids' => []],
                'model_router' => ['owner_node_id' => $routerId ?? $host->id,
                    'read_node_ids' => $vm->model_key === null ? [] : [$gateway->id], 'write_node_ids' => []],
            ],
            ...($hubId === null ? [] : ['configuration' => ['$secret' => 'node.vpn_configuration', 'id' => $hubId]]),
            ...($vm->model_key === null ? [] : ['model_key' => ['$secret' => 'task_vm.model_key', 'id' => $vm->id], 'model_router' => ['$secret' => 'task_vm.model_router', 'id' => $vm->id]]),
            ...($vm->provider === TaskVmProviderKind::UpCloud ? ['upcloud_token' => ['$secret' => 'upcloud.api', 'id' => $vm->id]] : [])];
        $this->available($input);

        return $input;
    }

    public function owned(Operation $operation, bool $lock = false): TaskVm
    {
        $input = $operation->input;
        if ($operation->kind !== 'task_vm.destroy' || $operation->target_type !== 'task_vm'
            || ($input['task_vm_id'] ?? null) !== $operation->target_id || ($input['node_id'] ?? null) !== $operation->node_id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The destruction no longer owns its admitted VM.', 422);
        }

        return $this->ownedInput($input, $lock);
    }

    /** The same canonical VM ownership check for a truthful Task parent root.
     * @param  array<string,mixed>  $input
     */
    public function ownedInput(array $input, bool $lock = false): TaskVm
    {
        $vm = TaskVm::query()->when($lock, static fn ($q) => $q->lockForUpdate())->findOrFail(ValidatedData::integer($input['task_vm_id']));
        if (($input['guest_node_id'] ?? null) !== $vm->node_id || ($input['group_id'] ?? null) !== $vm->group_id
            || ($input['guest_user'] ?? null) !== $vm->node?->user || ($input['model_origin'] ?? null) !== $vm->ai_router_origin
            || ($input['name'] ?? null) !== $vm->name || ($input['wireguard_ip'] ?? null) !== $vm->wireguard_ip
            || ($input['provider'] ?? null) !== $vm->provider->value || ($input['provider_id'] ?? null) !== $vm->provider_id
            || ($vm->provider === TaskVmProviderKind::Incus && $vm->host_node_id !== ($input['node_id'] ?? null))
            || ($input['worker_stop_required'] ?? null) !== $vm->worker_stop_required
            || ($input['model_key_required'] ?? null) !== ($vm->model_key !== null)
            || ($input['workspace_ids'] ?? null) !== $this->workspaces($vm) || ($input['route_ids'] ?? null) !== $this->routes($vm)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The destruction no longer owns its admitted VM and cleanup scope.', 422);
        }
        $this->preserved($vm);

        return $vm;
    }

    /** @param array<string,mixed> $input */
    public function available(array $input): void
    {
        $nodeRemoval = $input['node_removal'] === null ? null : ValidatedData::object($input['node_removal']);
        if ($input['workspace_ids'] !== [] || $input['route_ids'] !== [] || ($nodeRemoval !== null && ($nodeRemoval['route_ids'] !== [] || $nodeRemoval['metrics'] !== null))) {
            throw new ResourceOperationException('operation.kind_unavailable', 'Same-root workspace and Route cleanup participants are not installed.', 409);
        }
    }

    /** @return array<string,mixed> */
    public function input(Operation $operation): array
    {
        if (! $operation->status->terminal()) {
            $this->owned($operation);
        }

        return Arr::except($operation->input, ['upcloud_token', 'model_key', 'model_router', 'configuration', 'private_bindings']);
    }

    private function preserved(TaskVm $vm): void
    {
        if ($vm->group !== null && TaskFinalReview::unpublishedRemovalCommit($vm->group) !== null) {
            throw new ResourceOperationException('task_vm.workspace_not_preserved', 'Publish the approved Task work before removing its VM.', 409);
        }
    }

    /** @param array<string,mixed> $snapshot
     * @return list<int>
     */
    private function cleanupNodeIds(array $snapshot): array
    {
        $participants = $snapshot['participants'];
        if (! is_array($participants) || ! array_is_list($participants)) {
            throw new LogicException('The Node cleanup capture must include every participant.');
        }
        $ids = array_map(static fn (mixed $row): int => ValidatedData::integer(ValidatedData::object($row)['node_id']), $participants);

        return array_values(array_unique($ids));
    }

    /** @return list<int> */
    private function workspaces(TaskVm $vm): array
    {
        return $vm->node_id === null ? [] : array_values(Instance::query()->where('node_id', $vm->node_id)->orderBy('id')->get()
            ->map(static fn (Instance $instance): int => $instance->id)->all());
    }

    /** @return list<int> */
    private function routes(TaskVm $vm): array
    {
        return $vm->node_id === null ? [] : array_values(Route::query()->whereHas('targets.instance', static fn ($q) => $q->where('node_id', $vm->node_id))->orderBy('id')->get()
            ->map(static fn (Route $route): int => $route->id)->all());
    }
}
