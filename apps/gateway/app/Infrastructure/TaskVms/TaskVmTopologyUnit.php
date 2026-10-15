<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskTopology;
use App\Domain\Tasks\TaskWait;
use App\Domain\TaskVms\TaskVmTopologyRun;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;
use JsonException;

/**
 * The SSH transport of `resources/task-vms/topology.sh` in a task VM: it starts a preparation unit and reads its state.
 * It decides nothing; {@see TaskVmTopologyAdmission} does.
 */
final readonly class TaskVmTopologyUnit
{
    private const string Script = 'topology.sh';

    public function __construct(private DevelopmentSshExecutor $ssh, private VpnSettings $vpn) {}

    /**
     * Starts the unit `$run` that prepares `$topology` as `$issue` in `$checkout`.
     *
     * @throws RuntimeConvergenceException when the call failed, which can happen after the unit started
     */
    public function start(Node $node, string $run, string $checkout, string $issue, TaskTopology $topology, string $pool): void
    {
        $script = TaskVmScripts::read(self::Script);
        $imports = TaskVmRoleImages::Directory.'/manifest.json';
        $this->run($node, new RemoteCommand(
            ['bash', '-c', $script, 'topology', 'start', $run, $checkout, $issue, implode(',', $topology->toArray()), $pool, $this->vpn->subnet(), $imports],
            input: $script,
            maxOutputBytes: 16384,
            timeout: 60,
        ));
    }

    /** @throws TaskCapacityException when the state cannot be read */
    public function status(Node $node, string $run): TaskVmTopologyRun
    {
        try {
            $result = $this->run($node, new RemoteCommand(
                ['bash', '-c', TaskVmScripts::read(self::Script), 'topology', 'status', $run],
                maxOutputBytes: 65536,
                timeout: 30,
            ));
            $lines = preg_split('/\R/', trim($result->stdout)) ?: [];
            $status = json_decode((string) end($lines), true, 16, JSON_THROW_ON_ERROR);
        } catch (RuntimeConvergenceException|JsonException $exception) {
            throw new TaskCapacityException(TaskWait::Topology, 'Task VM: the topology preparation could not be read: '.$exception->getMessage());
        }

        return TaskVmTopologyRun::fromStatus(is_array($status) ? $status : []);
    }

    private function run(Node $node, RemoteCommand $command): CommandResult
    {
        return $this->ssh->execute($node, $command, 'task-topology', 'task_vm.topology_failed', $command->timeout, 'Task VM topology');
    }
}
