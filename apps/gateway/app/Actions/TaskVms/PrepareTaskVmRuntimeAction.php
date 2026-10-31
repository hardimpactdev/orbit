<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

/** Runtime preparation records one intent; its native result owns readiness. */
final readonly class PrepareTaskVmRuntimeAction
{
    public function __construct(private StartTaskVmRuntimeOperationAction $runtime) {}

    public function execute(int $taskVmId): void
    {
        $this->runtime->execute($taskVmId);
    }
}
