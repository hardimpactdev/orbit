<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Data\Nodes\ProvisionNodeData;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmFamilyChildren;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Reserves Gateway identity and exact owning input; it performs no Node enrollment. */
final readonly class PrepareTaskVmEnrollmentAction
{
    public function __construct(private TaskVmProvider $provider, private TaskVmSettings $settings, private OperationStore $store) {}

    /** @return array{ready:bool,node_id:int,enrolled:bool} */
    public function execute(Operation $effect, TaskVm $vm): array
    {
        return DB::transaction(function () use ($effect, $vm): array {
            $parent = Operation::query()->lockForUpdate()->find($effect->parent_operation_id);
            $vm = TaskVm::query()->lockForUpdate()->findOrFail($vm->id);
            if ($parent === null || ! in_array($parent->kind, ['task_vm.provision', 'task_vm.enroll'], true)
                || $parent->target_type !== 'task_vm' || $parent->target_id !== $vm->id || $vm->state !== TaskVmState::Provisioning) {
                throw new ResourceOperationException('operation.completion_unproven', 'Enrollment preparation requires its provisioning parent.', 409);
            }
            $saved = $parent->state['enrollment'] ?? null;
            if (is_array($saved)) {
                if ($vm->node_id !== $saved['node_id'] || $vm->node?->name !== $vm->name) {
                    throw new TaskVmException('task_vm.node_conflict', 'The reserved VM Node changed.', 409);
                }

                return ['ready' => true, 'node_id' => ValidatedData::integer($vm->node_id), 'enrolled' => $saved['enrolled'] === true];
            }
            if ($parent->kind === 'task_vm.provision') {
                $created = app(OperationChildren::class)->requireSucceeded($parent, 'provider-create');
                if ($created->kind !== 'task_vm.'.$vm->provider->value.'_create') {
                    throw new ResourceOperationException('operation.completion_unproven', 'The VM allocation has no exact child proof.', 409);
                }
                if ($vm->provider->value === 'upcloud') {
                    app(OperationChildren::class)->requireSucceeded($parent, 'provider-start');
                }
            }
            $observation = $this->provider->observe($vm);
            if ($observation === null || ! $observation->running || ! $observation->settled || $observation->address === null
                || ! $this->provider->bootstrapReady($vm)) {
                throw new TaskVmException('task_vm.not_settled', 'The VM has no settled bootstrapped address.', 409);
            }
            if ($vm->recipe_identity !== null && $observation->imageFingerprint !== $vm->image_fingerprint) {
                throw new TaskVmException('task_vm.image_mismatch', 'The VM did not launch its recorded recipe image.', 409);
            }
            $node = $vm->node_id === null ? null : Node::query()->lockForUpdate()->findOrFail($vm->node_id);
            if (($node !== null && $node->name !== $vm->name)
                || ($node !== null && TaskVm::query()->where('node_id', $node->id)->whereKeyNot($vm->id)->exists())
                || ($node === null && Node::query()->where('name', $vm->name)->exists())) {
                throw new TaskVmException('task_vm.node_conflict', 'The VM name or Node belongs to another enrollment.', 409);
            }
            $enrolled = $node?->status === LifecycleStatus::Active;
            $data = new ProvisionNodeData(name: $vm->name, publicSshHost: $observation->address, roles: [RoleName::AppDev],
                user: 'orbit', orbitUser: 'orbit', wireguardIp: $vm->wireguard_ip,
                expectedSshHostFingerprint: $this->provider->sshHostFingerprint($vm), clusterProvided: true,
                clusterId: $this->settings->devClusterId, sshJumpNodeId: $vm->host_node_id, taskVmId: $vm->id);
            $node ??= Node::query()->create(['name' => $vm->name, 'status' => LifecycleStatus::Provisioning, 'platform' => 'linux',
                'user' => 'orbit', 'public_ssh_host' => $observation->address, 'wireguard_ip' => $vm->wireguard_ip,
                'ssh_jump_node_id' => $vm->host_node_id]);
            $node->update(['ssh_jump_node_id' => $vm->host_node_id]);
            $vm->update(['node_id' => $node->id, 'address' => $observation->address,
                ...($observation->imageFingerprint === null ? [] : ['image_fingerprint' => $observation->imageFingerprint])]);
            $intents = app(TaskVmFamilyChildren::class)->nodeIntents($parent);
            if (! $enrolled) {
                $intents[] = ['key' => 'node-enrollment', 'kind' => 'node.add', 'node_id' => $node->id, 'input' => $data->toArray()];
            }
            $this->store->change($parent, ['state' => [...($parent->state ?? []), 'child_intents' => $intents,
                'enrollment' => ['node_id' => $node->id, 'enrolled' => $enrolled]]]);

            return ['ready' => true, 'node_id' => $node->id, 'enrolled' => $enrolled];
        });
    }
}
