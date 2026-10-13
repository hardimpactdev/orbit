<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Domain\Gateway\GatewayQueue;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\TaskVms\IncusTaskVmImageBuilder;
use App\Infrastructure\TaskVms\TaskVmImageBuildChain;
use App\Models\Node;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Dispatches the nightly chain. The operator creates the first image. Sunday resets to stock. */
final class RefreshTaskVmBaseImages implements ShouldBeUnique, ShouldQueue
{
    use TaskVmJob;

    public int $tries = 1;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 3600;

    public function __construct()
    {
        $this->onTaskVmQueue(GatewayQueue::Builds);
    }

    public function handle(TaskVmSettings $settings, IncusTaskVmImageBuilder $builder, TaskVmImageBuildChain $chain): void
    {
        $host = $settings->sourceHost();
        if ($host === null) {
            return;
        }
        $node = Node::query()->whereKey($host->nodeId)->where('status', LifecycleStatus::Active)->first()
            ?? throw new TaskVmException('task_vm.unknown_host', "The first task VM host, Node [{$host->nodeId}], is not an active Node.");
        if ($builder->exists($host, $node)) {
            $chain->start($node, now()->isSunday());
        }
    }
}
