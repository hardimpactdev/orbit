<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskCheckException;
use App\Domain\Tasks\TaskCompute;
use App\Domain\Tasks\TaskTopology;
use App\Domain\Tasks\TaskTopologyAdmission;
use App\Domain\Tasks\TaskTopologyEvidence;
use App\Domain\Tasks\TaskTopologyFailedException;
use App\Domain\Tasks\TaskWait;
use App\Domain\TaskVms\TaskVmTopologyState;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskVm;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use JsonException;

/**
 * Prepares a group's declared topology nested in its task VM, before a subtask that declares one runs its baseline
 * check or any turn. The first such subtask starts `resources/task-vms/topology.sh` in the VM as a systemd unit, which
 * runs the host setup, builds the role images the VM lacks, and acquires `TASK-<group>` with the union of the group's
 * declarations. Each tick reads the unit's state over SSH until it is ready. A ready topology that holds the subtask's
 * declaration admits it at once, from the row. A failed or lost unit asks for assistance and forgets the run, so a
 * resolution starts it again.
 */
final readonly class TaskVmTopologyAdmission implements TaskTopologyAdmission
{
    public const string SharedGroup = 'Declared topology runs only in a task VM group.';

    public const string TooSmall = 'Task VM: this subtask\'s topology needs a larger task VM than the group got, and Orbit does not resize it.';

    public const string NoWorkspace = 'Task VM: the group has no task VM workspace for its topology yet.';

    private const string Script = 'task-vms/topology.sh';

    private const int ReasonLimit = 2000;

    public function __construct(private DevelopmentSshExecutor $ssh, private VpnSettings $vpn) {}

    public function prepare(Task $group, Task $task): void
    {
        if (TaskTopology::from($task->topology ?? []) === []) {
            return;
        }
        if ($group->task_compute !== TaskCompute::Vm) {
            throw new TaskCapacityException(TaskWait::Topology, self::SharedGroup);
        }
        $vm = TaskVm::forGroup($group);
        $instance = $group->taskable;
        if (! $vm instanceof TaskVm || ! $vm->node instanceof Node || ! $instance instanceof Instance) {
            throw new TaskCapacityException(TaskWait::Topology, self::NoWorkspace);
        }
        $topology = TaskTopology::forGroup($group);
        if (! $vm->size->holds($topology)) {
            throw new TaskCapacityException(TaskWait::Topology, self::TooSmall);
        }

        if ($vm->topology_state === TaskVmTopologyState::Preparing) {
            $status = $this->status($vm);
            match ($status['state'] ?? null) {
                'running' => throw new TaskCapacityException(TaskWait::Topology, self::preparing($vm->topology ?? [])),
                'ready' => $vm->update(['topology_state' => TaskVmTopologyState::Ready, 'topology_error' => null]),
                default => $this->fail($vm, $status),
            };
        }
        if ($vm->topology_state === TaskVmTopologyState::Ready && TaskTopology::covers($vm->topology ?? [], $topology)) {
            // The VM can restart without a park, and its nested guests stop with it. A run from an earlier boot is
            // stale, so the topology is prepared again; images and host setup stay.
            if (($this->status($vm)['state'] ?? null) === 'ready') {
                return;
            }
            Log::warning('The task VM restarted since its topology was ready; preparing it again.', ['task_vm' => $vm->name]);
        }
        $this->start($vm, $vm->node, $instance, $group, $topology);

        throw new TaskCapacityException(TaskWait::Topology, self::preparing($topology));
    }

    public function evidence(Task $group, CarbonInterface $since): TaskTopologyEvidence
    {
        $vm = TaskVm::forGroup($group);
        $instance = $group->taskable;
        if (! $vm instanceof TaskVm || ! $vm->node instanceof Node || ! $instance instanceof Instance) {
            return TaskTopologyEvidence::parse('', $since, now());
        }
        try {
            // The newest MiB: a record cut at its start no longer has the shape that counts.
            $result = $this->run($vm->node, new RemoteCommand(
                ['bash', '-c', 'log="$1/.e2e/evidence.log"; [ -f "$log" ] || exit 0; tail -c 1048576 -- "$log"', 'evidence', (string) $instance->checkout_path],
                maxOutputBytes: 1100000,
                timeout: 30,
            ));
        } catch (RuntimeConvergenceException $exception) {
            throw new TaskCheckException('Orbit could not read the topology evidence log: '.$exception->getMessage(), previous: $exception);
        }

        return TaskTopologyEvidence::parse($result->stdout, $since, now());
    }

    /** @param list<string> $topology */
    private static function preparing(array $topology): string
    {
        return 'Task VM: preparing the topology '.implode(', ', $topology).'.';
    }

    /**
     * Records the run first, so a tick that dies after the SSH call finds a run to read, never a second unit.
     *
     * @param  list<string>  $topology
     */
    private function start(TaskVm $vm, Node $node, Instance $instance, Task $group, array $topology): void
    {
        $run = bin2hex(random_bytes(8));
        $vm->update([
            'topology' => $topology,
            'topology_state' => TaskVmTopologyState::Preparing,
            'topology_run' => $run,
            'topology_started_at' => now(),
            'topology_error' => null,
        ]);
        $script = self::script();
        try {
            $this->run($node, new RemoteCommand(
                ['bash', '-c', $script, 'topology', 'start', $run, (string) $instance->checkout_path, 'TASK-'.$group->id, implode(',', $topology), $vm->size->topologyPoolSize(), $this->vpn->subnet()],
                input: $script,
                maxOutputBytes: 16384,
                timeout: 60,
            ));
        } catch (RuntimeConvergenceException $exception) {
            // The call can fail after systemd-run started the unit, for example when SSH timed out. A unit that runs
            // or finished is the run; only a run without a unit fails.
            try {
                $state = $this->status($vm)['state'] ?? null;
            } catch (TaskCapacityException) {
                $state = null;
            }
            if (! in_array($state, ['running', 'ready', 'failed'], true)) {
                $this->fail($vm, ['step' => 'start', 'log' => $this->output($exception->result) ?: $exception->getMessage()]);
            }
        }
    }

    /** @return array<string, mixed> */
    private function status(TaskVm $vm): array
    {
        try {
            $result = $this->run($vm->node ?? throw new TaskCapacityException(TaskWait::Topology, self::NoWorkspace), new RemoteCommand(
                ['bash', '-c', self::script(), 'topology', 'status', (string) $vm->topology_run],
                maxOutputBytes: 65536,
                timeout: 30,
            ));
            $lines = preg_split('/\R/', trim($result->stdout)) ?: [];
            $status = json_decode((string) end($lines), true, 16, JSON_THROW_ON_ERROR);
        } catch (RuntimeConvergenceException|JsonException $exception) {
            throw new TaskCapacityException(TaskWait::Topology, 'Task VM: the topology preparation could not be read: '.$exception->getMessage());
        }

        return is_array($status) ? $status : [];
    }

    /** @param array<string, mixed> $status */
    private function fail(TaskVm $vm, array $status): never
    {
        $step = is_string($status['step'] ?? null) ? $status['step'] : null;
        $log = is_string($status['log'] ?? null) ? trim($status['log']) : '';
        $reason = match (true) {
            ($status['state'] ?? null) === 'lost' => 'The topology preparation stopped without a result.',
            $step !== null => "Preparing the topology failed at step {$step}.",
            default => 'Preparing the topology failed.',
        };
        if ($log !== '') {
            $reason .= ' The end of its log: '.mb_substr(new CommandActivityInputSanitizer()->redactText($log), -self::ReasonLimit);
        }
        $vm->update(['topology_state' => null, 'topology_run' => null, 'topology_error' => $reason]);

        throw new TaskTopologyFailedException($reason);
    }

    private function run(Node $node, RemoteCommand $command): CommandResult
    {
        return $this->ssh->execute($node, $command, 'task-topology', 'task_vm.topology_failed', $command->timeout, 'Task VM topology');
    }

    private function output(?CommandResult $result): string
    {
        return $result instanceof CommandResult ? trim($result->stdout."\n".$result->stderr) : '';
    }

    private static function script(): string
    {
        return (string) file_get_contents(resource_path(self::Script));
    }
}
