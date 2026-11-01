<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Actions\TaskVms\StartTaskVmDestroyAction;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Drains already queued destruction requests into Swamp intent; never executes or waits. */
final class DestroyTaskVm implements ShouldBeUnique, ShouldQueue
{
    use TaskVmRowJob;

    public function handle(StartTaskVmDestroyAction $operations): void
    {
        $operations->execute($this->taskVmId);
    }
}
