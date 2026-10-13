<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskCompute;
use App\Domain\Tasks\TaskTopology;
use App\Domain\Tasks\TaskTopologyAdmission;
use App\Domain\Tasks\TaskTopologyEvidence;
use App\Domain\Tasks\TaskTopologyFailedException;
use App\Domain\Tasks\TaskWait;
use App\Domain\TaskVms\TaskVmTopologyRun;
use App\Domain\TaskVms\TaskVmTopologyRunState;
use App\Domain\TaskVms\TaskVmTopologyState;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskVm;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * Prepares a group's declared topology nested in its task VM, before a subtask that declares one runs its baseline
 * check or any turn. The first such subtask starts a preparation unit ({@see TaskVmTopologyUnit}), which runs the host
 * setup, builds the role images the VM lacks, and acquires `TASK-<group>` with the union of the group's declarations.
 * Each tick reads the unit's state until it is ready. A ready topology that holds the subtask's declaration admits it
 * at once, from the row. A failed or lost unit asks for assistance and forgets the run, so a resolution starts it
 * again. Evidence comes from {@see TaskVmTopologyEvidenceReader}.
 */
final readonly class TaskVmTopologyAdmission implements TaskTopologyAdmission
{
    public const string SharedGroup = 'Declared topology runs only in a task VM group.';

    public const string TooSmall = 'Task VM: this subtask\'s topology needs a larger task VM than the group got, and Orbit does not resize it.';

    public const string NoWorkspace = 'Task VM: the group has no task VM workspace for its topology yet.';

    private const int ReasonLimit = 2000;

    public function __construct(private TaskVmTopologyUnit $unit, private TaskVmTopologyEvidenceReader $evidence) {}

    public function prepare(Task $group, Task $task): void
    {
        if ($task->topology->isEmpty()) {
            return;
        }
        if ($group->task_compute !== TaskCompute::Vm) {
            throw new TaskCapacityException(TaskWait::Topology, self::SharedGroup);
        }
        $vm = TaskVm::forGroup($group);
        $node = $vm?->node;
        $instance = $group->taskable;
        if (! $vm instanceof TaskVm || ! $node instanceof Node || ! $instance instanceof Instance) {
            throw new TaskCapacityException(TaskWait::Topology, self::NoWorkspace);
        }
        $topology = TaskTopology::forGroup($group);
        if (! $vm->size->holds($topology)) {
            throw new TaskCapacityException(TaskWait::Topology, self::TooSmall);
        }

        if ($vm->topology_state === TaskVmTopologyState::Preparing) {
            $run = $this->unit->status($node, (string) $vm->topology_run);
            match ($run->state) {
                TaskVmTopologyRunState::Running => throw new TaskCapacityException(TaskWait::Topology, self::preparing($vm->topology)),
                TaskVmTopologyRunState::Ready => $vm->update(['topology_state' => TaskVmTopologyState::Ready, 'topology_error' => null]),
                default => $this->fail($vm, $run),
            };
        }
        if ($vm->topology_state === TaskVmTopologyState::Ready && $vm->topology->covers($topology)) {
            // The VM can restart without a park, and its nested guests stop with it. A run from an earlier boot is
            // stale, so the topology is prepared again; images and host setup stay.
            if ($this->unit->status($node, (string) $vm->topology_run)->state === TaskVmTopologyRunState::Ready) {
                return;
            }
            Log::warning('The task VM restarted since its topology was ready; preparing it again.', ['task_vm' => $vm->name]);
        }
        $this->start($vm, $node, $instance, $group, $topology);

        throw new TaskCapacityException(TaskWait::Topology, self::preparing($topology));
    }

    public function evidence(Task $group, CarbonInterface $since): TaskTopologyEvidence
    {
        $vm = TaskVm::forGroup($group);
        $node = $vm?->node;
        $instance = $group->taskable;

        return $node instanceof Node && $instance instanceof Instance
            ? $this->evidence->read($node, $instance, $since)
            : TaskTopologyEvidence::none();
    }

    private static function preparing(TaskTopology $topology): string
    {
        return 'Task VM: preparing the topology '.implode(', ', $topology->toArray()).'.';
    }

    /** Records the run first, so a tick that dies after the SSH call finds a run to read, never a second unit. */
    private function start(TaskVm $vm, Node $node, Instance $instance, Task $group, TaskTopology $topology): void
    {
        $run = bin2hex(random_bytes(8));
        $vm->update([
            'topology' => $topology,
            'topology_state' => TaskVmTopologyState::Preparing,
            'topology_run' => $run,
            'topology_started_at' => now(),
            'topology_error' => null,
        ]);
        try {
            $this->unit->start($node, $run, (string) $instance->checkout_path, 'TASK-'.$group->id, $topology, (string) $vm->size->resources()->topologyPool);
        } catch (RuntimeConvergenceException $exception) {
            // The call can fail after systemd-run started the unit, for example when SSH timed out. A unit that runs
            // or finished is the run; only a run without a unit fails.
            try {
                $state = $this->unit->status($node, $run)->state;
            } catch (TaskCapacityException) {
                $state = null;
            }
            if (! in_array($state, [TaskVmTopologyRunState::Running, TaskVmTopologyRunState::Ready, TaskVmTopologyRunState::Failed], true)) {
                $this->fail($vm, new TaskVmTopologyRun(TaskVmTopologyRunState::Failed, 'start', $this->output($exception->result) ?: $exception->getMessage()));
            }
        }
    }

    private function fail(TaskVm $vm, TaskVmTopologyRun $run): never
    {
        $reason = match (true) {
            $run->state === TaskVmTopologyRunState::Lost => 'The topology preparation stopped without a result.',
            $run->step !== null => "Preparing the topology failed at step {$run->step}.",
            default => 'Preparing the topology failed.',
        };
        if ($run->log !== '') {
            $reason .= ' The end of its log: '.mb_substr(new CommandActivityInputSanitizer()->redactText($run->log), -self::ReasonLimit);
        }
        $vm->update(['topology_state' => null, 'topology_run' => null, 'topology_error' => $reason]);

        throw new TaskTopologyFailedException($reason);
    }

    private function output(?CommandResult $result): string
    {
        return $result instanceof CommandResult ? trim($result->stdout."\n".$result->stderr) : '';
    }
}
