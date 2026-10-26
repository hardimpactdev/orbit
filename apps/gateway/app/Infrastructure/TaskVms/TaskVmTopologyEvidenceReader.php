<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Tasks\TaskCheckException;
use App\Domain\Tasks\TaskTopologyEvidence;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentStep;
use App\Models\Instance;
use App\Models\Node;
use Carbon\CarbonInterface;

/**
 * Applies Gateway review rules to the bounded evidence bytes read by the agent.
 */
final readonly class TaskVmTopologyEvidenceReader
{
    public function __construct(private AgentCommandRunner $agents) {}

    /** @throws TaskCheckException when the evidence cannot be read */
    public function read(Node $node, Instance $instance, CarbonInterface $since): TaskTopologyEvidence
    {
        try {
            $result = AgentStep::run($this->agents, $node, 'task-vms.read-topology-evidence', [
                'checkout' => (string) $instance->checkout_path,
            ], 'task-topology', 'task_vm.topology_failed', 'Task VM topology', 30);
        } catch (RuntimeConvergenceException $exception) {
            throw new TaskCheckException('Orbit could not read the topology evidence log: '.$exception->getMessage(), previous: $exception);
        }
        $encoded = $result['content_base64'] ?? null;
        if (! is_string($encoded)) {
            return TaskTopologyEvidence::none();
        }
        $content = base64_decode($encoded, true);
        if ($content === false) {
            throw new TaskCheckException('Orbit could not decode the topology evidence log.');
        }

        return TaskTopologyEvidence::fromJsonLines($content, $since, now());
    }
}
