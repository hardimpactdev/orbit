<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Actions\Nodes\RecordNodeSshHostKeyAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\NativeOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Swamp\NativeRunResult;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;

/**
 * Projects the terminal run of a slot enrolment. A run whose end check passed makes the slot `free`: its Node is `Active`
 * with the keys the slot VM reported, and a task may claim it. A run that failed makes the slot `failed`, so no claim
 * can take it; the keys and the hub peer stay for the rerun. The facts come from the proof VM's own report, which the
 * workflow captures; the Gateway holds no private key.
 */
final readonly class TaskVmSlotEnrollProjection implements DirectOperationProjection, NativeOperationProjection
{
    /** The end check: a native assert over the volume, the hub peer, the proof VM and its cleanup. */
    public const string EndCheck = 'Check the slot';

    /** The proof VM check prints one JSON line with the identity it came up with. */
    public const string Report = 'Check the slot VM';

    /** @return array<string,array{job:string,step:string}> */
    public static function captures(): array
    {
        return ['slot' => ['job' => 'vm', 'step' => self::Report]];
    }

    public function native(Operation $operation): bool
    {
        return true;
    }

    public function input(Operation $operation): array
    {
        $input = $operation->input;
        if ($operation->kind !== 'task_vm.slot_enroll' || $operation->target_type !== 'task_vm' || ($input['task_vm_id'] ?? null) !== $operation->target_id
            || ($input['node_id'] ?? null) !== $operation->node_id || ! is_int($input['slot'] ?? null)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'A slot enrolment needs its admitted slot and host.', 422);
        }

        return TaskVmProviderWorkflowInput::envelope($input);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $this->input($operation);
        if ($data['status'] === OperationStatus::Interrupted->value) {
            $data['status'] = OperationStatus::Failed->value;
        }
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $vm = TaskVm::query()->findOrFail($operation->target_id);
        $result = [];
        if ($status === OperationStatus::Succeeded) {
            $result = ValidatedData::object($data['result'] ?? []);
            if (NativeRunResult::stepStatus($result, self::EndCheck) !== 'succeeded') {
                throw new ResourceOperationException('operation.result_invalid', 'A succeeded slot enrolment needs its passed end check.', 422);
            }
            $report = $this->report($operation, $result);
            DB::transaction(fn () => $this->free($vm, $report));
            $result = ['ready' => true, 'task_vm_id' => $vm->id, 'name' => $vm->name, 'identity_revision' => $report['revision']];
        } elseif ($status->terminal()) {
            TaskVm::query()->whereKey($vm->id)->where('state', TaskVmState::Provisioning)->update(['state' => TaskVmState::Failed,
                'error_code' => is_string($data['error_code'] ?? null) ? $data['error_code'] : 'task_vm.slot_enroll_failed',
                'error_message' => 'The slot enrolment failed; run it again after fixing the cause.']);
        }

        return app(DirectOperation::class)->project($operation, $data, $result);
    }

    /**
     * What the proof VM printed, with the revision that the end check proved the volume holds (the VM cannot read it:
     * the Incus agent mounts the volume after ssh starts).
     *
     * @param  array<string,mixed>  $result
     * @return array{wireguard_public_key:string,ssh_host_key:string,observer_digest:string,revision:string}
     */
    private function report(Operation $operation, array $result): array
    {
        $slot = ValidatedData::object($result['slot'] ?? []);
        try {
            $report = ValidatedData::object(json_decode(ValidatedData::string($slot['stdout'] ?? null), true, flags: JSON_THROW_ON_ERROR));
        } catch (JsonException|InvalidArgumentException) {
            throw $this->invalid();
        }
        $identity = ValidatedData::object($operation->input['identity'] ?? []);
        $key = $report['wireguard_public_key'] ?? null;
        $host = $report['ssh_host_key'] ?? null;
        $digest = $report['observer_digest'] ?? null;
        $revision = $identity['revision'] ?? null;
        if (! is_string($key) || preg_match('/\A[A-Za-z0-9+\/]{43}=\z/D', $key) !== 1
            || ! is_string($host) || preg_match('/\Assh-ed25519 [A-Za-z0-9+\/]+={0,2}\z/D', $host) !== 1
            || ! is_string($digest) || preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
            || ! is_string($revision) || preg_match('/\A[a-f0-9]{64}\z/D', $revision) !== 1) {
            throw $this->invalid();
        }

        return ['wireguard_public_key' => $key, 'ssh_host_key' => $host, 'observer_digest' => $digest, 'revision' => $revision];
    }

    /** @param array{wireguard_public_key:string,ssh_host_key:string,observer_digest:string,revision:string} $report */
    private function free(TaskVm $vm, array $report): void
    {
        $vm->refresh();
        if ($vm->state === TaskVmState::Free && $vm->identity_revision === $report['revision']) {
            return;
        }
        // A slot that is no longer enrolling (retired, or failed by an earlier verdict) must not report a ready Operation.
        if ($vm->state !== TaskVmState::Provisioning || $vm->slot === null || ! $vm->node instanceof Node) {
            throw new ResourceOperationException('task_vm.slot_not_enrolling', 'The slot is no longer enrolling, so the run cannot make it free.', 409);
        }
        [$type, $key] = explode(' ', $report['ssh_host_key'], 2);
        $guest = $vm->node;
        app(RecordNodeSshHostKeyAction::class)->execute($guest, (string) $vm->wireguard_ip, new HostKey($type, $key,
            'SHA256:'.rtrim(base64_encode(hash('sha256', (string) base64_decode($key, true), true)), '=')));
        $guest->forceFill(['agent_secret_hash' => $report['observer_digest'], 'wireguard_public_key' => $report['wireguard_public_key'],
            'public_ssh_host' => $vm->bridge_address ?? $guest->public_ssh_host, 'status' => LifecycleStatus::Active, 'failed_step' => null, 'error_code' => null])->save();
        $guest->roles()->where('role', RoleName::AppDev)->update(['status' => LifecycleStatus::Active]);
        // The Process is recorded as installed; it runs only while a task holds the slot.
        Process::query()->where('owner_type', Node::class)->where('owner_id', $guest->id)->where('name', 'pi-server')->update([
            'status' => LifecycleStatus::Active, 'failed_step' => null, 'error_code' => null]);
        $vm->update(['state' => TaskVmState::Free, 'identity_revision' => $report['revision'], 'ready_at' => null, 'error_code' => null, 'error_message' => null]);
    }

    private function invalid(): ResourceOperationException
    {
        return new ResourceOperationException('operation.result_invalid', 'A slot enrolment needs the identity report of its proof VM.', 422);
    }
}
