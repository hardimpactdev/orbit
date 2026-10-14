<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Domain\TaskVms\TaskVmState;
use App\Models\TaskVm;
use Illuminate\Contracts\Queue\ShouldQueue;

/** One-release reader for delayed claim payloads from the previous release. Never dispatched by new code. */
final class RecoverLegacyWarmClaim implements ShouldQueue
{
    use TaskVmRowJob;

    public int $tries = 1;

    public function handle(): void
    {
        $vm = TaskVm::query()->find($this->taskVmId);
        if ($vm?->state === TaskVmState::Provisioning && $vm->group_id !== null && $vm->claimed_at !== null) {
            PrepareTaskVmRuntime::enqueue($vm->id);
        }
    }
}
