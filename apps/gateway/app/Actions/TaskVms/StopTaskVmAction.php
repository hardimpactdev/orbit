<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Domain\TaskVms\TaskVmError;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmProvider;
use App\Models\TaskVm;
use Throwable;

/** Stop and observe under the caller's group execution lock; a parked row alone proves nothing. */
final readonly class StopTaskVmAction
{
    public function __construct(private TaskVmProvider $provider) {}

    public function execute(TaskVm $vm): void
    {
        $power = $this->provider->observe($vm);
        if ($power !== null && ! $power->settled) {
            throw new TaskVmException(TaskVmError::NotSettled, 'The task VM power state is not settled.');
        }
        $failure = null;
        if ($power?->running) {
            try {
                $this->provider->stop($vm);
            } catch (Throwable $exception) {
                $failure = $exception;
            }
            $power = $this->provider->observe($vm);
        }
        if ($power === null) {
            throw new TaskVmException(TaskVmError::VmMissing, 'The task VM is missing; its workspace was not confirmed parked.');
        }
        if (! $power->settled) {
            throw new TaskVmException(TaskVmError::NotSettled, 'The task VM power state is not settled.');
        }
        if ($power->running) {
            throw $failure ?? new TaskVmException(TaskVmError::ParkFailed, 'The task VM is still running after parking.');
        }
        $vm->update(['stop_confirmed_at' => now(), 'error_code' => null, 'error_message' => null]);
    }
}
