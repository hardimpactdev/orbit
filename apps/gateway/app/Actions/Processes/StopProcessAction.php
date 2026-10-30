<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Data\Processes\ProcessData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessOperationAdmission;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntimeLease;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessRuntimeStatusIndex;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

final readonly class StopProcessAction
{
    use MarksProcessRuntimeFailures;

    private ProcessRuntimeLease $lease;

    private RecordEventBroadcaster $broadcaster;

    private ProcessRuntimeStatusIndex $statuses;

    public function __construct(
        private ProcessRuntimeManager $runtime,
        ?ProcessRuntimeLease $lease = null,
        ?RecordEventBroadcaster $broadcaster = null,
        ?ProcessRuntimeStatusIndex $statuses = null,
    ) {
        $this->lease = $lease ?? app(ProcessRuntimeLease::class);
        $this->broadcaster = $broadcaster ?? app(RecordEventBroadcaster::class);
        $this->statuses = $statuses ?? app(ProcessRuntimeStatusIndex::class);
    }

    public function execute(#[SensitiveParameter] Process $process): Process
    {
        return $this->lease->run($process, function (Process $fresh): Process {
            try {
                $this->runtime->stop($fresh);
            } catch (ProcessOperationException $exception) {
                $this->markRuntimeFailed($fresh, $exception);

                throw $exception;
            }

            $fresh->update([
                'desired_state' => DesiredProcessState::Stopped,
                'status' => LifecycleStatus::Active,
                'failed_step' => null,
                'error_code' => null,
            ]);

            $result = $fresh->refresh();
            $status = $this->runtime->status($result);
            $this->statuses->remember($result, $status);

            $this->broadcaster->broadcast(
                RecordEventType::ProcessStatus,
                $result->id,
                ProcessData::fromModel($result, $status)->toArray(),
            );

            return $result;
        });
    }

    public function admit(Process $process, ?Node $caller = null, ?string $requestId = null): Operation
    {
        $admission = app(ProcessOperationAdmission::class);
        if ($admission->direct($process, 'stop')) {
            return $admission->execute($process, 'stop', caller: $caller, requestId: $requestId);
        }

        $admit = fn (): Operation => DB::transaction(fn (): Operation => $this->lease->run($process, function (Process $fresh) use ($caller, $requestId): Operation {
            return app(ProcessOperationAdmission::class)->execute($fresh, 'stop', caller: $caller, requestId: $requestId);
        }));

        return $admit();
    }
}
