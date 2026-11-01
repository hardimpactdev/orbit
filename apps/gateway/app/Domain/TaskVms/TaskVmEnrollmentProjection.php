<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Actions\Nodes\RecordNodeSshHostKeyAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Ssh\HostKey;
use App\Models\Node;
use App\Models\Process;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;

/** Projects native enrollment results. Swamp owns execution and readiness decisions. */
final readonly class TaskVmEnrollmentProjection
{
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
        if (($result['ready'] ?? null) !== true || ! is_string($node['observer_digest'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $node['observer_digest']) !== 1) {
            throw new ResourceOperationException('operation.invalid_result', 'Enrollment requires its complete runtime and observer result.', 422);
        }
        $guest = Node::query()->findOrFail($nodeId);
        app(RecordNodeSshHostKeyAction::class)->execute($guest, (string) $vm->wireguard_ip, new HostKey(
            ValidatedData::string($node['ssh_host_key_type'] ?? null),
            ValidatedData::string($node['ssh_host_key'] ?? null),
            ValidatedData::string($node['ssh_host_fingerprint'] ?? null),
        ));
        Node::query()->findOrFail($nodeId)->forceFill([
            'agent_secret_hash' => $node['observer_digest'],
            ...Arr::only($node, ['wireguard_public_key', 'architecture']),
            ...isset($node['address']) ? ['public_ssh_host' => $node['address']] : [],
            'status' => LifecycleStatus::Active, 'failed_step' => null, 'error_code' => null,
        ])->save();
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
