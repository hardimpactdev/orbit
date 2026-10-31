<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmEnrollmentOwner;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Public hub participant admission. The exact hub worker owns the native effect. */
final readonly class NodeWireGuardInput
{
    /** @return array{schema_version:1,executor_node_id:int,hub_id:int,context_node_id:int,excluded_node_id:int|null,agent_sha256:string} */
    public function capture(Node $context, ?int $excludedNodeId = null): array
    {
        $hub = Node::query()->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Vpn))->sole();
        if ($hub->status !== LifecycleStatus::Active
            || ($excludedNodeId !== null && $excludedNodeId !== $context->id)) {
            throw new ResourceOperationException('node.network_unsupported', 'The hub requires its exact active Node worker.', 422);
        }

        return ['schema_version' => 1, 'executor_node_id' => $hub->id, 'hub_id' => $hub->id,
            'context_node_id' => $context->id, 'excluded_node_id' => $excludedNodeId,
            'agent_sha256' => app(AgentDelivery::class)->pin($hub)];
    }

    /** TaskVM parents use the existing Gateway SSH custody for a workerless hub.
     * @return array{schema_version:1,executor_node_id:int,hub_id:int,context_node_id:int,excluded_node_id:int|null,agent_sha256:string}
     */
    public function captureForTaskVm(Node $context, Node $gateway, ?int $excludedNodeId = null): array
    {
        if (! self::gateway($gateway->id)) {
            throw new ResourceOperationException('node.network_unsupported', 'Hub SSH execution requires the admitted active Gateway.', 422);
        }

        return [...$this->capture($context, $excludedNodeId), 'executor_node_id' => $gateway->id];
    }

    /** @param array{schema_version:1,executor_node_id:int,hub_id:int,context_node_id:int,excluded_node_id:int|null,agent_sha256:string} $cleanup
     * @return array{schema_version:1,executor_node_id:int,hub_id:int,context_node_id:int,excluded_node_id:null,agent_sha256:string}
     */
    public static function restorationSnapshot(array $cleanup): array
    {
        if ($cleanup['excluded_node_id'] !== null && $cleanup['excluded_node_id'] !== $cleanup['context_node_id']) {
            throw new ResourceOperationException('node.network_unsupported', 'Hub restoration requires its admitted context.', 422);
        }

        return [...$cleanup, 'excluded_node_id' => null];
    }

    /** Record from the owning root projection, after restoring the Node status in its transaction. */
    public static function beginRestoration(Operation $operation): void
    {
        DB::transaction(function () use ($operation): void {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            if ($operation->status->terminal()) {
                throw new ResourceOperationException('operation.finished', 'The owning hub Operation has ended.', 409);
            }
            $before = $operation->state ?? [];
            $operation->state = [...$before, 'node_wireguard_restoring' => true];
            if (self::bound($operation) === null) {
                $operation->state = $before;
                throw new ResourceOperationException('operation.execution_forbidden', 'Hub restoration has no matching admitted view.', 422);
            }
            $operation->save();
        });
    }

    /** @return array{schema_version:1,executor_node_id:int,hub_id:int,context_node_id:int,excluded_node_id:int|null,agent_sha256:string}|null */
    public static function bound(Operation $operation): ?array
    {
        $snapshot = $operation->input['_wireguard'] ?? null;
        $restoring = $operation->state['node_wireguard_restoring'] ?? false;
        if (! is_bool($restoring)) {
            return null;
        }
        if ($restoring) {
            $restore = $operation->input['_wireguard_restore'] ?? null;
            if (! in_array($operation->kind, ['node.remove', 'task_vm.destroy', 'node.add'], true)
                || ! is_array($snapshot) || ! is_array($restore)
                || ($snapshot['excluded_node_id'] ?? null) !== ($operation->kind === 'node.add' ? null : ($snapshot['context_node_id'] ?? null))
                || Arr::sortRecursive($restore) !== Arr::sortRecursive([...$snapshot, 'excluded_node_id' => null])
                || Node::query()->whereKey($snapshot['context_node_id'] ?? null)->where('status', LifecycleStatus::Removing)->exists()) {
                return null;
            }
            $snapshot = $restore;
        }
        if (! is_array($snapshot) || count($snapshot) !== 6 || ($snapshot['schema_version'] ?? null) !== 1
            || ! is_int($snapshot['executor_node_id'] ?? null)
            || ! is_int($snapshot['hub_id'] ?? null) || ! is_int($snapshot['context_node_id'] ?? null)
            || ! in_array($operation->kind, ['node.add', 'node.remove',
                'node.network_converge', 'node.wireguard_hub_converge', 'task_vm.destroy',
                'task_vm.enroll', 'task_vm.prepare_runtime', 'task_vm.provision', 'tasks.provision'], true)
            || ! array_key_exists('excluded_node_id', $snapshot)
            || ($snapshot['excluded_node_id'] !== null && $snapshot['excluded_node_id'] !== $snapshot['context_node_id'])
            || ! is_string($snapshot['agent_sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $snapshot['agent_sha256']) !== 1) {
            return null;
        }
        $hub = Node::query()->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Vpn))->sole();
        if ($hub->id !== $snapshot['hub_id']
            || $hub->status !== LifecycleStatus::Active
            || ! Node::query()->whereKey($snapshot['context_node_id'])->exists()) {
            return null;
        }

        $participants = $operation->input['participant_node_ids'] ?? [];
        $bindings = $operation->input['private_bindings'] ?? [];
        $binding = is_array($bindings) ? ($bindings['configuration'] ?? null) : null;
        $taskVmKind = in_array($operation->kind, ['task_vm.enroll', 'task_vm.prepare_runtime', 'task_vm.provision', 'tasks.provision'], true);
        $vm = $taskVmKind ? TaskVmEnrollmentOwner::owned($operation) : null;
        $taskVmContext = $operation->kind === 'task_vm.destroy'
            ? $operation->target_type === 'task_vm' && TaskVm::query()->whereKey($operation->target_id)->where('node_id', $snapshot['context_node_id'])->exists()
            : $vm !== null && $vm->node_id === $snapshot['context_node_id'] && $snapshot['excluded_node_id'] === null;
        $removal = $operation->input['_node_removal'] ?? null;
        $execution = $operation->input['_removal'] ?? null;
        $removalContext = $operation->kind === 'node.remove' && $operation->target_type === 'node' && is_array($removal) && is_array($execution)
            && $operation->node_id === $snapshot['executor_node_id'] && self::gateway($snapshot['executor_node_id'])
            && ($removal['node_id'] ?? null) === $operation->target_id
            && ($execution['snapshot'] ?? null) === $removal
            && ($execution[$restoring ? 'restore_hub' : 'hub'] ?? null) === $snapshot;
        if ($operation->kind === 'node.remove' && ! $removalContext) {
            return null;
        }
        $contextMatches = $taskVmKind || $operation->kind === 'task_vm.destroy'
            ? $taskVmContext
            : $operation->target_type === 'node' && $operation->target_id === $snapshot['context_node_id'];
        $enrollment = $operation->input['_enrollment'] ?? [];
        $enrollmentContext = is_array($enrollment) && $operation->kind === 'node.add' && $operation->target_type === 'node'
            && $operation->target_id === $snapshot['context_node_id'] && $operation->node_id === $snapshot['executor_node_id']
            && ($enrollment['node_id'] ?? null) === $snapshot['context_node_id']
            && ($enrollment['executor_node_id'] ?? null) === $snapshot['executor_node_id']
            && $snapshot['excluded_node_id'] === null;
        if ($snapshot['executor_node_id'] !== $snapshot['hub_id']
            && ((! $taskVmContext && ! $enrollmentContext && ! $removalContext) || ! self::gateway($snapshot['executor_node_id']))) {
            return null;
        }
        if (! $contextMatches || ! is_array($participants) || ! is_array($binding)
            || ! in_array($snapshot['context_node_id'], $participants, true) || ! in_array($snapshot['hub_id'], $participants, true)
            || ! in_array($snapshot['executor_node_id'], $participants, true)
            || ($binding['owner_node_id'] ?? null) !== $snapshot['hub_id']
            || ($binding['read_node_ids'] ?? null) !== [$snapshot['executor_node_id']]
            || ($binding['write_node_ids'] ?? null) !== []) {
            return null;
        }

        return $snapshot;
    }

    private static function gateway(int $id): bool
    {
        return Node::query()->whereKey($id)->where('status', LifecycleStatus::Active)
            ->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))->exists();
    }
}
