<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Data\Nodes\ProvisionNodeData;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;

/** Only the owning VM composition may attach a reserved Node or assert machine deletion. */
final readonly class NodeTaskVmLifecycleOwnership
{
    public function __construct(private OperationIdempotency $idempotency, private OperationChildren $children) {}

    public function enrollment(Node $node, ProvisionNodeData $data): void
    {
        $vm = $this->owner($node, 'node.add', ['task_vm.provision', 'task_vm.enroll']);
        if ($vm->state !== TaskVmState::Provisioning || ! in_array($node->status, [LifecycleStatus::Provisioning, LifecycleStatus::Failed], true)
            || $data->taskVmId !== $vm->id || $data->name !== $vm->name || $node->platform !== 'linux'
            || $data->roles !== [RoleName::AppDev] || $data->platform !== 'linux' || $data->user !== 'orbit' || $data->orbitUser !== 'orbit'
            || $data->wireguardIp !== $vm->wireguard_ip || $data->publicSshHost !== $vm->address
            || $node->public_ssh_host !== $vm->address || $data->sshJumpNodeId !== $vm->host_node_id
            || $node->instances()->exists() || $node->roles()->where('role', '!=', RoleName::AppDev)->exists()) {
            throw $this->forbidden();
        }
        $prepared = $this->children->requireSucceeded($this->parent(), 'prepare-enrollment');
        if ($prepared->kind !== 'task_vm.prepare_enrollment' || $prepared->target_type !== 'task_vm' || $prepared->target_id !== $vm->id
            || ($prepared->result['ready'] ?? null) !== true || ($prepared->result['node_id'] ?? null) !== $node->id
            || ($prepared->result['enrolled'] ?? null) !== false) {
            throw $this->forbidden();
        }
    }

    public function removal(Node $node, bool $deleted): void
    {
        $vm = $this->owner($node, 'node.remove', ['task_vm.destroy']);
        if (! $deleted) {
            return;
        }
        $deletion = $this->children->requireSucceeded($this->parent(), 'provider-delete');
        if ($deletion->kind !== 'task_vm.'.$vm->provider->value.'_delete' || $deletion->target_type !== 'task_vm'
            || $deletion->target_id !== $vm->id || ($deletion->result['ready'] ?? null) !== true) {
            throw $this->forbidden();
        }
    }

    /** @param list<string> $parentKinds */
    private function owner(Node $node, string $kind, array $parentKinds): TaskVm
    {
        $parent = $this->parent();
        $intent = $this->idempotency->childIntent;
        if (! in_array($parent->kind, $parentKinds, true) || $parent->target_type !== 'task_vm'
            || $this->idempotency->childKey !== ($kind === 'node.add' ? 'node-enrollment' : 'node-removal')
            || ! is_array($intent) || $intent['kind'] !== $kind || $intent['node_id'] !== $node->id) {
            throw $this->forbidden();
        }
        $vm = TaskVm::query()->lockForUpdate()->find($parent->target_id);
        if ($vm === null || $vm->node_id !== $node->id || $vm->name !== $node->name || $vm->wireguard_ip !== $node->wireguard_ip
            || TaskVm::query()->where('node_id', $node->id)->whereKeyNot($vm->id)->exists()) {
            throw $this->forbidden();
        }

        return $vm;
    }

    private function parent(): Operation
    {
        return $this->idempotency->parent ?? throw $this->forbidden();
    }

    private function forbidden(): ResourceOperationException
    {
        return new ResourceOperationException('operation.child_forbidden', 'Node lifecycle admission requires its owning task VM and proven machine state.', 422);
    }
}
