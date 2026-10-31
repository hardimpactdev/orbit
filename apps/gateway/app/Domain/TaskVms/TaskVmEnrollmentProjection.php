<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationPrivateBindings;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationPublicView;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Projects native enrollment results. Swamp owns execution and readiness decisions. */
final readonly class TaskVmEnrollmentProjection implements DirectOperationProjection, DirectOperationPublicView
{
    public function input(Operation $operation): array
    {
        if (! in_array($operation->kind, ['task_vm.enroll', 'task_vm.prepare_runtime'], true)
            || $operation->target_type !== 'task_vm' || ($operation->input['task_vm_id'] ?? null) !== $operation->target_id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'Enrollment requires its admitted VM intent.', 422);
        }

        return [...Arr::only($operation->input, ['task_vm_id', 'guest_node_id', 'execution_node_id', 'provider', 'name', 'user', 'agent_sha256', 'agent', 'bootstrap', 'node', 'runtime', 'pi_url', 'network', 'gateway_node_id', 'issuer_node_id']), 'wireguard_public_key' => Node::query()->whereKey($operation->input['guest_node_id'])->value('wireguard_public_key')];
    }

    public function assertPublicParticipant(Operation $operation, int $callerNodeId): void
    {
        app(DirectOperationPrivateBindings::class)->assertParticipant($operation, $callerNodeId);
    }

    public function publicInput(Operation $operation, int $callerNodeId): array
    {
        $this->assertPublicParticipant($operation, $callerNodeId);

        return $this->input($operation);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $intent = $this->input($operation);
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $result = ValidatedData::object($data['result'] ?? []);

        return DB::transaction(function () use ($operation, $data, $status, $result, $intent): Operation {
            $terminal = $operation->status->terminal();
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if (! $terminal && $status === OperationStatus::Running) {
                $this->recordPublic(TaskVm::query()->findOrFail($operation->target_id), $intent, $result);
            } elseif (! $terminal && $status === OperationStatus::Succeeded) {
                $this->record(TaskVm::query()->findOrFail($operation->target_id), $intent, $result);
            } elseif (! $terminal && $status === OperationStatus::Failed) {
                TaskVm::query()->whereKey($operation->target_id)->where('state', TaskVmState::Provisioning)->update([
                    'state' => TaskVmState::Failed, 'error_code' => $data['error_code'] ?? 'task_vm.enrollment_failed',
                    'error_message' => 'Native Task VM enrollment failed.',
                ]);
            }

            return $projected;
        });
    }

    /** @param array<string,mixed> $intent
     * @param  array<string,mixed>  $result
     */
    public function recordPublic(TaskVm $vm, array $intent, array $result): void
    {
        $node = ValidatedData::object($result['node'] ?? []);
        $attributes = Arr::only($node, ['ssh_host_key_type', 'ssh_host_key', 'ssh_host_fingerprint', 'wireguard_public_key', 'architecture']);
        if ($attributes !== []) {
            Node::query()->whereKey(ValidatedData::integer($intent['guest_node_id']))->where('name', $vm->name)->update($attributes);
        }
    }

    /** Shared with provision roots. Native result fields are observations, never target selectors.
     * @param  array<string,mixed>  $intent
     * @param  array<string,mixed>  $result
     */
    public function record(TaskVm $vm, array $intent, array $result): void
    {
        $vm->refresh();
        if ($vm->state !== TaskVmState::Provisioning) {
            return;
        }
        $nodeId = ValidatedData::integer($intent['guest_node_id']);
        $runtime = ValidatedData::object($intent['runtime']);
        $process = ValidatedData::object($runtime['process']);
        $node = ValidatedData::object($result['node'] ?? []);
        Node::query()->whereKey($nodeId)->update([
            ...Arr::only($node, ['ssh_host_key_type', 'ssh_host_key', 'ssh_host_fingerprint', 'wireguard_public_key', 'architecture']),
            ...isset($node['address']) ? ['public_ssh_host' => $node['address']] : [],
            'status' => LifecycleStatus::Active, 'failed_step' => null, 'error_code' => null,
        ]);
        Node::query()->findOrFail($nodeId)->roles()->where('role', RoleName::AppDev)->update(['status' => LifecycleStatus::Active]);
        Process::query()->whereKey(ValidatedData::integer($process['process_id']))->where('owner_type', Node::class)->where('owner_id', $nodeId)->update([
            'status' => LifecycleStatus::Active, 'failed_step' => null, 'error_code' => null,
            'systemd_activation_hash' => $process['content_hash'], 'systemd_activation_pending' => false,
        ]);
        TaskVm::query()->whereKey($vm->id)->where('state', TaskVmState::Provisioning)->update([
            'state' => TaskVmState::Ready, 'ready_at' => now(), 'error_code' => null, 'error_message' => null,
            ...isset($node['address']) ? ['address' => $node['address']] : [],
        ]);
    }
}
