<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Domain\TaskVms\TaskVmSlotPool;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Runs the refill rule of one task VM host's ready slots ({@see TaskVmSlotPool::refill()}) after a change that may need it. */
final class RefillTaskVmSlots implements ShouldBeUnique, ShouldQueue
{
    use TaskVmJob;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 60;

    public function __construct(public int $hostNodeId)
    {
        $this->onTaskVmQueue();
    }

    /** Queues the rule for the host once the current transaction commits; a VM with no host has no pool. */
    public static function dispatchFor(?int $hostNodeId): void
    {
        if ($hostNodeId !== null) {
            self::dispatch($hostNodeId)->afterCommit();
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->hostNodeId;
    }

    public function handle(TaskVmSlotPool $pool): void
    {
        $pool->refill($this->hostNodeId);
    }
}
