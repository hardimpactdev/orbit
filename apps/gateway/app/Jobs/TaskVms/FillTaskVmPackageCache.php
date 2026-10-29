<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Actions\TaskVms\StartTaskVmPackageCacheOperationAction;
use App\Domain\Gateway\GatewayQueue;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class FillTaskVmPackageCache implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use TaskVmJob;

    public int $tries = 1;

    public int $timeout = 1700;

    public int $uniqueFor = 7200;

    public function __construct(public readonly int $hostNodeId)
    {
        $this->onTaskVmQueue(GatewayQueue::Builds);
    }

    public function uniqueId(): string
    {
        return (string) $this->hostNodeId;
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [new WithoutOverlapping('package-cache:'.$this->hostNodeId)->dontRelease()->expireAfter(1760)];
    }

    public function handle(StartTaskVmPackageCacheOperationAction $fill): void
    {
        $fill->execute($this->hostNodeId);
    }
}
