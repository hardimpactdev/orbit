<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskTopology;
use App\Domain\Tasks\TaskWait;
use App\Domain\TaskVms\TaskVmTopologyRun;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\AgentCommands\AgentStep;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Node;

/** Sends topology intent to the agent. TaskVmTopologyAdmission owns admission and recovery. */
final readonly class TaskVmTopologyUnit
{
    public function __construct(private AgentCommandRunner $agents, private VpnSettings $vpn) {}

    /** @throws RuntimeConvergenceException when the call failed, possibly after the unit started */
    public function start(Node $node, string $run, string $checkout, string $issue, TaskTopology $topology): void
    {
        $this->run($node, 'task-vm.start-topology', [
            'run' => $run,
            'checkout' => $checkout,
            'issue' => $issue,
            'nodes' => $topology->toArray(),
            'vpn_subnet' => $this->vpn->subnet(),
        ]);
    }

    /** @throws TaskCapacityException when the state cannot be read */
    public function status(Node $node, string $run): TaskVmTopologyRun
    {
        try {
            $status = $this->run($node, 'task-vm.topology-status', ['run' => $run]);
        } catch (RuntimeConvergenceException $exception) {
            throw new TaskCapacityException(TaskWait::Topology, 'Task VM: the topology preparation could not be read: '.$exception->getMessage());
        }

        return TaskVmTopologyRun::fromStatus($status);
    }

    /**
     * A lost start, including a timeout, must reach admission's status check before a new run can start.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function run(Node $node, string $command, array $input): array
    {
        try {
            return $this->agents->run($node, $command, $input);
        } catch (AgentCommandRefused $refusal) {
            throw AgentStep::failed($node, 'task-topology', 'task_vm.topology_failed', 'Task VM topology', AgentStep::output($refusal), $refusal);
        } catch (AgentRunnerFailure $failure) {
            throw AgentStep::failed($node, 'task-topology', 'task_vm.topology_failed', 'Task VM topology', $failure->result ?? new CommandResult(1, '', $failure->getMessage(), 0, false), $failure);
        }
    }
}
