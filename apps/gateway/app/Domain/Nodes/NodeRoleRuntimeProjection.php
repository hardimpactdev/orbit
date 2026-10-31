<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Data\Processes\ProcessData;
use App\Domain\Analytics\PlausibleProcess;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessRuntimeStatusIndex;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Process;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Record confirmed runtime completion immediately; later role publication can fail independently. */
final readonly class NodeRoleRuntimeProjection
{
    public function __construct(private ProcessRuntimeStatusIndex $statuses, private RecordEventBroadcaster $broadcaster) {}

    /** Called only after the owning native runtime method confirms success.
     * The Docker specification remains the admitted installation spec and existing Process definition.
     *
     * @param  array<string,mixed>  $snapshot
     */
    public function record(array $snapshot): void
    {
        if (($snapshot['role'] ?? null) !== RoleName::Analytics->value) {
            return;
        }
        $runtime = ValidatedData::object($snapshot['runtime']);
        $installation = ValidatedData::object($runtime['installation']);
        $nodeId = ValidatedData::integer($snapshot['node_id']);
        if (($runtime['node_id'] ?? null) !== $nodeId || ($installation['runtime'] ?? null) !== ProcessRuntime::Docker->value) {
            throw new ResourceOperationException('operation.execution_forbidden', 'Analytics runtime completion belongs to its admitted Node Process.', 422);
        }
        DB::transaction(function () use ($installation, $nodeId): void {
            $process = Process::query()->whereKey(ValidatedData::integer($installation['process_id']))
                ->where('owner_type', Node::class)->where('owner_id', $nodeId)->where('name', PlausibleProcess::NAME)
                ->where('runtime', ProcessRuntime::Docker)->lockForUpdate()->firstOrFail();
            $process->forceFill(['status' => LifecycleStatus::Active, 'desired_state' => DesiredProcessState::Running,
                'failed_step' => null, 'error_code' => null, 'retired_runtime' => null]);
            if (! $process->isDirty()) {
                return;
            }
            $process->save();
            $this->statuses->remember($process, 'running');
            $this->broadcaster->broadcast(RecordEventType::ProcessStatus, $process->id,
                ProcessData::fromModel($process, 'running')->toArray());
        });
    }
}
