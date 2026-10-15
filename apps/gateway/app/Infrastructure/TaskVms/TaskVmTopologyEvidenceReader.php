<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Tasks\TaskCheckException;
use App\Domain\Tasks\TaskTopologyEvidence;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;
use App\Models\Node;
use Carbon\CarbonInterface;

/**
 * Reads the newest MiB of a task VM workspace's topology evidence over SSH from `.e2e/evidence.jsonl`. The first output line names the file read.
 */
final readonly class TaskVmTopologyEvidenceReader
{
    private const string Read = 'dir="$1"; for file in "$2"; do if [ -f "$dir/$file" ]; then echo "$file"; tail -c 1048576 -- "$dir/$file"; exit 0; fi; done';

    public function __construct(private DevelopmentSshExecutor $ssh) {}

    /** @throws TaskCheckException when the evidence cannot be read */
    public function read(Node $node, Instance $instance, CarbonInterface $since): TaskTopologyEvidence
    {
        try {
            $result = $this->ssh->execute($node, new RemoteCommand(
                ['bash', '-c', self::Read, 'evidence', (string) $instance->checkout_path, TaskTopologyEvidence::File],
                maxOutputBytes: 1100000,
                timeout: 30,
            ), 'task-topology', 'task_vm.topology_failed', 30, 'Task VM topology');
        } catch (RuntimeConvergenceException $exception) {
            throw new TaskCheckException('Orbit could not read the topology evidence log: '.$exception->getMessage(), previous: $exception);
        }
        [$file, $content] = explode("\n", $result->stdout, 2) + ['', ''];

        return match ($file) {
            TaskTopologyEvidence::File => TaskTopologyEvidence::fromJsonLines($content, $since, now()),
            default => TaskTopologyEvidence::none(),
        };
    }
}
