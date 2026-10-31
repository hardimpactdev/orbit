<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmCapabilities;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;
use Illuminate\Support\Facades\DB;

/** Lifecycle admission. All provider calls, waits, enrollment and teardown run under Swamp. */
final readonly class StartTaskVmLifecycleOperationAction
{
    public function __construct(private StartOperationAction $start, private TaskVmSettings $settings, private SshKeyProvider $keys) {}

    public function execute(int $taskVmId, string $verb): ?Operation
    {
        if ($verb === 'destroy') {
            return app(StartTaskVmDestroyAction::class)->execute($taskVmId);
        }
        TaskVmCapabilities::require('children');
        TaskVmCapabilities::require('receipts');
        if (in_array($verb, ['provision', 'enroll'], true)) {
            TaskVmCapabilities::requireComposition('task_vm.'.$verb, ['node.add']);
            TaskVmCapabilities::requireComposition('task_vm.prepare_runtime', ['process.create']);
        }
        if (! in_array($verb, ['provision', 'enroll', 'resume'], true)) {
            throw new TaskVmException('task_vm.invalid_effect', 'The task VM lifecycle intent is not supported.');
        }

        return DB::transaction(function () use ($taskVmId, $verb): ?Operation {
            $vm = TaskVm::query()->find($taskVmId);
            if ($vm === null || $vm->state === TaskVmState::Destroyed
                || (in_array($verb, ['provision', 'enroll'], true) && $vm->state !== TaskVmState::Provisioning)
                || ($verb === 'resume' && $vm->state !== TaskVmState::Parked)) {
                return null;
            }
            if ($verb === 'provision' && $vm->node_id !== null) {
                $verb = 'enroll';
            }
            $kind = 'task_vm.'.$verb;
            $existing = Operation::query()->where('target_type', 'task_vm')->where('target_id', $vm->id)
                ->whereNotIn('status', ['succeeded', 'failed', 'cancelled'])->first();
            if ($existing?->kind === $kind) {
                return $existing;
            }
            $gateway = Node::query()->where('status', LifecycleStatus::Active)
                ->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))->first()
                ?? throw new TaskVmException('task_vm.job_failed', 'Task VM operations require an active Gateway Node.', 500);
            $input = ['task_vm_id' => $vm->id, 'provider' => $vm->provider->value, 'host_node_id' => $vm->host_node_id,
                'execution_node_id' => $vm->host_node_id ?? $gateway->id,
                'variant' => $vm->provider->value.($vm->model_key === null ? '-no-key' : '-key'),
                'gateway_node_id' => $gateway->id, 'guest_node_id' => $vm->node_id,
                'deadline_at' => now()->addSeconds($verb === 'resume' ? 540 : 1500)->getTimestamp(),
                'token' => ['$secret' => 'upcloud.api', 'id' => $vm->id],
                'origin' => $vm->ai_router_origin, 'key' => $vm->model_key === null ? null : ['$secret' => 'task_vm.model_key', 'id' => $vm->id]];
            if ($vm->provider === TaskVmProviderKind::Incus) {
                $host = $this->settings->host((int) $vm->host_node_id);
                $resources = $vm->size->resources();
                $input['vm'] = ['project' => $host->project, 'name' => $vm->name, 'cidr' => $host->cidr];
                $input['create'] = ['kind' => 'create', 'image' => $vm->image_fingerprint ?? $host->imageAlias,
                    'expected_fingerprint' => $vm->recipe_identity === null ? null : $vm->image_fingerprint,
                    'base_alias' => $host->imageAlias, 'gateway_public_key' => $this->keys->publicKey(),
                    'cpus' => $resources->cpusOn($host), 'memory' => $resources->memoryOn($host), 'disk' => $resources->diskOn($host),
                    'network' => $host->network, 'pool' => $host->pool, 'topology' => $resources->testNodes > 0];
            }
            if ($verb === 'resume') {
                $vm->update(['stop_confirmed_at' => null, ...TaskVm::ForgetTopology]);
            }

            $operation = $this->start->execute($kind, 'task_vm', $vm->id, $vm->host_node_id ?? $gateway->id, $input, 'task-vm', null);

            return $operation;
        });
    }
}
