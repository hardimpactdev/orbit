<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\VmObservation;
use App\Models\TaskVm;
use Illuminate\Support\Sleep;

/** Gateway boot and reboot orchestration, using typed provider observations. */
final readonly class TaskVmGuest
{
    /** A guest reboot can briefly report stopped; read once more after this wait. */
    private const int RebootSeconds = 5;

    public function __construct(private TaskVmProvider $provider) {}

    public function bootstrapReady(TaskVm $vm): bool
    {
        return $this->provider->bootstrapReady($vm);
    }

    /** What the provider sees of the VM. A stopped VM gets a second reading, because a guest reboot shows it stopped. */
    public function observeSettled(TaskVm $vm): ?VmObservation
    {
        $observation = $this->provider->observe($vm);
        if ($observation?->running === false) {
            Sleep::for(self::RebootSeconds)->seconds();
            $observation = $this->provider->observe($vm);
        }

        return $observation;
    }
}
