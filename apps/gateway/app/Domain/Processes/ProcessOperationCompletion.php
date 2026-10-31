<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Data\Processes\ProcessData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Operation;
use App\Models\Process;
use App\Support\ValidatedData;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Support\Facades\DB;

/** Bounded Process writes from a native outcome; never executes on a Node. */
final readonly class ProcessOperationCompletion
{
    /** @param iterable<ProcessInstallationProjection> $installations */
    public function __construct(private RecordEventBroadcaster $broadcaster, private ProcessRuntimeStatusIndex $statuses,
        #[Tag(ProcessInstallationProjection::class)] private iterable $installations = []) {}

    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        $process = Process::query()->whereKey($operation->input['process_id'])->firstOrFail();
        $this->completeIntent($process, $operation->kind, $operation->input, $outcome, $operation);
    }

    /** The parent supplies its verified terminal outcome; no native run or child Operation is invented.
     * @param  array<string,mixed>  $input  Captured Process update input, including content_hash when systemd.
     */
    public function completeRecordedUpdate(Process $process, array $input, OperationOutcome $outcome): void
    {
        DB::transaction(function () use ($process, $input, $outcome): void {
            $this->completeIntent($process->refresh(), 'process.update', $input, $outcome);
        });
    }

    /** @param array<string,mixed> $input */
    private function completeIntent(Process $process, string $kind, array $input, OperationOutcome $outcome, ?Operation $operation = null): void
    {
        if ($outcome->status !== OperationStatus::Succeeded) {
            $process->update(['status' => $kind === 'process.destroy' ? LifecycleStatus::Removing : LifecycleStatus::Failed,
                'error_code' => $outcome->errorCode, 'failed_step' => null]);
            if ($operation !== null) {
                $this->installed($operation, $process, $outcome);
            }

            return;
        }
        if ($kind === 'process.destroy') {
            if (! $input['preserve_record']) {
                $process->delete();
                $this->broadcaster->broadcast(RecordEventType::ProcessDeleted, $process->id, ['id' => $process->id, 'name' => $process->name]);
            }

            return;
        }
        $desired = match ($kind) {
            'process.start', 'process.restart' => DesiredProcessState::Running,
            'process.stop' => DesiredProcessState::Stopped,
            default => $process->desired_state,
        };
        $attributes = ['status' => LifecycleStatus::Active, 'desired_state' => $desired,
            'failed_step' => null, 'error_code' => null, 'retired_runtime' => null];
        if (isset($input['content_hash'])) {
            $attributes['systemd_activation_hash'] = $input['content_hash'];
            $attributes['systemd_activation_pending'] = false;
        }
        $process->forceFill($attributes)->save();
        if ($operation !== null) {
            $this->installed($operation, $process, $outcome);
        }
        $this->statuses->remember($process, ValidatedData::string($outcome->result['state'] ?? 'unknown'));
        $this->broadcaster->broadcast(($input['created'] ?? false) ? RecordEventType::ProcessCreated : RecordEventType::ProcessStatus,
            $process->id, ProcessData::fromModel($process, ValidatedData::string($outcome->result['state'] ?? 'unknown'))->toArray());
    }

    private function installed(Operation $operation, Process $process, OperationOutcome $outcome): void
    {
        if (in_array($operation->kind, ['process.create', 'process.update'], true)) {
            foreach ($this->installations as $projection) {
                $projection->complete($operation, $process, $outcome);
            }
        }
    }
}
