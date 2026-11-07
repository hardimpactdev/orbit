<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Actions\Nodes\RecordNodeSshHostKeyAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationStartRefusal;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Ssh\HostKey;
use App\Jobs\TaskVms\RefillTaskVmSlots;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * Projects the end of one slot enrol (docs/cli/tasks/vm-slot-enrol.mmd). An identity whose hub peer is in place is `parked`, free for a
 * task start: its Node is active with the keys the Gateway made for it. One that did not pass is `failed`, so no task start takes it; the
 * rule that runs after every enrol enrols it again.
 */
final readonly class TaskVmSlotEnrolProjection implements DirectOperationProjection, DirectOperationStartRefusal
{
    /** The end check: the identity's peer is on the hub. */
    public const string EndCheck = 'Add the hub peer';

    /** @return array<string,mixed> */
    public function input(Operation $operation): array
    {
        $input = ValidatedData::object($operation->input);

        return ['intent' => ValidatedData::object($input['intent']), 'execution_secrets' => []];
    }

    /** @param array<string,mixed> $data */
    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($operation->status->terminal()) {
                return app(DirectOperation::class)->project($operation, $data, ValidatedData::object($operation->result ?? []));
            }
            $slot = TaskVm::query()->lockForUpdate()->findOrFail($operation->target_id);
            $passed = ($data['status'] ?? null) === 'succeeded';
            $this->record($slot, $passed, $passed ? null : (ValidatedData::nullableString($data['error_code'] ?? null) ?? 'task_vm.slot_enrol_failed'));
            $result = ['slot' => $slot->name, 'enrolled' => $passed];
            $data['result'] = $result;
            // After a success the rule runs at once; after a failure it waits for its sweep, so a failing enrol does not loop.
            if ($passed) {
                DB::afterCommit(static fn () => RefillTaskVmSlots::dispatchFor((int) $slot->host_node_id));
            }

            return app(DirectOperation::class)->project($operation, $data, $result);
        });
    }

    /** A refused start never ran: the identity is failed, and the slot rule enrols it again at its next sweep. */
    public function refused(Operation $operation): void
    {
        $slot = TaskVm::query()->lockForUpdate()->find($operation->target_id);
        if ($slot instanceof TaskVm) {
            $this->record($slot, false, $operation->error_code ?? 'task_vm.slot_enrol_failed');
        }
    }

    /** The identity after its enrol: free, or failed. */
    public function record(TaskVm $slot, bool $passed, ?string $errorCode): void
    {
        if (! $passed) {
            $slot->update(['state' => TaskVmState::Failed, 'error_code' => $errorCode, 'error_message' => 'The slot enrol did not pass.']);

            return;
        }
        $node = Node::query()->findOrFail($slot->node_id);
        $sshHost = ValidatedData::string($slot->ssh_host_private_key);
        [$type, $key] = explode(' ', TaskVmSlotIdentity::sshPublicKey($sshHost), 2);
        app(RecordNodeSshHostKeyAction::class)->execute($node, (string) $slot->wireguard_ip, new HostKey($type, $key, TaskVmSlotIdentity::sshFingerprint($sshHost)));
        $node->forceFill(['agent_secret_hash' => hash('sha256', ValidatedData::string($slot->agent_secret)),
            'wireguard_public_key' => TaskVmSlotIdentity::wireguardPublicKey(ValidatedData::string($slot->wireguard_private_key)),
            'status' => LifecycleStatus::Active, 'failed_step' => null, 'error_code' => null])->save();
        $node->roles()->where('role', RoleName::AppDev)->update(['status' => LifecycleStatus::Active]);
        Process::query()->where('owner_type', Node::class)->where('owner_id', $node->id)->where('name', 'pi-server')
            ->where('desired_state', DesiredProcessState::Running)->update(['status' => LifecycleStatus::Active, 'failed_step' => null, 'error_code' => null]);
        $slot->update(['state' => TaskVmState::Parked, 'ready_at' => now(), 'error_code' => null, 'error_message' => null]);
    }
}
