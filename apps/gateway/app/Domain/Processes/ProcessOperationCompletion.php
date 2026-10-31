<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Data\Processes\ProcessData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Operation;
use App\Models\Process;
use App\Support\ValidatedData;

/** Projects proven runtime results; never executes on a Node. */
final readonly class ProcessOperationCompletion implements OperationCompletion
{
    public function __construct(private RecordEventBroadcaster $broadcaster, private ProcessRuntimeStatusIndex $statuses) {}

    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        $process = Process::query()->whereKey($operation->input['process_id'])->firstOrFail();
        if ($outcome->status !== OperationStatus::Succeeded) {
            $process->update(['status' => $operation->kind === 'process.destroy' ? LifecycleStatus::Removing : LifecycleStatus::Failed,
                'error_code' => $outcome->errorCode, 'failed_step' => $operation->steps()->where('status', 'failed')->value('name')]);

            return;
        }
        if ($operation->kind === 'process.destroy') {
            if (! $operation->input['preserve_record']) {
                $process->delete();
                $this->broadcaster->broadcast(RecordEventType::ProcessDeleted, $process->id, ['id' => $process->id, 'name' => $process->name]);
            }

            return;
        }
        $desired = match ($operation->kind) {
            'process.start', 'process.restart' => DesiredProcessState::Running,
            'process.stop' => DesiredProcessState::Stopped,
            default => $process->desired_state,
        };
        $process->forceFill(['status' => LifecycleStatus::Active, 'desired_state' => $desired, 'failed_step' => null, 'error_code' => null,
            'retired_runtime' => null, ...isset($operation->input['content_hash']) ? ['systemd_activation_hash' => $operation->input['content_hash'], 'systemd_activation_pending' => false] : []])->save();
        $this->statuses->remember($process, ValidatedData::string($outcome->result['state'] ?? 'unknown'));
        $this->broadcaster->broadcast(($operation->input['created'] ?? false) ? RecordEventType::ProcessCreated : RecordEventType::ProcessStatus,
            $process->id, ProcessData::fromModel($process, ValidatedData::string($outcome->result['state'] ?? 'unknown'))->toArray());
    }
}
