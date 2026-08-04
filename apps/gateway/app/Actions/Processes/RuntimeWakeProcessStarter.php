<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Models\Node;
use App\Models\Process;
use App\Services\Processes\ProcessRuntimeDriverRegistry;
use Throwable;

/**
 * Fresh-worker wake start: load scalars, resolve driver, start unit.
 *
 * Intended for Laravel process concurrency workers only. Parent-side process
 * events are recorded by RuntimeHibernation before and after the batch.
 */
final readonly class RuntimeWakeProcessStarter
{
    public function __construct(
        private ProcessRuntimeDriverRegistry $runtimeDrivers,
    ) {}

    public function start(int $nodeId, int $processId, string $runtimeUnit): bool
    {
        try {
            $node = Node::query()->find($nodeId);
            $process = Process::query()->find($processId);

            if (! $node instanceof Node || ! $process instanceof Process) {
                return false;
            }

            return $this->runtimeDrivers
                ->forProcess($process)
                ->start($node, $runtimeUnit);
        } catch (Throwable) {
            return false;
        }
    }
}
