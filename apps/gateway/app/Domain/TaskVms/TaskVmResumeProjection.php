<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Actions\Nodes\RecordNodeSshHostKeyAction;
use App\Actions\TaskVms\FailTaskVmAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Ssh\HostKey;
use App\Models\Operation;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Native results change VM metadata only; no provider or guest calls. */
final readonly class TaskVmResumeProjection implements DirectOperationProjection
{
    public function __construct(private TaskVmResumeIntent $intent, private FailTaskVmAction $fail) {}

    public function input(Operation $operation): array
    {
        return $this->intent->input($operation);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $result = ValidatedData::object($data['result'] ?? []);
            $terminal = $operation->status->terminal();
            if (! $terminal && ($data['status'] ?? null) === 'succeeded') {
                $vm = $this->intent->owned($operation, true);
                if (($result['task_vm_id'] ?? null) !== $vm->id || ($result['guest_node_id'] ?? null) !== $vm->node_id
                    || ($result['ssh_ready'] ?? null) !== true
                    || (($operation->input['mode'] ?? null) === 'resume' && (($result['resumed'] ?? null) !== true || ($result['ready'] ?? null) !== true))) {
                    throw new ResourceOperationException('operation.invalid_result', 'The native resume result does not confirm the admitted VM.', 422);
                }
            }
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if ($terminal) {
                return $projected;
            }
            $vm = TaskVm::query()->lockForUpdate()->find($operation->target_id);
            if ($vm !== null && $vm->state->value === ($operation->input['admitted_state'] ?? null)) {
                if ($projected->status === OperationStatus::Succeeded) {
                    $pin = ValidatedData::object($result['ssh_host_key'] ?? []);
                    $guest = $vm->node()->firstOrFail();
                    app(RecordNodeSshHostKeyAction::class)->execute($guest, (string) $vm->wireguard_ip, new HostKey(
                        ValidatedData::string($pin['ssh_host_key_type'] ?? null),
                        ValidatedData::string($pin['ssh_host_key'] ?? null),
                        ValidatedData::string($pin['ssh_host_fingerprint'] ?? null),
                    ));
                    if ($vm->state === TaskVmState::Ready) {
                        return $projected;
                    }
                    $vm->update(['state' => TaskVmState::Ready, 'ready_at' => now(), 'error_code' => null, 'error_message' => null, ...TaskVm::ForgetTopology]);
                } elseif ($projected->status === OperationStatus::Failed && $vm->state === TaskVmState::Parked) {
                    $this->fail->execute($vm, [$vm->state], ValidatedData::string($data['error_code']), 'The task VM resume could not be confirmed.', 'The task VM resume could not be confirmed.');
                }
            }

            return $projected;
        });
    }
}
