<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Domain\Gateway\GatewayQueue;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Reconciles the deployed recipe; unchanged identities never dispatch another build. */
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

    public function handle(RecipeImageBuilds $builds): void
    {
        $builds->reconcile();
    }
}
